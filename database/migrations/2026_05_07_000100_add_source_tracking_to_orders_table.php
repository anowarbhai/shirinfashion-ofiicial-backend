<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_source')->nullable()->index()->after('device_id');
            $table->string('order_source_detail')->nullable()->after('order_source');
            $table->text('referrer_url')->nullable()->after('order_source_detail');
            $table->string('utm_source')->nullable()->index()->after('referrer_url');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'order_source',
                'order_source_detail',
                'referrer_url',
                'utm_source',
            ]);
        });
    }
};
