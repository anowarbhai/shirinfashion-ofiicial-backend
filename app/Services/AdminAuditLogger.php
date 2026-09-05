<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class AdminAuditLogger
{
    public function log(
        Request $request,
        string $action,
        string $description,
        ?object $subject = null,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        try {
            $actor ??= $request->user() instanceof User ? $request->user() : null;
            $actorRole = $actor?->adminRole?->name ?? ($actor?->role ? str($actor->role)->title()->toString() : null);

            $ipAddress = $this->resolveClientIp($request);
            $rawUserAgent = (string) ($request->header('user-agent') ?: $request->userAgent());
            $deviceInfo = $this->parseDeviceInfo($rawUserAgent);
            $location = $this->resolveLocation($request, $ipAddress);

            $metadata['device'] = $deviceInfo['device'];
            $metadata['device_type'] = $deviceInfo['device_type'];
            $metadata['os'] = $deviceInfo['os'];
            $metadata['browser'] = $deviceInfo['browser'];
            if ($location) {
                $metadata['location'] = $location;
            }

            AdminAuditLog::query()->create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_role' => $actorRole,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->id ?? null,
                'subject_name' => $subject?->name ?? $subject?->title ?? $subject?->order_number ?? null,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'ip_address' => $ipAddress,
                'user_agent' => substr($rawUserAgent, 0, 255),
                'device' => substr($deviceInfo['device'], 0, 120),
                'location' => $location ? substr($location, 0, 120) : null,
            ]);

            AdminAuditLog::query()
                ->where('created_at', '<', Carbon::now()->subDays(30))
                ->delete();
        } catch (Throwable) {
            // Audit logging should never block the admin action itself.
        }
    }

    public function resolveClientIp(Request $request): string
    {
        $headers = [
            'cf-connecting-ip',
            'x-real-ip',
            'x-client-ip',
            'true-client-ip',
        ];

        foreach ($headers as $header) {
            $value = $request->header($header);
            if ($value) {
                $clean = $this->sanitizeIp(trim((string) $value));
                if ($clean) {
                    return $clean;
                }
            }
        }

        $forwarded = $request->header('x-forwarded-for');
        if ($forwarded) {
            $ips = explode(',', (string) $forwarded);
            // Search for first valid public IP, else first valid IP
            $firstValid = null;
            foreach ($ips as $candidate) {
                $clean = $this->sanitizeIp(trim($candidate));
                if ($clean) {
                    if ($firstValid === null) {
                        $firstValid = $clean;
                    }
                    if ($this->isPublicIp($clean)) {
                        return $clean;
                    }
                }
            }
            if ($firstValid) {
                return $firstValid;
            }
        }

        $fallback = $this->sanitizeIp((string) $request->ip());
        return $fallback ?: '127.0.0.1';
    }

    public function parseDeviceInfo(?string $userAgent): array
    {
        if (! $userAgent) {
            return [
                'device' => 'Desktop',
                'os' => 'Unknown OS',
                'browser' => 'Unknown Browser',
                'device_type' => 'desktop',
            ];
        }

        $os = 'Unknown OS';
        $deviceType = 'desktop';

        if (preg_match('/windows nt 10/i', $userAgent)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/windows nt 6\.3/i', $userAgent)) {
            $os = 'Windows 8.1';
        } elseif (preg_match('/windows nt 6\.1/i', $userAgent)) {
            $os = 'Windows 7';
        } elseif (preg_match('/windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/iphone/i', $userAgent)) {
            $os = 'iOS';
            $deviceType = 'mobile';
        } elseif (preg_match('/ipad/i', $userAgent)) {
            $os = 'iPadOS';
            $deviceType = 'tablet';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
            $deviceType = preg_match('/mobile/i', $userAgent) ? 'mobile' : 'tablet';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/cros/i', $userAgent)) {
            $os = 'ChromeOS';
        }

        $browser = 'Unknown Browser';
        if (preg_match('/edg(?:e)?\/([\d\.]+)/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/opr\/([\d\.]+)/i', $userAgent)) {
            $browser = 'Opera';
        } elseif (preg_match('/samsungbrowser\/([\d\.]+)/i', $userAgent)) {
            $browser = 'Samsung Internet';
        } elseif (preg_match('/chrome\/([\d\.]+)/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox\/([\d\.]+)/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari\/([\d\.]+)/i', $userAgent)) {
            $browser = 'Safari';
        }

        $device = "{$os} • {$browser}";

        return [
            'device' => $device,
            'os' => $os,
            'browser' => $browser,
            'device_type' => $deviceType,
        ];
    }

    public function resolveLocation(Request $request, string $ip): ?string
    {
        // 1. Check Cloudflare location headers
        $cfCountryCode = strtoupper(trim((string) $request->header('cf-ipcountry')));
        $cfCity = trim((string) $request->header('cf-ipcity'));

        if ($cfCountryCode && $cfCountryCode !== 'XX' && $cfCountryCode !== 'T1') {
            $countryName = $this->countryCodeToName($cfCountryCode);
            if ($cfCity) {
                return "{$cfCity}, {$countryName}";
            }
            return $countryName;
        }

        // 2. Localhost & Private IPs
        if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.')) {
            return 'Localhost';
        }

        if (! $this->isPublicIp($ip)) {
            return 'Local Network';
        }

        // 3. Public IP geolocation via cached lookup
        try {
            return Cache::remember("ip_geo_v1_{$ip}", 86400, function () use ($ip) {
                $response = Http::timeout(1.2)->get("http://ip-api.com/json/{$ip}?fields=status,country,city,regionName");
                if ($response->successful()) {
                    $data = $response->json();
                    if (($data['status'] ?? '') === 'success' && ! empty($data['country'])) {
                        $city = trim((string) ($data['city'] ?? ''));
                        $country = trim((string) ($data['country'] ?? ''));
                        if ($city && $country) {
                            return "{$city}, {$country}";
                        }
                        return $country ?: null;
                    }
                }
                return null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    protected function sanitizeIp(string $ip): ?string
    {
        // Strip port if present e.g. 103.205.71.42:54321
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):\d+$/', $ip, $matches)) {
            $ip = $matches[1];
        }

        if ($ip === '::1') {
            return '127.0.0.1';
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    protected function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    protected function countryCodeToName(string $code): string
    {
        $map = [
            'BD' => 'Bangladesh',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'IN' => 'India',
            'PK' => 'Pakistan',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'QA' => 'Qatar',
            'KW' => 'Kuwait',
            'OM' => 'Oman',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'JP' => 'Japan',
            'CN' => 'China',
            'KR' => 'South Korea',
            'TH' => 'Thailand',
            'TR' => 'Turkey',
        ];

        return $map[$code] ?? $code;
    }
}
