<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiCallingTestRequest;
use App\Http\Requests\Admin\AiCallingSettingsUpdateRequest;
use App\Services\AdminSettingsService;
use App\Services\AiOrderCallingService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AiCallingSettingsController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly AiOrderCallingService $calling,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('ai_calling'),
        ]);
    }

    public function update(AiCallingSettingsUpdateRequest $request): JsonResponse
    {
        $data = $this->settings->saveGroup('ai_calling', $request->validated());

        return response()->json([
            'message' => 'AI calling settings saved successfully.',
            'data' => $data,
        ]);
    }

    public function test(AiCallingTestRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'AI test call request submitted successfully.',
                'data' => $this->calling->sendTestCall($request->validated()),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
