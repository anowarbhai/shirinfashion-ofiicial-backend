<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if ($this->indexExists('users', 'users_phone_unique')) {
            DB::statement('ALTER TABLE `users` DROP INDEX `users_phone_unique`');
        }

        if (! $this->indexExists('users', 'users_role_phone_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique(['role', 'phone'], 'users_role_phone_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if ($this->indexExists('users', 'users_role_phone_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_role_phone_unique');
            });
        }

        if (! $this->indexExists('users', 'users_phone_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('phone', 'users_phone_unique');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?',
            [$index],
        )) > 0;
    }
};
