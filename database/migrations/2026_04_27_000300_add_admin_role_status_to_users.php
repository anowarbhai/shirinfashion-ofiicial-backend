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
            $table->foreignId('admin_role_id')
                ->nullable()
                ->after('role')
                ->constrained('admin_roles')
                ->nullOnDelete();
            $table->string('status')->default('active')->after('admin_role_id')->index();
        });

        $superAdminRoleId = DB::table('admin_roles')->where('slug', 'super-admin')->value('id');
        $adminRoleId = DB::table('admin_roles')->where('slug', 'admin')->value('id');

        DB::table('users')
            ->where('role', 'admin')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $user, int $index) use ($superAdminRoleId, $adminRoleId): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'admin_role_id' => $index === 0 ? ($superAdminRoleId ?: $adminRoleId) : $adminRoleId,
                        'status' => 'active',
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('admin_role_id');
            $table->dropColumn('status');
        });
    }
};
