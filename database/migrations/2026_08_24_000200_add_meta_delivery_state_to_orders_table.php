<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('meta_campaign_facebook_pixel_ids')->nullable()->after('meta_fbc');
            $table->unsignedSmallInteger('meta_purchase_attempts')->default(0)->after('meta_user_agent');
            $table->timestamp('meta_purchase_last_attempt_at')->nullable()->after('meta_purchase_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'meta_campaign_facebook_pixel_ids',
                'meta_purchase_attempts',
                'meta_purchase_last_attempt_at',
            ]);
        });
    }
};
