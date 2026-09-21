<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request): array {
            $identifier = strtolower(trim((string) (
                $request->input('identifier')
                ?? $request->input('phone')
                ?? $request->input('email')
                ?? 'unknown'
            )));
            $ip = ClientIp::resolve($request) ?? 'unknown';

            return [
                Limit::perMinute(5)->by('auth:'.sha1($identifier)),
                Limit::perHour(20)->by('auth-hour:'.sha1($identifier)),
                Limit::perHour(300)->by('auth-ip:'.$ip),
            ];
        });

        RateLimiter::for('otp-send', function (Request $request): array {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone')) ?: 'unknown';
            $ip = ClientIp::resolve($request) ?? 'unknown';

            return [
                Limit::perMinute(2)->by('otp-phone:'.$phone),
                Limit::perHour(10)->by('otp-phone-hour:'.$phone),
                Limit::perHour(300)->by('otp-ip:'.$ip),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request): array {
            $session = (string) ($request->input('otp_session_token') ?? 'unknown');
            $ip = ClientIp::resolve($request) ?? 'unknown';

            return [
                Limit::perMinute(10)->by('otp-verify:'.sha1($session)),
                Limit::perHour(60)->by('otp-verify-hour:'.sha1($session)),
                Limit::perHour(300)->by('otp-verify-ip:'.$ip),
            ];
        });

        RateLimiter::for('checkout-submit', function (Request $request): array {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone')) ?: '';
            $device = trim((string) ($request->input('device_id') ?: $request->input('cart_session_id')));
            $ip = ClientIp::resolve($request) ?? 'unknown';

            if (str_starts_with($phone, '880') && strlen($phone) === 13) {
                $phone = '0'.substr($phone, 3);
            }

            $limits = [
                Limit::perMinute(20)->by('checkout-ip-minute:'.$ip),
                Limit::perHour(120)->by('checkout-ip-hour:'.$ip),
            ];

            if ($phone !== '') {
                $limits[] = Limit::perMinute(4)->by('checkout-phone-minute:'.sha1($phone));
                $limits[] = Limit::perHour(12)->by('checkout-phone-hour:'.sha1($phone));
            }

            if ($device !== '') {
                $limits[] = Limit::perMinute(8)->by('checkout-device-minute:'.sha1($device));
                $limits[] = Limit::perHour(30)->by('checkout-device-hour:'.sha1($device));
            }

            return $limits;
        });

        RateLimiter::for('checkout-draft', function (Request $request): array {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone')) ?: '';
            $device = trim((string) ($request->input('device_id') ?: $request->input('cart_session_id')));
            $ip = ClientIp::resolve($request) ?? 'unknown';

            if (str_starts_with($phone, '880') && strlen($phone) === 13) {
                $phone = '0'.substr($phone, 3);
            }

            $limits = [
                Limit::perMinute(120)->by('checkout-draft-ip-minute:'.$ip),
                Limit::perHour(1000)->by('checkout-draft-ip-hour:'.$ip),
            ];

            if ($phone !== '') {
                $limits[] = Limit::perMinute(30)->by('checkout-draft-phone-minute:'.sha1($phone));
                $limits[] = Limit::perHour(300)->by('checkout-draft-phone-hour:'.sha1($phone));
            }

            if ($device !== '') {
                $limits[] = Limit::perMinute(30)->by('checkout-draft-device-minute:'.sha1($device));
                $limits[] = Limit::perHour(300)->by('checkout-draft-device-hour:'.sha1($device));
            }

            return $limits;
        });

        RateLimiter::for('order-track', function (Request $request): array {
            $target = strtolower(trim((string) (
                $request->input('order_number')
                ?? $request->input('tracking_number')
                ?? 'unknown'
            )));

            return [
                Limit::perMinute(5)->by('track-target:'.sha1($target)),
                Limit::perHour(30)->by('track-target-hour:'.sha1($target)),
                Limit::perHour(300)->by('track-ip:'.(ClientIp::resolve($request) ?? 'unknown')),
            ];
        });

        RateLimiter::for('public-write', function (Request $request): array {
            $ip = ClientIp::resolve($request) ?? 'unknown';

            return [
                Limit::perMinute(60)->by('public-write:'.$ip),
                Limit::perHour(500)->by('public-write-hour:'.$ip),
            ];
        });

        RateLimiter::for('mobile-sync', function (Request $request): array {
            $device = (string) ($request->input('device_id') ?? 'unknown');

            return [
                Limit::perMinute(60)->by('mobile-sync:'.sha1($device)),
                Limit::perHour(1000)->by('mobile-sync-ip:'.(ClientIp::resolve($request) ?? 'unknown')),
            ];
        });
    }
}
