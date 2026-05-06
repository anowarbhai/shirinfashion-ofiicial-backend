<?php

namespace App\Services;

use App\Models\StorefrontSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class AdminSettingsService
{
    public const CACHE_PREFIX = 'admin.settings.';

    public function getGroup(string $group): array
    {
        return Cache::remember(
            self::CACHE_PREFIX.$group,
            now()->addHour(),
            function () use ($group): array {
                $stored = StorefrontSetting::query()
                    ->where('key', $this->groupKey($group))
                    ->value('value');

                return array_replace_recursive(
                    $this->defaults()[$group] ?? [],
                    is_array($stored) ? $stored : [],
                );
            }
        );
    }

    public function saveGroup(string $group, array $data, bool $isPublic = false): array
    {
        $merged = array_replace_recursive($this->defaults()[$group] ?? [], $data);

        StorefrontSetting::query()->updateOrCreate(
            ['key' => $this->groupKey($group)],
            [
                'group' => 'settings.'.$group,
                'value' => $merged,
                'type' => 'json',
                'is_public' => $isPublic,
            ],
        );

        $this->flush($group);

        return $merged;
    }

    public function getSetting(string $path, mixed $default = null): mixed
    {
        [$group, $nested] = array_pad(explode('.', $path, 2), 2, null);

        if (!$group) {
            return $default;
        }

        $groupSettings = $this->getGroup($group);

        return $nested ? Arr::get($groupSettings, $nested, $default) : $groupSettings;
    }

    public function flush(?string $group = null): void
    {
        if ($group) {
            Cache::forget(self::CACHE_PREFIX.$group);

            return;
        }

        foreach (array_keys($this->defaults()) as $defaultGroup) {
            Cache::forget(self::CACHE_PREFIX.$defaultGroup);
        }
    }

    public function defaults(): array
    {
        return [
            'general' => [
                'store_name' => 'Shirin Fashion',
                'store_tagline' => 'Premium cosmetics and beauty products',
                'support_email' => 'hello@shirinfashionbd.com',
                'support_phone' => '+8801901856510',
                'order_prefix' => 'SBA',
                'default_currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'maintenance_mode' => false,
                'maintenance_message' => 'We are performing a quick update. Please check back shortly.',
                'invoice_note' => 'Thank you for shopping with Shirin Fashion.',
            ],
            'fraud_checker' => [
                'enabled' => false,
                'api_key' => '',
                'api_url' => 'https://fraudchecker.ocs-api.top/api/v3',
                'auto_hold_high_risk' => true,
                'block_disposable_email' => true,
                'block_international_phone_mismatch' => false,
                'risk_score_threshold' => 75,
                'max_orders_per_phone_per_day' => 5,
                'max_orders_per_ip_per_day' => 7,
                'blacklist_phones' => [],
                'blacklist_ips' => [],
                'review_note' => 'High-risk orders will be held for manual review.',
            ],
            'checkout_guard' => [
                'enabled' => true,
                'block_by_phone' => true,
                'block_by_ip' => true,
                'block_by_device' => true,
                'cooldown_minutes' => 180,
                'message' => 'You can place another order after {{time}}.',
            ],
            'mail_setup' => [
                'enabled' => false,
                'provider' => 'gmail',
                'recipient_email' => 'hello@shirinfashionbd.com',
                'from_address' => 'hello@shirinfashionbd.com',
                'from_name' => 'Shirin Fashion',
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_timeout' => 30,
            ],
            'sms_integration' => [
                'enabled' => false,
                'provider' => 'custom',
                'api_key' => '',
                'api_secret' => '',
                'sender_id' => '',
                'base_url' => '',
                'enable_customer_login_otp' => false,
                'enable_admin_login_otp' => false,
                'enable_order_otp' => false,
                'customer_otp_template' => 'Your Shirin Fashion OTP is {{code}}.',
                'admin_otp_template' => 'Admin login OTP for Shirin Fashion: {{code}}.',
                'order_otp_template' => 'Your Shirin Fashion order OTP is {{code}}.',
                'order_template' => 'Your order {{order_number}} has been received.',
                'status_callback_url' => '',
            ],
        ];
    }

    private function groupKey(string $group): string
    {
        return 'settings.'.$group;
    }
}
