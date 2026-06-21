<?php

namespace App\Providers;

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
            $ip = $request->ip();

            return [
                Limit::perMinute(5)->by('auth:'.sha1($identifier)),
                Limit::perHour(20)->by('auth-hour:'.sha1($identifier)),
                Limit::perHour(300)->by('auth-ip:'.$ip),
            ];
        });

        RateLimiter::for('otp-send', function (Request $request): array {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone')) ?: 'unknown';
            $ip = $request->ip();

            return [
                Limit::perMinute(2)->by('otp-phone:'.$phone),
                Limit::perHour(10)->by('otp-phone-hour:'.$phone),
                Limit::perHour(300)->by('otp-ip:'.$ip),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request): array {
            $session = (string) ($request->input('otp_session_token') ?? 'unknown');
            $ip = $request->ip();

            return [
                Limit::perMinute(10)->by('otp-verify:'.sha1($session)),
                Limit::perHour(60)->by('otp-verify-hour:'.sha1($session)),
                Limit::perHour(300)->by('otp-verify-ip:'.$ip),
            ];
        });

        RateLimiter::for('checkout', fn (Request $request): array => [
            Limit::perMinute(60)->by('checkout:'.$request->ip()),
            Limit::perHour(1000)->by('checkout-hour:'.$request->ip()),
        ]);

        RateLimiter::for('order-track', function (Request $request): array {
            $target = strtolower(trim((string) (
                $request->input('order_number')
                ?? $request->input('tracking_number')
                ?? 'unknown'
            )));

            return [
                Limit::perMinute(5)->by('track-target:'.sha1($target)),
                Limit::perHour(30)->by('track-target-hour:'.sha1($target)),
                Limit::perHour(300)->by('track-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public-write', fn (Request $request): array => [
            Limit::perMinute(60)->by('public-write:'.$request->ip()),
            Limit::perHour(500)->by('public-write-hour:'.$request->ip()),
        ]);

        RateLimiter::for('mobile-sync', function (Request $request): array {
            $device = (string) ($request->input('device_id') ?? 'unknown');

            return [
                Limit::perMinute(60)->by('mobile-sync:'.sha1($device)),
                Limit::perHour(1000)->by('mobile-sync-ip:'.$request->ip()),
            ];
        });
    }
}
