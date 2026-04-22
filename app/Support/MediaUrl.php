<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class MediaUrl
{
    public static function normalizeStored(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || str_starts_with($value, 'data:image/')) {
            return $value !== '' ? $value : null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $path = (string) parse_url($value, PHP_URL_PATH);

            if (str_contains($path, '/storage/')) {
                return '/'.ltrim(strstr($path, '/storage/') ?: $path, '/');
            }

            return $value;
        }

        if (str_starts_with($value, '/storage/')) {
            return $value;
        }

        if (str_starts_with($value, 'storage/')) {
            return '/'.$value;
        }

        if (
            str_starts_with($value, 'media/')
            || str_starts_with($value, 'avatars/')
            || str_starts_with($value, 'logos/')
            || str_starts_with($value, 'favicons/')
        ) {
            return Storage::url($value);
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        return $value;
    }

    public static function toPublic(?string $value): ?string
    {
        $normalized = self::normalizeStored($value);

        if (! is_string($normalized) || $normalized === '' || str_starts_with($normalized, 'data:image/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        if (str_starts_with($normalized, '/storage/')) {
            return rtrim((string) config('app.url'), '/').$normalized;
        }

        if (str_starts_with($normalized, 'storage/')) {
            return rtrim((string) config('app.url'), '/').'/'.$normalized;
        }

        return $normalized;
    }
}
