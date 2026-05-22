<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        $query = MediaAsset::query()->latest();

        $search = request('q');
        $month = request('month');

        if (is_string($search) && $search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('file_name', 'like', "%{$search}%")
                    ->orWhere('alt_text', 'like', "%{$search}%")
                    ->orWhere('mime_type', 'like', "%{$search}%");
            });
        }

        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            [$year, $monthNumber] = explode('-', $month);
            $query
                ->whereYear('created_at', (int) $year)
                ->whereMonth('created_at', (int) $monthNumber);
        }

        return response()->json([
            'data' => [
                'items' => $query->paginate(24),
                'months' => MediaAsset::query()
                    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as value")
                    ->selectRaw("DATE_FORMAT(created_at, '%M %Y') as label")
                    ->selectRaw('COUNT(*) as total')
                    ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%M %Y')")
                    ->orderByRaw("DATE_FORMAT(created_at, '%Y-%m') desc")
                    ->get(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->hasFile('file')) {
            $validated = $request->validate([
                'file' => ['required', 'file', 'image', 'max:10240'],
                'alt_text' => ['nullable', 'string', 'max:255'],
            ]);

            $file = $validated['file'];
            $disk = (string) config('filesystems.media', 'public');
            $directory = 'media/'.now()->format('Y/m');
            $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];
            $stored = $this->storeUploadedImage($file, $disk, $directory, $dimensions);

            $media = MediaAsset::create([
                'file_name' => $file->getClientOriginalName(),
                'alt_text' => $validated['alt_text'] ?? null,
                'url' => $stored['url'],
                'disk' => $disk,
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'metadata' => [
                    'folder' => $directory,
                    'path' => $stored['path'],
                    'original_name' => $file->getClientOriginalName(),
                    'original_mime_type' => $file->getMimeType(),
                    'original_size_bytes' => $file->getSize(),
                    'optimized' => $stored['optimized'],
                ],
            ]);
        } else {
            $media = MediaAsset::create($request->validate([
                'file_name' => ['required', 'string', 'max:255'],
                'alt_text' => ['nullable', 'string', 'max:255'],
                'url' => ['required', 'url'],
                'disk' => ['nullable', 'string', 'max:50'],
                'mime_type' => ['nullable', 'string', 'max:100'],
                'size_bytes' => ['nullable', 'integer'],
                'width' => ['nullable', 'integer'],
                'height' => ['nullable', 'integer'],
                'metadata' => ['nullable', 'array'],
            ]));
        }

        return response()->json([
            'message' => 'Media asset created successfully.',
            'data' => $media,
        ], 201);
    }

    public function update(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $mediaAsset->update([
            'alt_text' => $validated['alt_text'] ?? null,
        ]);

        return response()->json([
            'message' => 'Media asset updated successfully.',
            'data' => $mediaAsset->fresh(),
        ]);
    }

    public function destroy(MediaAsset $mediaAsset): JsonResponse
    {
        $this->deleteStoredMedia($mediaAsset);
        $mediaAsset->delete();

        return response()->json([
            'message' => 'Media asset deleted successfully.',
        ]);
    }

    protected function deleteStoredMedia(MediaAsset $mediaAsset): void
    {
        $disk = $mediaAsset->disk ?: 'public';

        if (! array_key_exists($disk, config('filesystems.disks', []))) {
            return;
        }

        $url = $mediaAsset->getRawOriginal('url');
        $storagePath = Arr::get($mediaAsset->metadata ?? [], 'path');

        if (! is_string($storagePath) || $storagePath === '') {
            if (! is_string($url) || $url === '') {
                return;
            }

            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path) || $path === '') {
                return;
            }

            $storagePath = str_contains($path, '/storage/')
                ? ltrim(substr($path, strpos($path, '/storage/') + 9), '/')
                : ltrim($path, '/');
        }

        if ($storagePath !== '' && Storage::disk($disk)->exists($storagePath)) {
            Storage::disk($disk)->delete($storagePath);
        }
    }

    /**
     * Converts future uploads to right-sized WebP when the server supports GD.
     * If GD/WebP is unavailable, the original upload path is preserved.
     *
     * @param array<int, int|null> $dimensions
     * @return array{path: string, url: string, mime_type: string|null, size_bytes: int|null, width: int|null, height: int|null, optimized: bool}
     */
    protected function storeUploadedImage(UploadedFile $file, string $disk, string $directory, array $dimensions): array
    {
        $optimized = $this->storeOptimizedWebp($file, $disk, $directory);

        if ($optimized !== null) {
            return $optimized;
        }

        $filename = Str::uuid()->toString().'-'.preg_replace('/[^A-Za-z0-9.\-_]/', '-', $file->getClientOriginalName());
        $storedPath = $file->storeAs($directory, $filename, $disk);

        return [
            'path' => $storedPath,
            'url' => Storage::disk($disk)->url($storedPath),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'optimized' => false,
        ];
    }

    /**
     * @return array{path: string, url: string, mime_type: string, size_bytes: int|null, width: int, height: int, optimized: bool}|null
     */
    protected function storeOptimizedWebp(UploadedFile $file, string $disk, string $directory): ?array
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $mimeType = (string) $file->getMimeType();
        $sourcePath = $file->getRealPath();

        if (! is_string($sourcePath) || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $source = $this->createImageResource($sourcePath, $mimeType);

        if ($source === null) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'media-webp-');

        if ($temporaryPath === false) {
            imagedestroy($source);

            return null;
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if ($sourceWidth < 1 || $sourceHeight < 1) {
                return null;
            }

            $maxDimension = 1800;
            $scale = min(1, $maxDimension / $sourceWidth, $maxDimension / $sourceHeight);
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            $target = $source;

            if ($targetWidth !== $sourceWidth || $targetHeight !== $sourceHeight) {
                $target = imagecreatetruecolor($targetWidth, $targetHeight);

                if (! $target) {
                    return null;
                }

                imagealphablending($target, false);
                imagesavealpha($target, true);
                $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
                imagefill($target, 0, 0, $transparent);
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            }

            if (! imagewebp($target, $temporaryPath, 82)) {
                return null;
            }

            $contents = file_get_contents($temporaryPath);

            if ($contents === false || $contents === '') {
                return null;
            }

            $optimizedSize = strlen($contents);

            if (
                $targetWidth === $sourceWidth
                && $targetHeight === $sourceHeight
                && $file->getSize() > 0
                && $optimizedSize >= $file->getSize()
            ) {
                return null;
            }

            $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeBaseName = preg_replace('/[^A-Za-z0-9\-_]/', '-', $baseName) ?: 'image';
            $storedPath = $directory.'/'.Str::uuid()->toString().'-'.$safeBaseName.'.webp';

            if (! Storage::disk($disk)->put($storedPath, $contents)) {
                return null;
            }

            return [
                'path' => $storedPath,
                'url' => Storage::disk($disk)->url($storedPath),
                'mime_type' => 'image/webp',
                'size_bytes' => Storage::disk($disk)->size($storedPath),
                'width' => $targetWidth,
                'height' => $targetHeight,
                'optimized' => true,
            ];
        } finally {
            if (isset($target) && $target !== $source) {
                imagedestroy($target);
            }

            imagedestroy($source);

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    protected function createImageResource(string $path, string $mimeType): mixed
    {
        return match ($mimeType) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) ?: null : null,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) ?: null : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) ?: null : null,
            default => null,
        };
    }
}
