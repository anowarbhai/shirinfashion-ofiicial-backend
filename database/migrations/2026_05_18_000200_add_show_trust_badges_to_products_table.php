<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'show_trust_badges')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('show_trust_badges')->default(true)->after('hide_from_storefront');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'show_trust_badges')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('show_trust_badges');
            });
        }
    }
};
