<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Services\AdminSettingsService;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class ContactMessageController extends Controller
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly ThemeSettingsService $themeSettings,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contactMessage = ContactMessage::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'subject' => $payload['subject'],
            'message' => $payload['message'],
            'status' => 'new',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            $this->ensureMailIsConfigured();

            Mail::to($this->resolveRecipientEmail())->send(
                new ContactMessageReceived($contactMessage),
            );
        } catch (Throwable $exception) {
            Log::error('Contact message email failed.', [
                'contact_message_id' => $contactMessage->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Your message was saved, but the email could not be sent right now.',
            ], 502);
        }

        return response()->json([
            'message' => 'Your message has been sent successfully.',
            'data' => [
                'id' => $contactMessage->id,
                'status' => $contactMessage->status,
            ],
        ], 201);
    }

    private function resolveRecipientEmail(): string
    {
        $appearanceSettings = $this->themeSettings->getGroup('appearance');
        $contactEmail = trim((string) ($appearanceSettings['contact']['email'] ?? ''));

        if ($contactEmail !== '') {
            return $contactEmail;
        }

        $generalSettings = $this->settings->getGroup('general');
        $supportEmail = trim((string) ($generalSettings['support_email'] ?? ''));

        return $supportEmail !== ''
            ? $supportEmail
            : (string) config('mail.from.address');
    }

    private function ensureMailIsConfigured(): void
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            throw new RuntimeException(
                'Mail server is not configured. Set MAIL_MAILER=smtp or another real mail driver.',
            );
        }
    }
}
