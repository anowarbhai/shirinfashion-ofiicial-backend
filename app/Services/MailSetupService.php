<?php

namespace App\Services;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class MailSetupService
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function settings(): array
    {
        return $this->settings->getGroup('mail_setup');
    }

    public function sendContactMessage(ContactMessage $contactMessage, string $recipientEmail): void
    {
        $this->configureMailer();

        Mail::to($recipientEmail)->send(new ContactMessageReceived($contactMessage));
    }

    public function sendTest(string $recipientEmail): void
    {
        $this->configureMailer();

        Mail::raw('This is a test email from Shirin Fashion mail setup.', function ($message) use ($recipientEmail): void {
            $message
                ->to($recipientEmail)
                ->subject('Shirin Fashion Mail Setup Test');
        });
    }

    public function configureMailer(): void
    {
        $settings = $this->settings();

        if (! ($settings['enabled'] ?? false)) {
            throw new RuntimeException('Mail setup is disabled. Enable it from Admin Settings > Mail Setup.');
        }

        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $username = trim((string) ($settings['smtp_username'] ?? ''));
        $password = (string) ($settings['smtp_password'] ?? '');
        $fromAddress = trim((string) ($settings['from_address'] ?? ''));

        if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
            throw new RuntimeException('Mail setup is incomplete. SMTP host, username, password, and from email are required.');
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => ($settings['smtp_encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => (int) ($settings['smtp_port'] ?? 587),
            'mail.mailers.smtp.encryption' => ($settings['smtp_encryption'] ?? 'tls') === 'none'
                ? null
                : ($settings['smtp_encryption'] ?? 'tls'),
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.timeout' => (int) ($settings['smtp_timeout'] ?? 30),
            'mail.from.address' => $fromAddress,
            'mail.from.name' => (string) ($settings['from_name'] ?? config('app.name')),
        ]);

        Mail::purge('smtp');
    }
}
