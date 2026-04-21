<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GeneralSettingsUpdateRequest;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class GeneralSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('general'),
        ]);
    }

    public function update(GeneralSettingsUpdateRequest $request): JsonResponse
    {
        $data = $this->settings->saveGroup('general', $request->validated());

        return response()->json([
            'message' => 'General settings saved successfully.',
            'data' => $data,
        ]);
    }
}
