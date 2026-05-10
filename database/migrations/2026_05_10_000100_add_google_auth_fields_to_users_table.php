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
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->after('email');
            }
        });

        if (DB::getDriverName() !== 'sqlite' && ! $this->indexExists('users', 'users_google_id_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('google_id', 'users_google_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite' && $this->indexExists('users', 'users_google_id_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_google_id_unique');
            });
        }

        if (Schema::hasColumn('users', 'google_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('google_id');
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
