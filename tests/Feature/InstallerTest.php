<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    public function test_installer_status_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/installer/status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'installed',
                'lock_file',
                'environment_file_exists',
            ]);
    }

    public function test_installer_requirements_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/installer/requirements');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'php' => ['current', 'minimum', 'satisfied'],
                'extensions',
                'directories',
                'can_install',
            ]);
    }

    public function test_uninstalled_system_redirects_home_to_install_page(): void
    {
        $lockFile = storage_path('installed');
        if (File::exists($lockFile)) {
            File::delete($lockFile);
        }

        $response = $this->get('/');
        $response->assertRedirect('/install');
    }
}
