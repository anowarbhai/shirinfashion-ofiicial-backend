<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('volume_discount_id')->nullable()->after('product_id')->constrained('product_volume_discounts')->nullOnDelete();
            $table->boolean('is_free_gift')->default(false)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('volume_discount_id');
            $table->dropColumn('is_free_gift');
        });
    }
};
