<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->validate([
            'url' => ['required', 'string', 'max:3000'],
        ]);

        $url = trim((string) $payload['url']);
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($path) || ! is_string($host)) {
            abort(404);
        }

        if (in_array($host, ['localhost', '127.0.0.1'], true) && str_starts_with($path, '/storage/')) {
            return $this->localStorageResponse($path);
        }

        if ($host === 'cdn.shirinfashionbd.com' && str_starts_with($path, '/products/')) {
            return $this->localFrontendProductResponse($path)
                ?? $this->remoteImageResponse($url);
        }

        if ($host === 'shirin-fashion-cdn.s3.us-east-1.amazonaws.com' && str_starts_with($path, '/media/')) {
            return $this->remoteImageResponse($url);
        }

        abort(404);
    }

    protected function localStorageResponse(string $path): Response
    {
        $file = public_path(ltrim($path, '/'));

        if (! File::isFile($file)) {
            abort(404);
        }

        return $this->fileResponse($file);
    }

    protected function localFrontendProductResponse(string $path): ?Response
    {
        $file = base_path('../frontend/public/images'.str_replace('/products/', '/products/', $path));

        if (! File::isFile($file)) {
            return null;
        }

        return $this->fileResponse($file);
    }

    protected function remoteImageResponse(string $url): Response
    {
        $response = Http::timeout(8)->get($url);

        abort_unless($response->ok(), 404);

        return response($response->body(), 200, $this->corsHeaders([
            'Content-Type' => $response->header('Content-Type', 'application/octet-stream'),
            'Cache-Control' => 'public, max-age=86400',
        ]));
    }

    protected function fileResponse(string $file): Response
    {
        return response(File::get($file), 200, $this->corsHeaders([
            'Content-Type' => File::mimeType($file) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400',
        ]));
    }

    protected function corsHeaders(array $headers): array
    {
        return [
            ...$headers,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept',
        ];
    }
}
