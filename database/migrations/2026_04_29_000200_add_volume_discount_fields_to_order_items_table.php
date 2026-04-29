<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'volume_discount_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                // Some shared MySQL hosts reject adding this FK to an existing busy table.
                // The app still validates tier ownership and existence before order creation.
                $table->unsignedBigInteger('volume_discount_id')->nullable()->after('product_id');
                $table->index('volume_discount_id', 'order_items_volume_discount_id_index');
            });
        }

        if (! Schema::hasColumn('order_items', 'is_free_gift')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->boolean('is_free_gift')->default(false)->after('line_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'volume_discount_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropIndex('order_items_volume_discount_id_index');
                $table->dropColumn('volume_discount_id');
            });
        }

        if (Schema::hasColumn('order_items', 'is_free_gift')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('is_free_gift');
            });
        }
    }
};
