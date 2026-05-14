<?php

namespace App\Http\Resources\Mobile\Concerns;

use Illuminate\Http\Request;

trait ResolvesMobileUrls
{
    protected function mobileUrl(?string $url, Request $request): ?string
    {
        if (! is_string($url) || $url === '' || str_starts_with($url, 'data:image/')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($host) && is_string($path)) {
            if (in_array($host, ['localhost', '127.0.0.1'], true) && str_starts_with($path, '/storage/')) {
                return $this->mobileMediaProxyUrl($url, $request);
            }

            if ($host === 'cdn.shirinfashionbd.com' && str_starts_with($path, '/products/')) {
                return $this->mobileMediaProxyUrl($url, $request);
            }
        }

        return $url;
    }

    protected function mobileUrlList(mixed $urls, Request $request): array
    {
        return collect(is_array($urls) ? $urls : [])
            ->map(fn ($url) => $this->mobileUrl(is_string($url) ? $url : null, $request))
            ->filter()
            ->values()
            ->all();
    }

    protected function mobileMediaProxyUrl(string $url, Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/')
            .'/api/v1/mobile/media?url='
            .rawurlencode($url);
    }
}
