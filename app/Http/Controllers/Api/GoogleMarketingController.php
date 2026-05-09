<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;

class GoogleMarketingController extends Controller
{
    public function show(): JsonResponse
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'google_marketing')
            ->value('value');

        $settings = array_merge([
            'gtm_enabled' => false,
            'gtm_container_id' => '',
            'ga4_enabled' => false,
            'ga4_measurement_id' => '',
            'google_ads_enabled' => false,
            'google_ads_conversion_id' => '',
            'campaign_tags' => [],
        ], is_array($stored) ? $stored : []);

        return response()->json([
            'data' => [
                'gtm_enabled' => (bool) ($settings['gtm_enabled'] ?? false),
                'gtm_container_id' => trim((string) ($settings['gtm_container_id'] ?? '')),
                'ga4_enabled' => (bool) ($settings['ga4_enabled'] ?? false),
                'ga4_measurement_id' => trim((string) ($settings['ga4_measurement_id'] ?? '')),
                'google_ads_enabled' => (bool) ($settings['google_ads_enabled'] ?? false),
                'google_ads_conversion_id' => trim((string) ($settings['google_ads_conversion_id'] ?? '')),
                'campaign_tags' => collect($settings['campaign_tags'] ?? [])
                    ->filter(fn ($tag): bool => is_array($tag) && (bool) ($tag['enabled'] ?? true))
                    ->map(fn (array $tag): array => [
                        'id' => (string) ($tag['id'] ?? ''),
                        'name' => (string) ($tag['name'] ?? 'Campaign Tag'),
                        'type' => in_array($tag['type'] ?? '', ['gtm', 'ga4', 'google_ads'], true) ? (string) $tag['type'] : 'gtm',
                        'tracking_id' => trim((string) ($tag['tracking_id'] ?? '')),
                        'enabled' => true,
                    ])
                    ->filter(fn (array $tag): bool => $tag['id'] !== '' && $tag['tracking_id'] !== '')
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
