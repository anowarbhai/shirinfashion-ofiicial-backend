<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MfsGatewaySettingsUpdateRequest;
use App\Services\AdminSettingsService;
use App\Services\MfsVerifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfsGatewaySettingsController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly MfsVerifyService $mfsVerifyService
    ) {
    }

    public function show(): JsonResponse
    {
        $data = $this->settings->getGroup('mfs_gateway');
        $baseUrl = rtrim((string) ($data['base_url'] ?? 'https://mfsapi.digitrixlabs.io'), '/');
        $brandColors = [
            'bkash' => '#e2136e',
            'nagad' => '#f7931e',
            'rocket' => '#8c3494',
            'upay' => '#005696',
        ];

        if (isset($data['accounts']) && is_array($data['accounts'])) {
            foreach ($data['accounts'] as $key => &$account) {
                $account['logo_url'] = "{$baseUrl}/images/providers/{$key}.png";
                $account['brand_color'] = $brandColors[$key] ?? '#0f172a';
            }
            unset($account);
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function update(MfsGatewaySettingsUpdateRequest $request): JsonResponse
    {
        $payload = $request->validated();

        if (($payload['api_secret'] ?? '') === '') {
            $payload['api_secret'] = $this->settings->getSetting('mfs_gateway.api_secret', '');
        }

        $data = $this->settings->saveGroup('mfs_gateway', $payload);

        return response()->json([
            'message' => 'MFS Gateway settings saved successfully.',
            'data' => $data,
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (blank($payload['api_key'] ?? null)) {
            $payload['api_key'] = $this->settings->getSetting('mfs_gateway.api_key', '');
        }

        if (blank($payload['api_secret'] ?? null)) {
            $payload['api_secret'] = $this->settings->getSetting('mfs_gateway.api_secret', '');
        }

        if (blank($payload['key_version'] ?? null)) {
            $payload['key_version'] = $this->settings->getSetting('mfs_gateway.key_version', '1');
        }

        if (blank($payload['base_url'] ?? null)) {
            $payload['base_url'] = $this->settings->getSetting('mfs_gateway.base_url', 'https://mfsapi.digitrixlabs.io');
        }

        $result = $this->mfsVerifyService->testConnection($payload);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }
}