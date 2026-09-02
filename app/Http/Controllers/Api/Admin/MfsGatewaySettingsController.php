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
        return response()->json([
            'data' => $this->settings->getGroup('mfs_gateway'),
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

        if (($payload['api_secret'] ?? '') === '') {
            $payload['api_secret'] = $this->settings->getSetting('mfs_gateway.api_secret', '');
        }

        $result = $this->mfsVerifyService->testConnection($payload);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }
}