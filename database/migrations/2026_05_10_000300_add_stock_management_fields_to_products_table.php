<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'manage_stock')) {
                $table->boolean('manage_stock')->default(true)->after('inventory');
            }

            if (! Schema::hasColumn('products', 'stock_status')) {
                $table->string('stock_status', 30)->default('in_stock')->after('manage_stock');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'stock_status')) {
                $table->dropColumn('stock_status');
            }

            if (Schema::hasColumn('products', 'manage_stock')) {
                $table->dropColumn('manage_stock');
            }
        });
    }
};
