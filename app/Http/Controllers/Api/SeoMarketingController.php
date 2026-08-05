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
            'home_title' => 'Shirin Fashion BD | Exclusive Fashion & Lifestyle Store',
            'meta_description' => 'Discover premium saree, three-piece, cosmetics, and lifestyle products at Shirin Fashion BD. Shop skincare, makeup, apparel and more.',
            'meta_keywords' => 'shirin fashion, fashion, saree, three piece, cosmetics, beauty, bdcaliph, online shopping bangladesh',
            'canonical_url' => 'https://bdcaliph.com',
            'robots_content' => "User-agent: *\nAllow: /\nSitemap: https://bdcaliph.com/sitemap.xml",
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
