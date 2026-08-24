<?php

namespace Tests\Feature;

use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\AdminSettingsService;
use App\Services\JwtService;
use App\Support\SensitiveSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensitiveSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_settings_are_encrypted_at_rest_and_revealed_to_the_service(): void
    {
        $service = app(AdminSettingsService::class);
        $service->saveGroup('mail_setup', [
            'enabled' => true,
            'smtp_password' => 'top-secret-password',
        ]);

        $stored = StorefrontSetting::query()
            ->where('key', 'settings.mail_setup')
            ->firstOrFail()
            ->value;

        $this->assertStringStartsWith('encrypted:v1:', $stored['smtp_password']);
        $this->assertStringNotContainsString('top-secret-password', $stored['smtp_password']);
        $this->assertSame('top-secret-password', $service->getGroup('mail_setup')['smtp_password']);
    }

    public function test_facebook_tokens_are_never_returned_to_the_admin_browser_and_blank_updates_preserve_them(): void
    {
        StorefrontSetting::query()->create([
            'key' => 'facebook_marketing',
            'value' => SensitiveSettings::protectFacebook([
                'pixel_enabled' => true,
                'pixel_id' => '111111111111111',
                'capi_enabled' => true,
                'access_token' => 'global-secret',
                'test_event_code' => '',
                'campaign_pixels' => [[
                    'id' => 'campaign-one',
                    'name' => 'Campaign One',
                    'pixel_id' => '222222222222222',
                    'capi_enabled' => true,
                    'access_token' => 'campaign-secret',
                    'test_event_code' => '',
                    'enabled' => true,
                ]],
            ]),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = app(JwtService::class)->issueToken($admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/marketing/facebook')
            ->assertOk()
            ->assertJsonPath('data.access_token', '')
            ->assertJsonPath('data.has_access_token', true)
            ->assertJsonPath('data.campaign_pixels.0.access_token', '')
            ->assertJsonPath('data.campaign_pixels.0.has_access_token', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/admin/marketing/facebook', [
                'pixel_enabled' => true,
                'pixel_id' => '111111111111111',
                'capi_enabled' => true,
                'access_token' => '',
                'test_event_code' => '',
                'campaign_pixels' => [[
                    'id' => 'campaign-one',
                    'name' => 'Campaign One Updated',
                    'pixel_id' => '222222222222222',
                    'capi_enabled' => true,
                    'access_token' => '',
                    'test_event_code' => '',
                    'enabled' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.access_token', '')
            ->assertJsonPath('data.campaign_pixels.0.access_token', '');

        $stored = StorefrontSetting::query()->where('key', 'facebook_marketing')->value('value');
        $revealed = SensitiveSettings::revealFacebook($stored);
        $this->assertSame('global-secret', $revealed['access_token']);
        $this->assertSame('campaign-secret', $revealed['campaign_pixels'][0]['access_token']);
    }
}
