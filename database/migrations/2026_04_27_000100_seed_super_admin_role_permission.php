<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('admin_roles')->updateOrInsert(
            ['slug' => 'super-admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Default system role with full access to every admin feature.',
                'is_system' => true,
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('admin_permissions')->updateOrInsert(
            ['slug' => 'system.everything'],
            [
                'name' => 'Can do everything',
                'group' => 'system',
                'description' => 'Allows full access to all current and future admin capabilities.',
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $roleId = DB::table('admin_roles')->where('slug', 'super-admin')->value('id');
        $permissionId = DB::table('admin_permissions')->where('slug', 'system.everything')->value('id');

        if ($roleId && $permissionId) {
            DB::table('admin_permission_role')->updateOrInsert(
                [
                    'admin_role_id' => $roleId,
                    'admin_permission_id' => $permissionId,
                ],
                [
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('admin_roles')->where('slug', 'super-admin')->value('id');
        $permissionId = DB::table('admin_permissions')->where('slug', 'system.everything')->value('id');

        if ($roleId && $permissionId) {
            DB::table('admin_permission_role')
                ->where('admin_role_id', $roleId)
                ->where('admin_permission_id', $permissionId)
                ->delete();
        }

        DB::table('admin_permissions')->where('slug', 'system.everything')->delete();
        DB::table('admin_roles')->where('slug', 'super-admin')->delete();
    }
};
