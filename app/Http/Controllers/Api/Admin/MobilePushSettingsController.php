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
            'cart_reminder_enabled' => ['sometimes', 'boolean'],
            'cart_reminder_delay_minutes' => ['sometimes', 'integer', 'min:10', 'max:10080'],
            'cart_reminder_repeat_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'cart_reminder_max_reminders' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'cart_reminder_title' => ['nullable', 'string', 'max:120'],
            'cart_reminder_body' => ['nullable', 'string', 'max:500'],
        ]);

        $payload['firebase_project_id'] = trim((string) ($payload['firebase_project_id'] ?? ''));
        $payload['firebase_client_email'] = trim((string) ($payload['firebase_client_email'] ?? ''));
        $payload['firebase_private_key'] = trim((string) ($payload['firebase_private_key'] ?? ''));
        $payload['cart_reminder_enabled'] = (bool) ($payload['cart_reminder_enabled'] ?? true);
        $payload['cart_reminder_delay_minutes'] = (int) ($payload['cart_reminder_delay_minutes'] ?? 120);
        $payload['cart_reminder_repeat_hours'] = (int) ($payload['cart_reminder_repeat_hours'] ?? 24);
        $payload['cart_reminder_max_reminders'] = (int) ($payload['cart_reminder_max_reminders'] ?? 2);
        $payload['cart_reminder_title'] = trim((string) ($payload['cart_reminder_title'] ?? 'Your cart is waiting'));
        $payload['cart_reminder_body'] = trim((string) ($payload['cart_reminder_body'] ?? 'You left items in your Shirin Fashion cart.'));

        $settings = $this->settings->saveGroup('mobile_push', $payload);

        return response()->json([
            'message' => 'Mobile push settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
