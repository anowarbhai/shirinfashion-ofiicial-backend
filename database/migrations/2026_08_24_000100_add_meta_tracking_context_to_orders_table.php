<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'meta_fbp')) {
                $table->string('meta_fbp', 255)->nullable()->after('utm_source');
            }

            if (! Schema::hasColumn('orders', 'meta_fbc')) {
                $table->string('meta_fbc', 255)->nullable()->after('meta_fbp');
            }

            if (! Schema::hasColumn('orders', 'meta_event_source_url')) {
                $table->text('meta_event_source_url')->nullable()->after('meta_fbc');
            }

            if (! Schema::hasColumn('orders', 'meta_landing_page_slug')) {
                $table->string('meta_landing_page_slug', 180)->nullable()->after('meta_event_source_url');
            }

            if (! Schema::hasColumn('orders', 'meta_user_agent')) {
                $table->text('meta_user_agent')->nullable()->after('meta_landing_page_slug');
            }

            if (! Schema::hasColumn('orders', 'meta_purchase_sent_at')) {
                $table->timestamp('meta_purchase_sent_at')->nullable()->after('meta_user_agent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            foreach ([
                'meta_purchase_sent_at',
                'meta_user_agent',
                'meta_landing_page_slug',
                'meta_event_source_url',
                'meta_fbc',
                'meta_fbp',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
