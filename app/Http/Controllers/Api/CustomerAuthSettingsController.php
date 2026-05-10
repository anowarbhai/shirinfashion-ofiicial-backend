<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class CustomerAuthSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        $settings = $this->settings->getGroup('customer_auth');
        $enabled = (bool) ($settings['google_login_enabled'] ?? false);
        $clientId = trim((string) ($settings['google_client_id'] ?? ''));

        return response()->json([
            'data' => [
                'google_login_enabled' => $enabled && $clientId !== '',
                'google_client_id' => $enabled ? $clientId : '',
            ],
        ]);
    }
}
