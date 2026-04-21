<?php

use App\Services\ThemeSettingsService;

if (!function_exists('getThemeSetting')) {
    function getThemeSetting(string $path, mixed $default = null): mixed
    {
        return app(ThemeSettingsService::class)->getSetting($path, $default);
    }
}
