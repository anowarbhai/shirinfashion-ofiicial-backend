<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FraudCheckerSettingsUpdateRequest;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class FraudCheckerSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
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
}
