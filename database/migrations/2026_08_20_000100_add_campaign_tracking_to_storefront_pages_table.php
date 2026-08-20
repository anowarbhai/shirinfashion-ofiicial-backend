<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('storefront_pages', 'campaign_facebook_pixel_ids')) {
                $table->json('campaign_facebook_pixel_ids')->nullable()->after('seo_description');
            }

            if (! Schema::hasColumn('storefront_pages', 'campaign_google_tag_ids')) {
                $table->json('campaign_google_tag_ids')->nullable()->after('campaign_facebook_pixel_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storefront_pages', function (Blueprint $table): void {
            if (Schema::hasColumn('storefront_pages', 'campaign_google_tag_ids')) {
                $table->dropColumn('campaign_google_tag_ids');
            }

            if (Schema::hasColumn('storefront_pages', 'campaign_facebook_pixel_ids')) {
                $table->dropColumn('campaign_facebook_pixel_ids');
            }
        });
    }
};
