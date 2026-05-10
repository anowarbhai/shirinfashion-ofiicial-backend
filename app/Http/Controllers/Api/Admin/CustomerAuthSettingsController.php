<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAuthSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('customer_auth'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'google_login_enabled' => ['required', 'boolean'],
            'google_client_id' => ['nullable', 'string', 'max:255'],
        ]);

        $payload['google_client_id'] = trim((string) ($payload['google_client_id'] ?? ''));

        $settings = $this->settings->saveGroup('customer_auth', $payload, true);

        return response()->json([
            'message' => 'Customer auth settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
