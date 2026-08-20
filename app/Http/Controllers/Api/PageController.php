<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;

class PageController extends Controller
{
    public function sitemap(): JsonResponse
    {
        return response()->json([
            'data' => StorefrontPage::query()
                ->select(['slug', 'updated_at'])
                ->where('status', 'published')
                ->orderBy('slug')
                ->get()
                ->map(fn (StorefrontPage $page): array => [
                    'slug' => $page->slug,
                    'updated_at' => optional($page->updated_at)?->toIso8601String(),
                ]),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = StorefrontPage::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'template' => $page->template,
                'excerpt' => $page->excerpt,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'campaign_tracking' => $this->campaignTrackingFor($page),
                'builder_json' => $page->builder_json ?? [],
                'updated_at' => optional($page->updated_at)?->toIso8601String(),
            ],
        ]);
    }

    private function campaignTrackingFor(StorefrontPage $page): array
    {
        $facebookIds = collect($page->campaign_facebook_pixel_ids ?? [])->map(fn ($id) => (string) $id)->filter();
        $googleIds = collect($page->campaign_google_tag_ids ?? [])->map(fn ($id) => (string) $id)->filter();
        $facebookSettings = $this->settings('facebook_marketing');
        $googleSettings = $this->settings('google_marketing');

        return [
            'facebook_pixels' => collect($facebookSettings['campaign_pixels'] ?? [])
                ->filter(fn ($pixel): bool => is_array($pixel))
                ->filter(fn (array $pixel): bool => $facebookIds->contains((string) ($pixel['id'] ?? '')))
                ->filter(fn (array $pixel): bool => (bool) ($pixel['enabled'] ?? true))
                ->map(fn (array $pixel): array => [
                    'id' => (string) ($pixel['id'] ?? ''),
                    'pixel_id' => trim((string) ($pixel['pixel_id'] ?? '')),
                ])
                ->filter(fn (array $pixel): bool => $pixel['id'] !== '' && $pixel['pixel_id'] !== '')
                ->values()->all(),
            'google_tags' => collect($googleSettings['campaign_tags'] ?? [])
                ->filter(fn ($tag): bool => is_array($tag))
                ->filter(fn (array $tag): bool => $googleIds->contains((string) ($tag['id'] ?? '')))
                ->filter(fn (array $tag): bool => (bool) ($tag['enabled'] ?? true))
                ->map(fn (array $tag): array => [
                    'id' => (string) ($tag['id'] ?? ''),
                    'type' => in_array($tag['type'] ?? '', ['gtm', 'ga4', 'google_ads'], true) ? (string) $tag['type'] : 'gtm',
                    'tracking_id' => trim((string) ($tag['tracking_id'] ?? '')),
                ])
                ->filter(fn (array $tag): bool => $tag['id'] !== '' && $tag['tracking_id'] !== '')
                ->values()->all(),
        ];
    }

    private function settings(string $key): array
    {
        $stored = StorefrontSetting::query()->where('key', $key)->value('value');

        return is_array($stored) ? $stored : (json_decode((string) $stored, true) ?: []);
    }
}
