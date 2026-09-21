<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ClientIp
{
    public static function resolve(Request $request): ?string
    {
        $remoteAddress = self::validIp($request->server('REMOTE_ADDR'));

        if (! $remoteAddress) {
            return null;
        }

        $serverAddress = self::validIp($request->server('SERVER_ADDR'));

        if (! self::isTrustedProxy($remoteAddress, $serverAddress)) {
            return $remoteAddress;
        }

        foreach (['x-forwarded-for', 'x-real-ip'] as $header) {
            foreach (explode(',', (string) $request->header($header, '')) as $candidate) {
                $candidate = self::validIp(trim($candidate));

                if ($candidate) {
                    return $candidate;
                }
            }
        }

        return $remoteAddress;
    }

    private static function isTrustedProxy(string $remoteAddress, ?string $serverAddress): bool
    {
        if ($serverAddress && hash_equals($serverAddress, $remoteAddress)) {
            return true;
        }

        if (self::isPrivateOrLoopback($remoteAddress)) {
            return true;
        }

        $configured = config('app.checkout_trusted_proxies', []);

        if (! is_array($configured)) {
            return false;
        }

        return in_array($remoteAddress, $configured, true);
    }

    private static function isPrivateOrLoopback(string $ip): bool
    {
        if ($ip === '::1' || str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd')) {
            return true;
        }

        if (str_starts_with(strtolower($ip), 'fe8') || str_starts_with(strtolower($ip), 'fe9') ||
            str_starts_with(strtolower($ip), 'fea') || str_starts_with(strtolower($ip), 'feb')) {
            return true;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($ip);

        return $long !== false && (
            ($long >= ip2long('10.0.0.0') && $long <= ip2long('10.255.255.255')) ||
            ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255')) ||
            ($long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255')) ||
            ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255'))
        );
    }

    private static function validIp(mixed $value): ?string
    {
        $ip = trim((string) $value);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
