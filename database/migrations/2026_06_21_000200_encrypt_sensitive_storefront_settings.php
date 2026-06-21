<?php

use App\Support\SensitiveSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $groups = ['fraud_checker', 'mail_setup', 'mobile_push', 'sms_integration'];

        foreach ($groups as $group) {
            $this->updateJsonSetting(
                'settings.'.$group,
                fn (array $settings): array => SensitiveSettings::protectGroup($group, $settings),
            );
        }

        $this->updateJsonSetting(
            'facebook_marketing',
            fn (array $settings): array => SensitiveSettings::protectFacebook($settings),
        );
    }

    public function down(): void
    {
        $groups = ['fraud_checker', 'mail_setup', 'mobile_push', 'sms_integration'];

        foreach ($groups as $group) {
            $this->updateJsonSetting(
                'settings.'.$group,
                fn (array $settings): array => SensitiveSettings::revealGroup($group, $settings),
            );
        }

        $this->updateJsonSetting(
            'facebook_marketing',
            fn (array $settings): array => SensitiveSettings::revealFacebook($settings),
        );
    }

    private function updateJsonSetting(string $key, callable $transform): void
    {
        $stored = DB::table('storefront_settings')->where('key', $key)->value('value');

        if (! is_string($stored) || $stored === '') {
            return;
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded)) {
            return;
        }

        DB::table('storefront_settings')
            ->where('key', $key)
            ->update(['value' => json_encode($transform($decoded), JSON_UNESCAPED_SLASHES)]);
    }
};
