<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class PublicMfsGatewaySettingsController extends Controller
{
    private const PROVIDER_METADATA = [
        'bkash' => [
            'name' => 'bKash',
            'brand_color' => '#e2136e',
        ],
        'nagad' => [
            'name' => 'Nagad',
            'brand_color' => '#f7931e',
        ],
        'rocket' => [
            'name' => 'Rocket',
            'brand_color' => '#8c3494',
        ],
        'upay' => [
            'name' => 'Upay',
            'brand_color' => '#005696',
        ],
    ];

    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        $mfs = $this->settings->getGroup('mfs_gateway');
        $baseUrl = rtrim((string) ($mfs['base_url'] ?? 'https://mfsapi.digitrixlabs.io'), '/');
        $accounts = [];

        foreach (($mfs['accounts'] ?? []) as $key => $account) {
            $meta = self::PROVIDER_METADATA[$key] ?? [];
            $accounts[$key] = [
                'enabled' => (bool) ($account['enabled'] ?? false),
                'name' => (string) ($meta['name'] ?? ucfirst((string) $key)),
                'number' => (string) ($account['number'] ?? ''),
                'type' => (string) ($account['type'] ?? 'personal'),
                'instruction' => (string) ($account['instruction'] ?? ''),
                'logo_url' => "{$baseUrl}/images/providers/{$key}.png",
                'brand_color' => (string) ($meta['brand_color'] ?? '#0f172a'),
            ];
        }

        return response()->json([
            'data' => [
                'enabled' => (bool) ($mfs['enabled'] ?? false),
                'accounts' => $accounts,
            ],
        ]);
    }
}