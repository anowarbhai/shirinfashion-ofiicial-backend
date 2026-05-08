<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role')->default('customer')->index()->after('phone');
            });
        }

        if (! Schema::hasColumn('users', 'avatar_url')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->longText('avatar_url')->nullable()->after('role');
            });
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->longText('avatar_url')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('users', 'marketing_opt_in')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('marketing_opt_in')->default(false)->after('avatar_url');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'avatar_url')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_url')->nullable()->change();
        });
    }
};
