<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->text('description')->nullable()->after('value');
            $table->decimal('maximum_order_amount', 10, 2)->nullable()->after('minimum_order_amount');
            $table->boolean('free_shipping')->default(false)->after('ends_at');
            $table->boolean('individual_use')->default(false)->after('free_shipping');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'maximum_order_amount',
                'free_shipping',
                'individual_use',
            ]);
        });
    }
};
