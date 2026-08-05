<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;

class SeoMarketingController extends Controller
{
    public function show(): JsonResponse
    {
        $stored = StorefrontSetting::query()
            ->where('key', 'seo_settings')
            ->value('value');

        $settings = array_merge([
            'home_title' => '',
            'meta_description' => '',
            'meta_keywords' => '',
            'canonical_url' => '',
            'robots_content' => "User-agent: *\nAllow: /\nSitemap: /sitemap.xml",
        ], is_array($stored) ? $stored : []);

        return response()->json([
            'data' => [
                'home_title' => trim((string) ($settings['home_title'] ?? '')),
                'meta_description' => trim((string) ($settings['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($settings['meta_keywords'] ?? '')),
                'canonical_url' => trim((string) ($settings['canonical_url'] ?? '')),
                'robots_content' => trim((string) ($settings['robots_content'] ?? '')),
            ],
        ]);
    }
}
