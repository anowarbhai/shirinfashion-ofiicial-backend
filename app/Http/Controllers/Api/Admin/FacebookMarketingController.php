<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'campaign_pixels' => ['nullable', 'array'],
            'campaign_pixels.*.id' => ['nullable', 'string', 'max:80'],
            'campaign_pixels.*.name' => ['nullable', 'string', 'max:120'],
            'campaign_pixels.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'campaign_pixels.*.capi_enabled' => ['sometimes', 'boolean'],
            'campaign_pixels.*.access_token' => ['nullable', 'string'],
            'campaign_pixels.*.test_event_code' => ['nullable', 'string', 'max:64'],
            'campaign_pixels.*.enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = [
            'pixel_enabled' => (bool) $validated['pixel_enabled'],
            'pixel_id' => trim((string) ($validated['pixel_id'] ?? '')),
            'capi_enabled' => (bool) $validated['capi_enabled'],
            'access_token' => trim((string) ($validated['access_token'] ?? '')),
            'test_event_code' => trim((string) ($validated['test_event_code'] ?? '')),
            'campaign_pixels' => $this->normalizeCampaignPixels($validated['campaign_pixels'] ?? []),
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
            'campaign_pixels' => [],
        ];
    }

    private function normalizeCampaignPixels(array $pixels): array
    {
        return collect($pixels)
            ->map(function (array $pixel): array {
                $pixelId = trim((string) ($pixel['pixel_id'] ?? ''));

                return [
                    'id' => trim((string) ($pixel['id'] ?? '')) ?: 'fb_'.Str::uuid()->toString(),
                    'name' => trim((string) ($pixel['name'] ?? '')) ?: 'Campaign Pixel',
                    'pixel_id' => $pixelId,
                    'capi_enabled' => (bool) ($pixel['capi_enabled'] ?? false),
                    'access_token' => trim((string) ($pixel['access_token'] ?? '')),
                    'test_event_code' => trim((string) ($pixel['test_event_code'] ?? '')),
                    'enabled' => (bool) ($pixel['enabled'] ?? true),
                ];
            })
            ->filter(fn (array $pixel): bool => $pixel['pixel_id'] !== '')
            ->values()
            ->all();
    }
}
