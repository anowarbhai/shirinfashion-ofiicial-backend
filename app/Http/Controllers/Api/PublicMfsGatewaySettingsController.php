<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class PublicMfsGatewaySettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        $mfs = $this->settings->getGroup('mfs_gateway');
        $accounts = [];

        foreach (($mfs['accounts'] ?? []) as $key => $account) {
            $accounts[$key] = [
                'enabled' => (bool) ($account['enabled'] ?? false),
                'number' => (string) ($account['number'] ?? ''),
                'type' => (string) ($account['type'] ?? 'personal'),
                'instruction' => (string) ($account['instruction'] ?? ''),
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