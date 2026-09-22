<?php

namespace Tests\Feature;

use App\Models\SmsOtp;
use App\Models\User;
use App\Services\AdminSettingsService;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_mobile_customer_can_request_password_reset_otp(): void
    {
        $this->enableSms();
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '01900000003',
        ]);

        $response = $this->postJson('/api/v1/mobile/auth/password/forgot', [
            'phone' => '01900000003',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.phone_masked', '*******0003')
            ->assertJsonStructure(['data' => ['otp_session_token', 'expires_in_seconds']]);

        $this->assertDatabaseHas('sms_otps', [
            'purpose' => 'customer_password_reset',
            'user_id' => $customer->id,
            'phone' => '01900000003',
        ]);
    }

    public function test_mobile_customer_can_reset_password_with_verified_otp(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '01900000004',
            'password' => 'old-password-123',
            'auth_token_version' => 2,
        ]);
        SmsOtp::query()->create([
            'session_token' => 'password-reset-session',
            'purpose' => 'customer_password_reset',
            'user_id' => $customer->id,
            'phone' => '01900000004',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/v1/mobile/auth/password/reset', [
            'phone' => '01900000004',
            'otp_session_token' => 'password-reset-session',
            'code' => '123456',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $customer->refresh();
        $this->assertTrue(Hash::check('new-password-456', $customer->password));
        $this->assertSame(3, $customer->auth_token_version);
        $this->assertNotNull(SmsOtp::query()->firstOrFail()->consumed_at);
    }

    public function test_password_reset_request_does_not_reveal_unknown_phone(): void
    {
        $this->enableSms();

        $this->postJson('/api/v1/mobile/auth/password/forgot', [
            'phone' => '01900000005',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['otp_session_token', 'phone_masked']]);

        $this->assertDatabaseCount('sms_otps', 0);
    }

    private function enableSms(): void
    {
        app(AdminSettingsService::class)->saveGroup('sms_integration', [
            'enabled' => true,
            'provider' => 'onecodesoft',
            'api_key' => 'test-key',
            'sender_id' => 'TEST',
            'base_url' => 'https://sms.example.test',
        ]);
        Http::fake([
            '*' => Http::response(['response_code' => '202'], 200),
        ]);
    }

    protected function authenticatedAs(User $user): self
    {
        $token = app(JwtService::class)->issueToken($user);

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
