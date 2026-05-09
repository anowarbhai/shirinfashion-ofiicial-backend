<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY short_description TEXT NULL');

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->text('short_description')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY short_description VARCHAR(255) NULL');

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('short_description')->nullable()->change();
        });
    }
};
