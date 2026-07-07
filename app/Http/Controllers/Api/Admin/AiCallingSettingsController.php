<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiCallingSettingsUpdateRequest;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;

class AiCallingSettingsController extends Controller
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
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
}
