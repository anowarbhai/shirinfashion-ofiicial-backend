<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentGatewaySettingsUpdateRequest;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class PaymentGatewaySettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('payment_gateway'),
        ]);
    }

    public function update(PaymentGatewaySettingsUpdateRequest $request): JsonResponse
    {
        $payload = $request->validated();

        if (($payload['store_password'] ?? '') === '') {
            $payload['store_password'] = $this->settings->getSetting('payment_gateway.store_password', '');
        }

        $payload['currency'] = strtoupper($payload['currency']);

        $data = $this->settings->saveGroup('payment_gateway', $payload);

        return response()->json([
            'message' => 'Payment gateway settings saved successfully.',
            'data' => $data,
        ]);
    }
}
