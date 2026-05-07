<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return array<int, array{name: string, slug: string, group: string, description: string}>
     */
    private function permissions(): array
    {
        return [
            [
                'name' => 'View all audit logs',
                'slug' => 'audit.view.all',
                'group' => 'team',
                'description' => 'View every admin activity log instead of only your own activity.',
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions() as $permission) {
            DB::table('admin_permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [
                    ...$permission,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $adminRoleId = DB::table('admin_roles')->where('slug', 'admin')->value('id');
        $permissionIds = DB::table('admin_permissions')
            ->whereIn('slug', collect($this->permissions())->pluck('slug')->all())
            ->pluck('id');

        if ($adminRoleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('admin_permission_role')->updateOrInsert(
                    ['admin_role_id' => $adminRoleId, 'admin_permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionSlugs = collect($this->permissions())->pluck('slug')->all();
        $permissionIds = DB::table('admin_permissions')->whereIn('slug', $permissionSlugs)->pluck('id');

        DB::table('admin_permission_role')->whereIn('admin_permission_id', $permissionIds)->delete();
        DB::table('admin_permissions')->whereIn('slug', $permissionSlugs)->delete();
    }
};
