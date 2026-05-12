<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_volume_discounts', 'extra_unit_price')) {
            Schema::table('product_volume_discounts', function (Blueprint $table) {
                $table->decimal('extra_unit_price', 12, 2)->nullable()->after('flat_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_volume_discounts', 'extra_unit_price')) {
            Schema::table('product_volume_discounts', function (Blueprint $table) {
                $table->dropColumn('extra_unit_price');
            });
        }
    }
};
