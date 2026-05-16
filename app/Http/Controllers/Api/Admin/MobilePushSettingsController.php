<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobilePushSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('mobile_push'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'firebase_project_id' => ['nullable', 'string', 'max:255'],
            'firebase_client_email' => ['nullable', 'email', 'max:255'],
            'firebase_private_key' => ['nullable', 'string', 'max:12000'],
        ]);

        $payload['firebase_project_id'] = trim((string) ($payload['firebase_project_id'] ?? ''));
        $payload['firebase_client_email'] = trim((string) ($payload['firebase_client_email'] ?? ''));
        $payload['firebase_private_key'] = trim((string) ($payload['firebase_private_key'] ?? ''));

        $settings = $this->settings->saveGroup('mobile_push', $payload);

        return response()->json([
            'message' => 'Mobile push settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
