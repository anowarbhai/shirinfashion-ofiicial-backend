<?php

namespace App\Services;

use App\Models\StorefrontSetting;
use App\Support\SensitiveSettings;
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
                    SensitiveSettings::revealGroup($group, is_array($stored) ? $stored : []),
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
                'value' => SensitiveSettings::protectGroup($group, $merged),
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

        if (! $group) {
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
                'store_name' => 'Digitrix Labs',
                'store_tagline' => 'Premium digital commerce and software solutions',
                'support_email' => 'hello@digitrixlabs.com',
                'support_phone' => '+8801901856510',
                'order_prefix' => 'DTX',
                'default_currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'maintenance_mode' => false,
                'maintenance_message' => 'We are performing a quick update. Please check back shortly.',
                'maintenance_until' => null,
                'invoice_note' => 'Thank you for shopping with Digitrix Labs.',
                'cookie_consent' => [
                    'enabled' => false,
                    'message' => 'We use cookies to improve your shopping experience, keep your cart working, and understand website performance.',
                    'accept_label' => 'Accept Cookies',
                    'close_label' => 'Close',
                    'policy_label' => 'Cookies Policy',
                    'policy_url' => '/cookies',
                ],
            ],
            'fraud_checker' => [
                'enabled' => false,
                'provider' => 'onesoftcode',
                'api_key' => '',
                'onesoftcode_api_key' => '',
                'bd_courier_api_key' => '',
                'api_url' => 'https://fraudchecker.ocs-api.top/api/v3',
                'bd_courier_api_url' => 'https://api.bdcourier.com',
                'couriers' => [
                    'pathao' => true,
                    'steadfast' => true,
                    'parceldex' => true,
                    'redx' => true,
                    'paperfly' => true,
                    'carrybee' => true,
                ],
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
                'protect_incomplete_orders' => true,
                'cooldown_minutes' => 180,
                'message' => 'You can place another order after {{time}}.',
            ],
            'ai_calling' => [
                'enabled' => false,
                'api_base_url' => 'https://digitrixlabs.epbx.bd/api/v1',
                'api_token' => '',
                'store_name' => 'Shirin Fashion',
                'caller_id' => '',
                'agent_extension' => '',
                'cod_only' => true,
                'confirmed_status' => 'confirmed',
                'rejected_status' => 'cancelled',
                'custom_text' => 'Hello {{customer_name}}, this is an automated confirmation call from {{store_name}}. You ordered {{product_names}}. Your order amount is {{amount}}. To confirm your order, press 1. To cancel your order, press 2.',
                'confirm_text' => 'Your order has been confirmed successfully. Thank you.',
                'cancel_text' => 'Your order has been cancelled. Thank you.',
                'request_timeout' => 20,
                'webhook_base_url' => '',
            ],
            'customer_auth' => [
                'google_login_enabled' => false,
                'google_client_id' => '',
                'google_android_client_id' => '',
            ],
            'payment_gateway' => [
                'enabled' => false,
                'store_id' => '',
                'store_password' => '',
                'sandbox' => true,
                'currency' => 'BDT',
                'frontend_url' => '',
                'callback_base_url' => '',
            ],
            'mobile_push' => [
                'firebase_project_id' => '',
                'firebase_client_email' => '',
                'firebase_private_key' => '',
                'app_update_enabled' => true,
                'latest_version' => '0.1.7',
                'latest_build_number' => 10,
                'minimum_build_number' => 9,
                'update_url' => 'https://play.google.com/store/apps/details?id=com.shirinfashion.app',
                'update_title' => 'New app update available',
                'update_message' => 'A newer Shirin Fashion app version is available. Update now for the best shopping experience.',
                'critical_update_title' => 'Update required',
                'critical_update_message' => 'This app version is no longer supported. Please update to continue shopping.',
                'update_reminder_hours' => 24,
                'cart_reminder_enabled' => true,
                'cart_reminder_delay_minutes' => 120,
                'cart_reminder_repeat_hours' => 24,
                'cart_reminder_max_reminders' => 2,
                'cart_reminder_title' => 'Your cart is waiting',
                'cart_reminder_body' => 'You left {count} item(s) in your Shirin Fashion cart.',
            ],
            'product_page' => [
                'reviewSettings' => [
                    'enableReviews' => true,
                    'showAverageRating' => true,
                    'allowGuestReviews' => true,
                ],
                'shippingMethods' => [
                    [
                        'id' => 1,
                        'name' => 'Inside Dhaka',
                        'description' => 'Delivery within 1-2 days inside Dhaka city',
                        'cost' => 80,
                        'isActive' => true,
                    ],
                    [
                        'id' => 2,
                        'name' => 'Outside Dhaka',
                        'description' => 'Delivery within 2-3 days outside Dhaka',
                        'cost' => 120,
                        'isActive' => true,
                    ],
                ],
                'freeShippingEnabled' => false,
                'freeShippingThreshold' => '0',
                'paymentMethods' => [
                    [
                        'id' => 'cod',
                        'name' => 'Cash on Delivery',
                        'description' => 'Pay after delivery confirmation at your doorstep.',
                        'active' => true,
                    ],
                    [
                        'id' => 'sslcommerz',
                        'name' => 'SSLCommerz',
                        'description' => 'Pay securely online with card, mobile banking, or internet banking.',
                        'active' => false,
                    ],
                    [
                        'id' => 'stripe',
                        'name' => 'Stripe',
                        'description' => 'Secure card checkout powered by Stripe.',
                        'active' => false,
                    ],
                    [
                        'id' => 'paypal',
                        'name' => 'PayPal',
                        'description' => 'Pay quickly with your PayPal balance or linked cards.',
                        'active' => false,
                    ],
                ],
                'taxSettings' => [
                    'enabled' => false,
                    'name' => 'VAT',
                    'type' => 'percentage',
                    'value' => '0',
                ],
                'cartDrawerStyle' => 'style-1',
                'mobileStickyProductActions' => true,
                'trustBadges' => [
                    'enabled' => true,
                    'items' => [
                        [
                            'id' => 'customers',
                            'icon' => 'star',
                            'title' => '1200+',
                            'subtitle' => 'Satisfied customers',
                            'active' => true,
                        ],
                        [
                            'id' => 'delivery',
                            'icon' => 'truck',
                            'title' => 'All Bangladesh',
                            'subtitle' => 'Home delivery',
                            'active' => true,
                        ],
                        [
                            'id' => 'cod',
                            'icon' => 'cash',
                            'title' => 'Cash On Delivery',
                            'subtitle' => 'Available',
                            'active' => true,
                        ],
                    ],
                ],
                'abandonedCheckoutCoupon' => [
                    'enabled' => false,
                    'couponCode' => '',
                    'eyebrow' => 'Private checkout offer',
                    'title' => 'Wait, your order is almost ready',
                    'message' => 'Use this coupon now and enjoy a special saving before you leave.',
                    'buttonLabel' => 'Copy Coupon',
                    'closeLabel' => 'No thanks',
                    'countdownMinutes' => 15,
                ],
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
            'database_backup' => [
                'enabled' => true,
                'monthly_backup_enabled' => true,
                'monthly_day' => 1,
                'monthly_time' => '03:00',
                'retention_months' => 6,
                'notification_email' => '',
                'backup_disk' => 'local',
                'compress' => true,
                'restore_enabled' => true,
                'download_link_ttl_days' => 7,
                'public_download_base_url' => '',
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
                'enable_order_notification_sms' => true,
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
