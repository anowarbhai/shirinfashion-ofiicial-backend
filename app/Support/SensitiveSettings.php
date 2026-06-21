<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class SensitiveSettings
{
    private const PREFIX = 'encrypted:v1:';

    private const GROUP_PATHS = [
        'fraud_checker' => ['api_key', 'onesoftcode_api_key', 'bd_courier_api_key'],
        'mail_setup' => ['smtp_password'],
        'mobile_push' => ['firebase_private_key'],
        'sms_integration' => ['api_key', 'api_secret'],
    ];

    public static function protectGroup(string $group, array $settings): array
    {
        return self::transformPaths($settings, self::GROUP_PATHS[$group] ?? [], true);
    }

    public static function revealGroup(string $group, array $settings): array
    {
        return self::transformPaths($settings, self::GROUP_PATHS[$group] ?? [], false);
    }

    public static function protectFacebook(array $settings): array
    {
        $settings = self::transformPaths($settings, ['access_token'], true);
        $settings['campaign_pixels'] = collect($settings['campaign_pixels'] ?? [])
            ->map(fn ($pixel) => is_array($pixel)
                ? self::transformPaths($pixel, ['access_token'], true)
                : $pixel)
            ->all();

        return $settings;
    }

    public static function revealFacebook(array $settings): array
    {
        $settings = self::transformPaths($settings, ['access_token'], false);
        $settings['campaign_pixels'] = collect($settings['campaign_pixels'] ?? [])
            ->map(fn ($pixel) => is_array($pixel)
                ? self::transformPaths($pixel, ['access_token'], false)
                : $pixel)
            ->all();

        return $settings;
    }

    private static function transformPaths(array $settings, array $paths, bool $protect): array
    {
        foreach ($paths as $path) {
            $value = Arr::get($settings, $path);

            if (! is_string($value) || $value === '') {
                continue;
            }

            Arr::set(
                $settings,
                $path,
                $protect ? self::protectValue($value) : self::revealValue($value),
            );
        }

        return $settings;
    }

    private static function protectValue(string $value): string
    {
        if (str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        return self::PREFIX.Crypt::encryptString($value);
    }

    private static function revealValue(string $value): string
    {
        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        } catch (Throwable) {
            return '';
        }
    }
}
