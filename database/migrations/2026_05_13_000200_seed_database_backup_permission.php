<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('admin_permissions')->updateOrInsert(
            ['slug' => 'settings.database-backup.manage'],
            [
                'name' => 'Manage database backups',
                'slug' => 'settings.database-backup.manage',
                'group' => 'settings',
                'description' => 'Create, download, schedule, and restore database backups.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $adminRoleId = DB::table('admin_roles')->where('slug', 'admin')->value('id');
        $permissionId = DB::table('admin_permissions')->where('slug', 'settings.database-backup.manage')->value('id');

        if ($adminRoleId && $permissionId) {
            DB::table('admin_permission_role')->updateOrInsert(
                ['admin_role_id' => $adminRoleId, 'admin_permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('admin_permissions')->where('slug', 'settings.database-backup.manage')->value('id');

        if ($permissionId) {
            DB::table('admin_permission_role')->where('admin_permission_id', $permissionId)->delete();
        }

        DB::table('admin_permissions')->where('slug', 'settings.database-backup.manage')->delete();
    }
};
