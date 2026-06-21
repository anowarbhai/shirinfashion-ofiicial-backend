<?php

namespace Tests\Feature;

use App\Models\StorefrontSetting;
use App\Services\AdminSettingsService;
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
}
