<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'Manage Brands', 'slug' => 'brands.manage', 'group' => 'products', 'description' => 'Create, edit, and delete product brands.'],
            ['name' => 'View Brands', 'slug' => 'brands.view', 'group' => 'products', 'description' => 'View list of product brands.'],
        ];

        foreach ($permissions as $permissionData) {
            $permissionId = DB::table('admin_permissions')->where('slug', $permissionData['slug'])->value('id');

            if (!$permissionId) {
                $permissionId = DB::table('admin_permissions')->insertGetId([
                    'name' => $permissionData['name'],
                    'slug' => $permissionData['slug'],
                    'group' => $permissionData['group'],
                    'description' => $permissionData['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Assign to super-admin and admin roles
            $roleIds = DB::table('admin_roles')
                ->whereIn('slug', ['super-admin', 'admin'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('admin_permission_role')->updateOrInsert([
                    'admin_role_id' => $roleId,
                    'admin_permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('admin_permissions')->whereIn('slug', ['brands.manage', 'brands.view'])->delete();
    }
};
