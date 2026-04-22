<?php

namespace App\Services;

use App\Models\SmsOtp;
use App\Models\User;
use App\Support\BangladeshPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class SmsOtpService
{
    public function __construct(
        private readonly AdminSettingsService $settings,
        private readonly SmsGatewayService $smsGateway,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function issue(string $purpose, string $phone, ?User $user = null, array $context = []): array
    {
        $this->ensureEnabled($purpose);

        $phone = $this->normalizePhone($phone);
        $code = (string) random_int(100000, 999999);
        $sessionToken = (string) Str::uuid();

        DB::transaction(function () use ($purpose, $phone, $user, $context, $code, $sessionToken): void {
            SmsOtp::query()
                ->where('purpose', $purpose)
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->delete();

            SmsOtp::query()->create([
                'session_token' => $sessionToken,
                'purpose' => $purpose,
                'user_id' => $user?->id,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'meta' => $context,
                'expires_at' => now()->addMinutes(5),
            ]);
        });

        $this->smsGateway->sendMessage(
            $phone,
            $this->renderTemplate($purpose, $code, $phone, $context)
        );

        return [
            'purpose' => $purpose,
            'otp_session_token' => $sessionToken,
            'phone_masked' => $this->maskPhone($phone),
            'expires_in_seconds' => 300,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $purpose, string $sessionToken, string $code, ?string $phone = null): array
    {
        $otp = $this->findActive($purpose, $sessionToken, $phone);

        if ($otp->attempts >= 5) {
            throw new RuntimeException('Too many invalid OTP attempts. Please request a new code.');
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw new RuntimeException('The OTP code is invalid.');
        }

        if (! $otp->verified_at) {
            $otp->forceFill(['verified_at' => now()])->save();
        }

        return [
            'purpose' => $purpose,
            'otp_session_token' => $otp->session_token,
            'phone_masked' => $this->maskPhone($otp->phone),
            'verified' => true,
        ];
    }

    public function consumeVerified(string $purpose, string $sessionToken, ?string $phone = null): SmsOtp
    {
        $otp = $this->findActive($purpose, $sessionToken, $phone);

        if (! $otp->verified_at) {
            throw new RuntimeException('Please verify the OTP before continuing.');
        }

        if ($otp->consumed_at) {
            throw new RuntimeException('This OTP session has already been used.');
        }

        $otp->forceFill([
            'consumed_at' => now(),
        ])->save();

        return $otp;
    }

    public function isEnabled(string $purpose): bool
    {
        $settings = $this->settings->getGroup('sms_integration');

        if (! ($settings['enabled'] ?? false)) {
            return false;
        }

        return match ($purpose) {
            'customer_login' => (bool) ($settings['enable_customer_login_otp'] ?? false),
            'admin_login' => (bool) ($settings['enable_admin_login_otp'] ?? false),
            'order' => (bool) ($settings['enable_order_otp'] ?? false),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function renderOrderTemplate(array $context): string
    {
        $settings = $this->settings->getGroup('sms_integration');

        return $this->replaceTemplateTokens(
            (string) ($settings['order_template'] ?? 'Your order {{order_number}} has been received.'),
            $context + ['brand' => $this->brandName()]
        );
    }

    private function ensureEnabled(string $purpose): void
    {
        if (! $this->isEnabled($purpose)) {
            throw new RuntimeException('OTP verification is disabled for this action.');
        }
    }

    private function findActive(string $purpose, string $sessionToken, ?string $phone = null): SmsOtp
    {
        $otp = SmsOtp::query()
            ->where('purpose', $purpose)
            ->where('session_token', $sessionToken)
            ->first();

        if (! $otp) {
            throw new RuntimeException('OTP session could not be found.');
        }

        if ($phone && $this->normalizePhone($phone) !== $otp->phone) {
            throw new RuntimeException('OTP session does not match this phone number.');
        }

        if ($otp->expires_at->isPast()) {
            throw new RuntimeException('This OTP has expired. Please request a new code.');
        }

        return $otp;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderTemplate(string $purpose, string $code, string $phone, array $context): string
    {
        $settings = $this->settings->getGroup('sms_integration');

        $template = match ($purpose) {
            'customer_login' => (string) ($settings['customer_otp_template'] ?? 'Your {{brand}} OTP is {{code}}.'),
            'admin_login' => (string) ($settings['admin_otp_template'] ?? 'Admin login OTP for {{brand}}: {{code}}.'),
            'order' => (string) ($settings['order_otp_template'] ?? 'Your {{brand}} order OTP is {{code}}.'),
            default => 'Your OTP is {{code}}.',
        };

        return $this->replaceTemplateTokens($template, $context + [
            'brand' => $this->brandName(),
            'code' => $code,
            'phone' => $phone,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function replaceTemplateTokens(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace('{{'.$key.'}}', (string) $value, $template);
            }
        }

        return preg_replace('/\{\{[^}]+\}\}/', '', $template) ?: $template;
    }

    private function normalizePhone(string $phone): string
    {
        return BangladeshPhone::normalizeToLocal($phone);
    }

    private function maskPhone(string $phone): string
    {
        $visible = 4;
        $length = strlen($phone);

        if ($length <= $visible) {
            return $phone;
        }

        return str_repeat('*', $length - $visible).substr($phone, -$visible);
    }

    private function brandName(): string
    {
        return (string) ($this->settings->getSetting('general.store_name', 'Shirin Fashion'));
    }
}
