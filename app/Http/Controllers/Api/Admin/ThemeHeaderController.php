<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeHeaderUpdateRequest;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;

class ThemeHeaderController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->themeSettings->getGroup('header'),
        ]);
    }

    public function update(ThemeHeaderUpdateRequest $request): JsonResponse
    {
        $settings = $this->themeSettings->saveGroup('header', $request->validated());

        return response()->json([
            'message' => 'Header settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
