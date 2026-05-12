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
            ['name' => 'View assigned orders', 'slug' => 'moderator.view_assigned_orders', 'group' => 'moderator', 'description' => 'View orders assigned to the current moderator.'],
            ['name' => 'View all moderator orders', 'slug' => 'moderator.view_all_moderator_orders', 'group' => 'moderator', 'description' => 'View all moderator assignment queues and orders.'],
            ['name' => 'Add moderators', 'slug' => 'moderator.add_moderator', 'group' => 'moderator', 'description' => 'Create new moderator profiles.'],
            ['name' => 'Manage moderators', 'slug' => 'moderator.manage_moderators', 'group' => 'moderator', 'description' => 'Update moderator profiles and manager mapping.'],
            ['name' => 'Assign products to moderators', 'slug' => 'moderator.assign_product_to_moderator', 'group' => 'moderator', 'description' => 'Set product-specific moderator assignment rules.'],
            ['name' => 'Reassign orders', 'slug' => 'moderator.reassign_orders', 'group' => 'moderator', 'description' => 'Reassign one order to an active moderator.'],
            ['name' => 'Bulk reassign orders', 'slug' => 'moderator.bulk_reassign_orders', 'group' => 'moderator', 'description' => 'Bulk reassign selected orders to an active moderator.'],
            ['name' => 'Activate/deactivate moderators', 'slug' => 'moderator.activate_deactivate_moderator', 'group' => 'moderator', 'description' => 'Toggle moderator active and inactive status.'],
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

        $permissionIds = DB::table('admin_permissions')
            ->whereIn('slug', collect($this->permissions())->pluck('slug')->all())
            ->pluck('id');

        foreach (['super-admin', 'admin'] as $roleSlug) {
            $roleId = DB::table('admin_roles')->where('slug', $roleSlug)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('admin_permission_role')->updateOrInsert(
                    ['admin_role_id' => $roleId, 'admin_permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('admin_permissions')
            ->whereIn('slug', collect($this->permissions())->pluck('slug')->all())
            ->pluck('id');

        DB::table('admin_permission_role')->whereIn('admin_permission_id', $permissionIds)->delete();
        DB::table('admin_permissions')->whereIn('id', $permissionIds)->delete();
    }
};
