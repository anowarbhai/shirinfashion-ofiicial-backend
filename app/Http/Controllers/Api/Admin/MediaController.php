<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $directory = 'media/'.now()->format('Y/m');
            $filename = Str::uuid()->toString().'-'.preg_replace('/[^A-Za-z0-9.\-_]/', '-', $file->getClientOriginalName());
            $storedPath = $file->storeAs($directory, $filename, 'public');
            $absolutePath = Storage::disk('public')->path($storedPath);
            $dimensions = @getimagesize($absolutePath) ?: [null, null];
            $publicBase = rtrim($request->getSchemeAndHttpHost(), '/').'/storage/';

            $media = MediaAsset::create([
                'file_name' => $file->getClientOriginalName(),
                'alt_text' => $validated['alt_text'] ?? null,
                'url' => $publicBase.$storedPath,
                'disk' => 'public',
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'metadata' => [
                    'folder' => $directory,
                    'original_name' => $file->getClientOriginalName(),
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

    public function destroy(MediaAsset $mediaAsset): JsonResponse
    {
        $mediaAsset->delete();

        return response()->json([
            'message' => 'Media asset deleted successfully.',
        ]);
    }
}
