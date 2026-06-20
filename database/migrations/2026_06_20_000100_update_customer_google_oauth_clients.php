<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WEB_CLIENT_ID = '440007634672-8n25cgifqh7kg37ofk703chtpagihmhd.apps.googleusercontent.com';

    private const ANDROID_CLIENT_ID = '440007634672-gotosqss78q65350fh11qtfk54hpkc1d.apps.googleusercontent.com';

    private const OLD_WEB_CLIENT_ID = '1020869753319-p8nna5ou6o9dc7t27aivdb7ptl3ah97p.apps.googleusercontent.com';

    private const OLD_ANDROID_CLIENT_ID = '1020869753319-a89oq3g6vu20kmkjskoe126nc8mor3tf.apps.googleusercontent.com';

    public function up(): void
    {
        $this->saveCustomerAuthSettings(self::WEB_CLIENT_ID, self::ANDROID_CLIENT_ID);
    }

    public function down(): void
    {
        $this->saveCustomerAuthSettings(self::OLD_WEB_CLIENT_ID, self::OLD_ANDROID_CLIENT_ID);
    }

    private function saveCustomerAuthSettings(string $webClientId, string $androidClientId): void
    {
        $key = 'settings.customer_auth';
        $stored = DB::table('storefront_settings')->where('key', $key)->value('value');
        $settings = is_string($stored) ? json_decode($stored, true) : [];

        if (! is_array($settings)) {
            $settings = [];
        }

        $settings = array_replace($settings, [
            'google_login_enabled' => true,
            'google_client_id' => $webClientId,
            'google_android_client_id' => $androidClientId,
        ]);

        DB::table('storefront_settings')->updateOrInsert(
            ['key' => $key],
            [
                'group' => 'settings.customer_auth',
                'value' => json_encode($settings, JSON_UNESCAPED_SLASHES),
                'type' => 'json',
                'is_public' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Cache::forget('admin.settings.customer_auth');
    }
};
