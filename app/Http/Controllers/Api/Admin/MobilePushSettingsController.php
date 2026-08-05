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
            'dashboard_widget_enabled' => ['sometimes', 'boolean'],
            'firebase_project_id' => ['nullable', 'string', 'max:255'],
            'firebase_client_email' => ['nullable', 'email', 'max:255'],
            'firebase_private_key' => ['nullable', 'string', 'max:12000'],
            'app_update_enabled' => ['sometimes', 'boolean'],
            'latest_version' => ['nullable', 'string', 'max:64'],
            'latest_build_number' => ['sometimes', 'integer', 'min:1', 'max:999999'],
            'minimum_build_number' => ['sometimes', 'integer', 'min:1', 'max:999999'],
            'update_url' => ['nullable', 'url', 'max:500'],
            'update_title' => ['nullable', 'string', 'max:120'],
            'update_message' => ['nullable', 'string', 'max:500'],
            'critical_update_title' => ['nullable', 'string', 'max:120'],
            'critical_update_message' => ['nullable', 'string', 'max:500'],
            'update_reminder_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'cart_reminder_enabled' => ['sometimes', 'boolean'],
            'cart_reminder_delay_minutes' => ['sometimes', 'integer', 'min:10', 'max:10080'],
            'cart_reminder_repeat_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'cart_reminder_max_reminders' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'cart_reminder_title' => ['nullable', 'string', 'max:120'],
            'cart_reminder_body' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = array_replace($this->settings->getGroup('mobile_push'), $payload);

        $payload['dashboard_widget_enabled'] = (bool) ($payload['dashboard_widget_enabled'] ?? true);
        $payload['firebase_project_id'] = trim((string) $payload['firebase_project_id']);
        $payload['firebase_client_email'] = trim((string) $payload['firebase_client_email']);
        $payload['firebase_private_key'] = trim((string) $payload['firebase_private_key']);
        $payload['app_update_enabled'] = (bool) $payload['app_update_enabled'];
        $payload['latest_version'] = trim((string) $payload['latest_version']);
        $payload['latest_build_number'] = (int) $payload['latest_build_number'];
        $payload['minimum_build_number'] = min(
            (int) $payload['minimum_build_number'],
            (int) $payload['latest_build_number'],
        );
        $payload['update_url'] = trim((string) $payload['update_url']);
        $payload['update_title'] = trim((string) $payload['update_title']);
        $payload['update_message'] = trim((string) $payload['update_message']);
        $payload['critical_update_title'] = trim((string) $payload['critical_update_title']);
        $payload['critical_update_message'] = trim((string) $payload['critical_update_message']);
        $payload['update_reminder_hours'] = (int) $payload['update_reminder_hours'];
        $payload['cart_reminder_enabled'] = (bool) $payload['cart_reminder_enabled'];
        $payload['cart_reminder_delay_minutes'] = (int) $payload['cart_reminder_delay_minutes'];
        $payload['cart_reminder_repeat_hours'] = (int) $payload['cart_reminder_repeat_hours'];
        $payload['cart_reminder_max_reminders'] = (int) $payload['cart_reminder_max_reminders'];
        $payload['cart_reminder_title'] = trim((string) $payload['cart_reminder_title']);
        $payload['cart_reminder_body'] = trim((string) $payload['cart_reminder_body']);

        $settings = $this->settings->saveGroup('mobile_push', $payload);

        return response()->json([
            'message' => 'Mobile push settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
