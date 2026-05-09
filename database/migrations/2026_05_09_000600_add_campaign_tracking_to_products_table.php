<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'campaign_facebook_pixel_ids')) {
                $table->json('campaign_facebook_pixel_ids')->nullable()->after('hide_from_storefront');
            }

            if (! Schema::hasColumn('products', 'campaign_google_tag_ids')) {
                $table->json('campaign_google_tag_ids')->nullable()->after('campaign_facebook_pixel_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'campaign_google_tag_ids')) {
                $table->dropColumn('campaign_google_tag_ids');
            }

            if (Schema::hasColumn('products', 'campaign_facebook_pixel_ids')) {
                $table->dropColumn('campaign_facebook_pixel_ids');
            }
        });
    }
};
