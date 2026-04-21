<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SmsIntegrationSettingsUpdateRequest;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class SmsIntegrationSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('sms_integration'),
        ]);
    }

    public function update(SmsIntegrationSettingsUpdateRequest $request): JsonResponse
    {
        $data = $this->settings->saveGroup('sms_integration', $request->validated());

        return response()->json([
            'message' => 'SMS integration settings saved successfully.',
            'data' => $data,
        ]);
    }
}
