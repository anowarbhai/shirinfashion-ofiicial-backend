<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookMarketingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->getSettings(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pixel_enabled' => ['required', 'boolean'],
            'pixel_id' => ['nullable', 'string', 'max:64'],
            'capi_enabled' => ['required', 'boolean'],
            'access_token' => ['nullable', 'string'],
            'test_event_code' => ['nullable', 'string', 'max:64'],
        ]);

        $settings = [
            'pixel_enabled' => (bool) $validated['pixel_enabled'],
            'pixel_id' => trim((string) ($validated['pixel_id'] ?? '')),
            'capi_enabled' => (bool) $validated['capi_enabled'],
            'access_token' => trim((string) ($validated['access_token'] ?? '')),
            'test_event_code' => trim((string) ($validated['test_event_code'] ?? '')),
        ];

        StorefrontSetting::query()->updateOrCreate(
            ['key' => 'facebook_marketing'],
            ['value' => $settings],
        );

        return response()->json([
            'message' => 'Facebook settings saved successfully.',
            'data' => $settings,
        ]);
    }

    private function getSettings(): array
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'facebook_marketing')
            ->value('value');

        return array_merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    private function defaults(): array
    {
        return [
            'pixel_enabled' => false,
            'pixel_id' => '',
            'capi_enabled' => false,
            'access_token' => '',
            'test_event_code' => '',
        ];
    }
}
