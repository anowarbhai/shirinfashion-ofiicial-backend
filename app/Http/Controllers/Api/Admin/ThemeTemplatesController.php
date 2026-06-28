<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeTemplatesUpdateRequest;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;

class ThemeTemplatesController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->themeSettings->getGroup('templates'),
        ]);
    }

    public function update(ThemeTemplatesUpdateRequest $request): JsonResponse
    {
        $settings = $this->themeSettings->saveGroup('templates', $request->validated());

        return response()->json([
            'message' => 'Template settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
