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
            ['name' => 'Create products', 'slug' => 'products.create', 'group' => 'products', 'description' => 'Add new products to the catalog.'],
            ['name' => 'Edit products', 'slug' => 'products.edit', 'group' => 'products', 'description' => 'Update existing product information.'],
            ['name' => 'Delete products', 'slug' => 'products.delete', 'group' => 'products', 'description' => 'Delete products from the catalog.'],
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

        $roleIds = DB::table('admin_roles')
            ->whereIn('slug', ['super-admin', 'admin', 'manager', 'product-manager'])
            ->pluck('id');

        $legacyProductManagerPermissionId = DB::table('admin_permissions')
            ->where('slug', 'products.manage')
            ->value('id');

        if ($legacyProductManagerPermissionId) {
            $roleIds = $roleIds
                ->merge(DB::table('admin_permission_role')
                    ->where('admin_permission_id', $legacyProductManagerPermissionId)
                    ->pluck('admin_role_id'))
                ->unique()
                ->values();
        }

        foreach ($roleIds as $roleId) {
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
        $permissionSlugs = collect($this->permissions())->pluck('slug')->all();
        $permissionIds = DB::table('admin_permissions')->whereIn('slug', $permissionSlugs)->pluck('id');

        DB::table('admin_permission_role')->whereIn('admin_permission_id', $permissionIds)->delete();
        DB::table('admin_permissions')->whereIn('slug', $permissionSlugs)->delete();
    }
};
