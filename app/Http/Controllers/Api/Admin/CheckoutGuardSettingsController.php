<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckoutGuardSettingsUpdateRequest;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class CheckoutGuardSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('checkout_guard'),
        ]);
    }

    public function update(CheckoutGuardSettingsUpdateRequest $request): JsonResponse
    {
        $data = $this->settings->saveGroup('checkout_guard', $request->validated());

        return response()->json([
            'message' => 'Checkout guard settings saved successfully.',
            'data' => $data,
        ]);
    }
}
