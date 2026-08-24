<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use App\Support\SensitiveSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FacebookMarketingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->redactTokens($this->getSettings()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pixel_enabled' => ['required', 'boolean'],
            'pixel_id' => ['nullable', 'string', 'max:64'],
            'capi_enabled' => ['required', 'boolean'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'test_event_code' => ['nullable', 'string', 'max:64'],
            'campaign_pixels' => ['nullable', 'array'],
            'campaign_pixels.*.id' => ['nullable', 'string', 'max:80'],
            'campaign_pixels.*.name' => ['nullable', 'string', 'max:120'],
            'campaign_pixels.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'campaign_pixels.*.capi_enabled' => ['sometimes', 'boolean'],
            'campaign_pixels.*.access_token' => ['nullable', 'string', 'max:4096'],
            'campaign_pixels.*.test_event_code' => ['nullable', 'string', 'max:64'],
            'campaign_pixels.*.enabled' => ['sometimes', 'boolean'],
        ]);

        $current = $this->getSettings();
        $settings = [
            'pixel_enabled' => (bool) $validated['pixel_enabled'],
            'pixel_id' => trim((string) ($validated['pixel_id'] ?? '')),
            'capi_enabled' => (bool) $validated['capi_enabled'],
            'access_token' => trim((string) ($validated['access_token'] ?? ''))
                ?: trim((string) ($current['access_token'] ?? '')),
            'test_event_code' => trim((string) ($validated['test_event_code'] ?? '')),
            'campaign_pixels' => $this->normalizeCampaignPixels(
                $validated['campaign_pixels'] ?? [],
                $current['campaign_pixels'] ?? [],
            ),
        ];

        StorefrontSetting::query()->updateOrCreate(
            ['key' => 'facebook_marketing'],
            ['value' => SensitiveSettings::protectFacebook($settings)],
        );

        return response()->json([
            'message' => 'Facebook settings saved successfully.',
            'data' => $this->redactTokens($settings),
        ]);
    }

    private function getSettings(): array
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'facebook_marketing')
            ->value('value');

        return array_merge(
            $this->defaults(),
            SensitiveSettings::revealFacebook(is_array($stored) ? $stored : []),
        );
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

    private function normalizeCampaignPixels(array $pixels, array $currentPixels = []): array
    {
        $currentById = collect($currentPixels)->keyBy(fn ($pixel): string => (string) ($pixel['id'] ?? ''));

        return collect($pixels)
            ->map(function (array $pixel) use ($currentById): array {
                $pixelId = trim((string) ($pixel['pixel_id'] ?? ''));
                $id = trim((string) ($pixel['id'] ?? '')) ?: 'fb_'.Str::uuid()->toString();
                $current = $currentById->get($id, []);

                return [
                    'id' => $id,
                    'name' => trim((string) ($pixel['name'] ?? '')) ?: 'Campaign Pixel',
                    'pixel_id' => $pixelId,
                    'capi_enabled' => (bool) ($pixel['capi_enabled'] ?? false),
                    'access_token' => trim((string) ($pixel['access_token'] ?? ''))
                        ?: trim((string) ($current['access_token'] ?? '')),
                    'test_event_code' => trim((string) ($pixel['test_event_code'] ?? '')),
                    'enabled' => (bool) ($pixel['enabled'] ?? true),
                ];
            })
            ->filter(fn (array $pixel): bool => $pixel['pixel_id'] !== '')
            ->values()
            ->all();
    }

    private function redactTokens(array $settings): array
    {
        $settings['has_access_token'] = trim((string) ($settings['access_token'] ?? '')) !== '';
        $settings['access_token'] = '';
        $settings['campaign_pixels'] = collect($settings['campaign_pixels'] ?? [])
            ->map(function ($pixel): array {
                $pixel = is_array($pixel) ? $pixel : [];
                $pixel['has_access_token'] = trim((string) ($pixel['access_token'] ?? '')) !== '';
                $pixel['access_token'] = '';

                return $pixel;
            })
            ->values()->all();

        return $settings;
    }
}
