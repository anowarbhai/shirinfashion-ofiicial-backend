<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SmsIntegrationSettingsUpdateRequest;
use App\Http\Requests\Admin\SmsTestMessageRequest;
use App\Services\AdminSettingsService;
use App\Services\SmsGatewayService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SmsIntegrationSettingsController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly SmsGatewayService $smsGateway
    )
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

    public function balance(): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->smsGateway->getBalance(),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function sendTest(SmsTestMessageRequest $request): JsonResponse
    {
        try {
            $result = $this->smsGateway->sendTestMessage(
                $request->string('number')->toString(),
                $request->string('message')->toString(),
            );

            return response()->json([
                'message' => 'Test SMS request submitted successfully.',
                'data' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
