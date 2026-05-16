<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'first_order_only')) {
                $table->boolean('first_order_only')->default(false)->after('individual_use');
            }

            if (! Schema::hasColumn('coupons', 'registered_customer_only')) {
                $table->boolean('registered_customer_only')->default(false)->after('first_order_only');
            }

            if (! Schema::hasColumn('coupons', 'mobile_app_only')) {
                $table->boolean('mobile_app_only')->default(false)->after('registered_customer_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            foreach (['mobile_app_only', 'registered_customer_only', 'first_order_only'] as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
