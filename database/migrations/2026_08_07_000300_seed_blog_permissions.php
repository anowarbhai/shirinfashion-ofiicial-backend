<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'name' => 'View blog posts & categories',
                'slug' => 'blog.view',
                'group' => 'blog',
                'description' => 'Allows viewing blog posts and categories in admin panel.',
                'is_active' => true,
            ],
            [
                'name' => 'Create blog posts & categories',
                'slug' => 'blog.create',
                'group' => 'blog',
                'description' => 'Allows creating new blog posts and categories.',
                'is_active' => true,
            ],
            [
                'name' => 'Edit blog posts & categories',
                'slug' => 'blog.edit',
                'group' => 'blog',
                'description' => 'Allows editing existing blog posts and categories.',
                'is_active' => true,
            ],
            [
                'name' => 'Delete blog posts & categories',
                'slug' => 'blog.delete',
                'group' => 'blog',
                'description' => 'Allows deleting blog posts and categories.',
                'is_active' => true,
            ],
            [
                'name' => 'Manage blogs (Full Access)',
                'slug' => 'blog.manage',
                'group' => 'blog',
                'description' => 'Full access to view, create, edit, and delete blog posts & categories.',
                'is_active' => true,
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('admin_permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [
                    ...$permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $adminRoles = DB::table('admin_roles')->whereIn('slug', ['super-admin', 'admin', 'manager', 'editor', 'moderator'])->get();
        $permissionIds = DB::table('admin_permissions')->whereIn('slug', array_column($permissions, 'slug'))->pluck('id');

        foreach ($adminRoles as $role) {
            foreach ($permissionIds as $pId) {
                DB::table('admin_permission_role')->updateOrInsert([
                    'admin_role_id' => $role->id,
                    'admin_permission_id' => $pId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = ['blog.view', 'blog.create', 'blog.edit', 'blog.delete', 'blog.manage'];
        $permissionIds = DB::table('admin_permissions')->whereIn('slug', $slugs)->pluck('id');

        DB::table('admin_permission_role')->whereIn('admin_permission_id', $permissionIds)->delete();
        DB::table('admin_permissions')->whereIn('slug', $slugs)->delete();
    }
};
