<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeAppearanceUpdateRequest;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;

class ThemeAppearanceController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->themeSettings->getGroup('appearance'),
        ]);
    }

    public function update(ThemeAppearanceUpdateRequest $request): JsonResponse
    {
        $settings = $this->themeSettings->saveGroup('appearance', $request->validated());

        return response()->json([
            'message' => 'Appearance settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
