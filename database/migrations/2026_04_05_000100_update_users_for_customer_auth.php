<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('phone')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('users', 'address')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->text('address')->nullable()->after('phone');
            });
        }

        if (DB::getDriverName() !== 'sqlite' && ! $this->indexExists('users', 'users_phone_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('phone', 'users_phone_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite' && $this->indexExists('users', 'users_phone_unique')) {
                $table->dropUnique('users_phone_unique');
            }

            if (Schema::hasColumn('users', 'address')) {
                $table->dropColumn('address');
            }

            $table->string('email')->nullable(false)->change();
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?',
            [$index],
        )) > 0;
    }
};
