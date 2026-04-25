<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FraudCheckerSettingsUpdateRequest;
use App\Http\Requests\Admin\FraudCheckerTestRequest;
use App\Services\AdminSettingsService;
use App\Services\FraudCheckerService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class FraudCheckerSettingsController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly FraudCheckerService $fraudChecker,
    )
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('fraud_checker'),
        ]);
    }

    public function update(FraudCheckerSettingsUpdateRequest $request): JsonResponse
    {
        $data = $this->settings->saveGroup('fraud_checker', $request->validated());

        return response()->json([
            'message' => 'Fraud checker settings saved successfully.',
            'data' => $data,
        ]);
    }

    public function test(FraudCheckerTestRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Fraud checker result loaded successfully.',
                'data' => $this->fraudChecker->check($request->validated('phone')),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
