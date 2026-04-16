<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleMarketingController extends Controller
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
            'gtm_enabled' => ['required', 'boolean'],
            'gtm_container_id' => ['nullable', 'string', 'max:64'],
            'ga4_enabled' => ['required', 'boolean'],
            'ga4_measurement_id' => ['nullable', 'string', 'max:64'],
            'google_ads_enabled' => ['required', 'boolean'],
            'google_ads_conversion_id' => ['nullable', 'string', 'max:64'],
        ]);

        $settings = [
            'gtm_enabled' => (bool) $validated['gtm_enabled'],
            'gtm_container_id' => trim((string) ($validated['gtm_container_id'] ?? '')),
            'ga4_enabled' => (bool) $validated['ga4_enabled'],
            'ga4_measurement_id' => trim((string) ($validated['ga4_measurement_id'] ?? '')),
            'google_ads_enabled' => (bool) $validated['google_ads_enabled'],
            'google_ads_conversion_id' => trim((string) ($validated['google_ads_conversion_id'] ?? '')),
        ];

        StorefrontSetting::query()->updateOrCreate(
            ['key' => 'google_marketing'],
            ['value' => $settings],
        );

        return response()->json([
            'message' => 'Google settings saved successfully.',
            'data' => $settings,
        ]);
    }

    private function getSettings(): array
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'google_marketing')
            ->value('value');

        return array_merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    private function defaults(): array
    {
        return [
            'gtm_enabled' => false,
            'gtm_container_id' => '',
            'ga4_enabled' => false,
            'ga4_measurement_id' => '',
            'google_ads_enabled' => false,
            'google_ads_conversion_id' => '',
        ];
    }
}
