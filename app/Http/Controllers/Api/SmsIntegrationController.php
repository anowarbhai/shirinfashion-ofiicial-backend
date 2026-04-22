<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class SmsIntegrationController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
    ) {
    }

    public function publicConfig(): JsonResponse
    {
        $sms = $this->settings->getGroup('sms_integration');

        return response()->json([
            'data' => [
                'enabled' => (bool) ($sms['enabled'] ?? false),
                'enable_customer_login_otp' => (bool) ($sms['enable_customer_login_otp'] ?? false),
                'enable_admin_login_otp' => (bool) ($sms['enable_admin_login_otp'] ?? false),
                'enable_order_otp' => (bool) ($sms['enable_order_otp'] ?? false),
            ],
        ]);
    }
}
