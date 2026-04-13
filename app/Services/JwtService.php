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
        $ttlMinutes = (int) env('JWT_TTL_MINUTES', 1440);

        $payload = [
            'iss' => config('app.url', 'shirinfashionbd-api'),
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => $issuedAt,
            'exp' => $issuedAt + ($ttlMinutes * 60),
        ];

        return JWT::encode($payload, $this->secret(), 'HS256');
    }

    public function decode(string $token): stdClass
    {
        return JWT::decode($token, new Key($this->secret(), 'HS256'));
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
