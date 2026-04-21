<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeFooterUpdateRequest;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;

class ThemeFooterController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->themeSettings->getGroup('footer'),
        ]);
    }

    public function update(ThemeFooterUpdateRequest $request): JsonResponse
    {
        $settings = $this->themeSettings->saveGroup('footer', $request->validated());

        return response()->json([
            'message' => 'Footer settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
