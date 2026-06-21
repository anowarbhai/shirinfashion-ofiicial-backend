<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtLogoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_the_current_users_existing_tokens(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
        ]);
        $token = app(JwtService::class)->issueToken($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk();
        $this->withHeaders($headers)->postJson('/api/auth/logout')->assertOk();
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_inactive_customer_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
        ]);
        $token = app(JwtService::class)->issueToken($user);
        $user->update(['status' => 'inactive']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
