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
        ], is_array($stored) ? $stored : []);

        return response()->json([
            'data' => [
                'pixel_enabled' => (bool) ($settings['pixel_enabled'] ?? false),
                'pixel_id' => trim((string) ($settings['pixel_id'] ?? '')),
            ],
        ]);
    }
}
