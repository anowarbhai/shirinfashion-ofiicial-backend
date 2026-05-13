<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'per_user_limit')) {
                $table->unsignedInteger('per_user_limit')->nullable()->after('usage_limit');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'coupon_code')) {
                $table->string('coupon_code')->nullable()->after('discount_total')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'coupon_code')) {
                $table->dropColumn('coupon_code');
            }
        });

        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'per_user_limit')) {
                $table->dropColumn('per_user_limit');
            }
        });
    }
};
