<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_repeated_tracking_attempts_are_rate_limited_per_order_target(): void
    {
        $payload = [
            'order_number' => 'SBA-NOT-A-REAL-ORDER',
            'phone' => '01700000000',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/orders/track', $payload)->assertNotFound();
        }

        $this->postJson('/api/orders/track', $payload)->assertTooManyRequests();
    }
}
