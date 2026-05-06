<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MailSetupSettingsUpdateRequest;
use App\Http\Requests\Admin\MailSetupTestRequest;
use App\Services\AdminSettingsService;
use App\Services\MailSetupService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class MailSetupSettingsController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly MailSetupService $mailSetup,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->getGroup('mail_setup'),
        ]);
    }

    public function update(MailSetupSettingsUpdateRequest $request): JsonResponse
    {
        $payload = $request->validated();

        if (($payload['smtp_password'] ?? '') === '') {
            $payload['smtp_password'] = $this->settings->getSetting('mail_setup.smtp_password', '');
        }

        $data = $this->settings->saveGroup('mail_setup', $payload);

        return response()->json([
            'message' => 'Mail setup settings saved successfully.',
            'data' => $data,
        ]);
    }

    public function test(MailSetupTestRequest $request): JsonResponse
    {
        try {
            $this->mailSetup->sendTest($request->validated('recipient_email'));

            return response()->json([
                'message' => 'Test email sent successfully.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
