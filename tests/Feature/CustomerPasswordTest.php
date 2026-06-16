<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_customer_can_set_first_password_without_current_password(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '01900000001',
            'google_id' => 'google-123',
            'password_set_at' => null,
        ]);

        $response = $this->authenticatedAs($customer)
            ->patchJson('/api/account/password', [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Password set successfully.')
            ->assertJsonPath('data.has_password', true);

        $customer->refresh();

        $this->assertNotNull($customer->password_set_at);
        $this->assertTrue(Hash::check('new-password-123', $customer->password));
    }

    public function test_customer_with_password_must_provide_current_password(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '01900000002',
            'password' => 'old-password-123',
            'password_set_at' => now(),
        ]);

        $this->authenticatedAs($customer)
            ->patchJson('/api/account/password', [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    protected function authenticatedAs(User $user): self
    {
        $token = app(JwtService::class)->issueToken($user);

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
