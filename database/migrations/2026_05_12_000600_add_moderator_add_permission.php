<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('admin_permissions')->updateOrInsert(
            ['slug' => 'moderator.add_moderator'],
            [
                'name' => 'Add moderators',
                'slug' => 'moderator.add_moderator',
                'group' => 'moderator',
                'description' => 'Create new moderator profiles.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('admin_permissions')->where('slug', 'moderator.add_moderator')->value('id');

        if (! $permissionId) {
            return;
        }

        foreach (['super-admin', 'admin'] as $roleSlug) {
            $roleId = DB::table('admin_roles')->where('slug', $roleSlug)->value('id');

            if (! $roleId) {
                continue;
            }

            DB::table('admin_permission_role')->updateOrInsert(
                ['admin_role_id' => $roleId, 'admin_permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('admin_permissions')->where('slug', 'moderator.add_moderator')->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('admin_permission_role')->where('admin_permission_id', $permissionId)->delete();
        DB::table('admin_permissions')->where('id', $permissionId)->delete();
    }
};
