<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

class JwtService
{
    public function issueToken(User $user): string
    {
        $issuedAt = time();
        $ttlMinutes = $this->ttlMinutes($user);

        $payload = [
            'iss' => config('app.url', 'shirinfashionbd-api'),
            'sub' => $user->id,
            'role' => $user->role,
            'ver' => (int) ($user->auth_token_version ?? 0),
            'iat' => $issuedAt,
            'exp' => $issuedAt + ($ttlMinutes * 60),
        ];

        return JWT::encode($payload, $this->secret(), 'HS256');
    }

    public function decode(string $token): stdClass
    {
        return JWT::decode($token, new Key($this->secret(), 'HS256'));
    }

    protected function ttlMinutes(User $user): int
    {
        if ($user->role === 'customer') {
            return max(1, (int) env('CUSTOMER_JWT_TTL_MINUTES', 525600));
        }

        return max(1, (int) env('JWT_TTL_MINUTES', 1440));
    }

    protected function secret(): string
    {
        $secret = env('JWT_SECRET');

        if ($secret) {
            return $secret;
        }

        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            return base64_decode(substr($appKey, 7)) ?: $appKey;
        }

        return $appKey;
    }
}
