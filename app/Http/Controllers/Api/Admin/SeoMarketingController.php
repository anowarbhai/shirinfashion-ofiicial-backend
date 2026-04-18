<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoMarketingController extends Controller
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
            'home_title' => ['required', 'string', 'max:120'],
            'meta_description' => ['required', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['required', 'url', 'max:255'],
            'robots_content' => ['required', 'string', 'max:5000'],
        ]);

        $settings = [
            'home_title' => trim((string) $validated['home_title']),
            'meta_description' => trim((string) $validated['meta_description']),
            'meta_keywords' => trim((string) ($validated['meta_keywords'] ?? '')),
            'canonical_url' => trim((string) $validated['canonical_url']),
            'robots_content' => trim((string) $validated['robots_content']),
        ];

        StorefrontSetting::query()->updateOrCreate(
            ['key' => 'seo_settings'],
            ['value' => $settings],
        );

        return response()->json([
            'message' => 'SEO settings saved successfully.',
            'data' => $settings,
        ]);
    }

    private function getSettings(): array
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'seo_settings')
            ->value('value');

        return array_merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    private function defaults(): array
    {
        return [
            'home_title' => 'Shirin Fashion | Premium Cosmetics & Beauty',
            'meta_description' => 'Discover premium cosmetics and beauty products at Shirin Fashion. Shop skincare, makeup, fragrance and more.',
            'meta_keywords' => 'cosmetics, beauty, skincare, makeup, fragrance, fashion',
            'canonical_url' => 'https://shirinfashion.store',
            'robots_content' => "User-agent: *\nAllow: /\nSitemap: https://shirinfashion.store/sitemap.xml",
        ];
    }
}
