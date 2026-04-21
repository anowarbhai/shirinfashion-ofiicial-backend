<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;

class ThemeController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => array_merge(
                $this->themeSettings->getPublicBundle(),
                ['menus' => $this->themeSettings->getMenusBundle()],
            ),
        ]);
    }
}
