<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'campaign_tags' => ['nullable', 'array'],
            'campaign_tags.*.id' => ['nullable', 'string', 'max:80'],
            'campaign_tags.*.name' => ['nullable', 'string', 'max:120'],
            'campaign_tags.*.type' => ['nullable', 'in:gtm,ga4,google_ads'],
            'campaign_tags.*.tracking_id' => ['nullable', 'string', 'max:64'],
            'campaign_tags.*.enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = [
            'gtm_enabled' => (bool) $validated['gtm_enabled'],
            'gtm_container_id' => $this->normalizeTrackingId((string) ($validated['gtm_container_id'] ?? ''), 'gtm'),
            'ga4_enabled' => (bool) $validated['ga4_enabled'],
            'ga4_measurement_id' => $this->normalizeTrackingId((string) ($validated['ga4_measurement_id'] ?? ''), 'ga4'),
            'google_ads_enabled' => (bool) $validated['google_ads_enabled'],
            'google_ads_conversion_id' => $this->normalizeTrackingId((string) ($validated['google_ads_conversion_id'] ?? ''), 'google_ads'),
            'campaign_tags' => $this->normalizeCampaignTags($validated['campaign_tags'] ?? []),
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
            'campaign_tags' => [],
        ];
    }

    private function normalizeCampaignTags(array $tags): array
    {
        return collect($tags)
            ->map(function (array $tag): array {
                $trackingId = trim((string) ($tag['tracking_id'] ?? ''));
                $type = in_array($tag['type'] ?? '', ['gtm', 'ga4', 'google_ads'], true)
                    ? (string) $tag['type']
                    : 'gtm';
                $trackingId = $this->normalizeTrackingId($trackingId, $type);

                return [
                    'id' => trim((string) ($tag['id'] ?? '')) ?: 'gg_'.Str::uuid()->toString(),
                    'name' => trim((string) ($tag['name'] ?? '')) ?: 'Campaign Tag',
                    'type' => $type,
                    'tracking_id' => $trackingId,
                    'enabled' => (bool) ($tag['enabled'] ?? true),
                ];
            })
            ->filter(fn (array $tag): bool => $tag['tracking_id'] !== '')
            ->values()
            ->all();
    }

    private function normalizeTrackingId(string $value, string $type): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $patterns = [
            'gtm' => '/GTM-[A-Z0-9]+/i',
            'ga4' => '/G-[A-Z0-9]+/i',
            'google_ads' => '/AW-[A-Z0-9]+/i',
        ];

        $pattern = $patterns[$type] ?? null;

        if ($pattern && preg_match($pattern, $value, $matches)) {
            return strtoupper($matches[0]);
        }

        return strtoupper($value);
    }
}
