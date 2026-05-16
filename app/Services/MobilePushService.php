<?php

namespace App\Services;

use App\Models\MobileDeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MobilePushService
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        $tokens = MobileDeviceToken::query()
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->pluck('token')
            ->all();

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendCampaign(string $title, string $body, string $target = 'all', array $data = []): array
    {
        $query = MobileDeviceToken::query()->where('enabled', true);

        if ($target === 'guests') {
            $query->whereNull('user_id');
        } elseif ($target === 'customers') {
            $query->whereNotNull('user_id');
        }

        return $this->sendToTokens($query->pluck('token')->all(), $title, $body, $data);
    }

    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = collect($tokens)
            ->filter(fn ($token): bool => is_string($token) && $token !== '')
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return [
                'configured' => $this->isConfigured(),
                'requested' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => true,
                'message' => 'No active mobile device tokens found.',
            ];
        }

        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'requested' => $tokens->count(),
                'sent' => 0,
                'failed' => 0,
                'skipped' => true,
                'message' => 'Firebase Cloud Messaging is not configured.',
            ];
        }

        $accessToken = $this->accessToken();
        $projectId = $this->projectId();
        $sent = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $this->stringData($data),
                        'android' => [
                            'priority' => 'HIGH',
                            'notification' => [
                                'channel_id' => 'shirin_customer_updates',
                                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $sent++;
                continue;
            }

            $failed++;
            if ($response->status() === 404 || $response->status() === 400) {
                MobileDeviceToken::query()
                    ->where('token', $token)
                    ->update(['enabled' => false]);
            }
        }

        return [
            'configured' => true,
            'requested' => $tokens->count(),
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => false,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->projectId() !== ''
            && $this->clientEmail() !== ''
            && $this->privateKey() !== '';
    }

    private function accessToken(): string
    {
        return Cache::remember('mobile_push.fcm_access_token', now()->addMinutes(50), function (): string {
            $now = time();
            $jwt = $this->jwt([
                'iss' => $this->clientEmail(),
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            $response = Http::asForm()
                ->timeout(10)
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Unable to authenticate with Firebase Cloud Messaging.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function jwt(array $claims): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '{}');
        $payload = $this->base64UrlEncode(json_encode($claims) ?: '{}');
        $input = "{$header}.{$payload}";

        $key = $this->privateKey();
        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Firebase service account JWT.');
        }

        return $input.'.'.$this->base64UrlEncode($signature);
    }

    private function stringData(array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key): bool => is_string($key) && $value !== null)
            ->map(fn ($value): string => is_scalar($value) ? (string) $value : (json_encode($value) ?: ''))
            ->all();
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function projectId(): string
    {
        return $this->setting('project_id', 'FIREBASE_PROJECT_ID');
    }

    private function clientEmail(): string
    {
        return $this->setting('client_email', 'FIREBASE_CLIENT_EMAIL');
    }

    private function privateKey(): string
    {
        return Str::replace('\n', "\n", $this->setting('private_key', 'FIREBASE_PRIVATE_KEY'));
    }

    private function setting(string $key, string $env): string
    {
        return trim((string) ($this->settings->getSetting("mobile_push.firebase_{$key}") ?: env($env, '')));
    }
}
