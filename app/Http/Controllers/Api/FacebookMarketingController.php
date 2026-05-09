<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;

class FacebookMarketingController extends Controller
{
    public function show(): JsonResponse
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'facebook_marketing')
            ->value('value');

        $settings = array_merge([
            'pixel_enabled' => false,
            'pixel_id' => '',
            'campaign_pixels' => [],
        ], is_array($stored) ? $stored : []);

        return response()->json([
            'data' => [
                'pixel_enabled' => (bool) ($settings['pixel_enabled'] ?? false),
                'pixel_id' => trim((string) ($settings['pixel_id'] ?? '')),
                'campaign_pixels' => collect($settings['campaign_pixels'] ?? [])
                    ->filter(fn ($pixel): bool => is_array($pixel) && (bool) ($pixel['enabled'] ?? true))
                    ->map(fn (array $pixel): array => [
                        'id' => (string) ($pixel['id'] ?? ''),
                        'name' => (string) ($pixel['name'] ?? 'Campaign Pixel'),
                        'pixel_id' => trim((string) ($pixel['pixel_id'] ?? '')),
                        'enabled' => true,
                    ])
                    ->filter(fn (array $pixel): bool => $pixel['id'] !== '' && $pixel['pixel_id'] !== '')
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
