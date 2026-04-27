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
            ['name' => 'View dashboard', 'slug' => 'dashboard.view', 'group' => 'dashboard', 'description' => 'Access the admin dashboard overview.'],
            ['name' => 'View orders', 'slug' => 'orders.view', 'group' => 'orders', 'description' => 'View customer orders.'],
            ['name' => 'Manage orders', 'slug' => 'orders.manage', 'group' => 'orders', 'description' => 'Update order status, tracking, notes, and totals.'],
            ['name' => 'Check order fraud', 'slug' => 'orders.fraud', 'group' => 'orders', 'description' => 'Run and view fraud checker results for orders.'],
            ['name' => 'Delete orders', 'slug' => 'orders.delete', 'group' => 'orders', 'description' => 'Delete orders when cleanup is required.'],
            ['name' => 'View products', 'slug' => 'products.view', 'group' => 'products', 'description' => 'View product catalogue data.'],
            ['name' => 'Manage products', 'slug' => 'products.manage', 'group' => 'products', 'description' => 'Create, edit, and delete products.'],
            ['name' => 'Manage categories', 'slug' => 'categories.manage', 'group' => 'products', 'description' => 'Create, edit, and delete product categories.'],
            ['name' => 'Manage tags', 'slug' => 'tags.manage', 'group' => 'products', 'description' => 'Create, edit, and delete product tags.'],
            ['name' => 'Manage attributes', 'slug' => 'attributes.manage', 'group' => 'products', 'description' => 'Create, edit, and delete product attributes and terms.'],
            ['name' => 'Manage product reviews', 'slug' => 'reviews.manage', 'group' => 'products', 'description' => 'Approve, reject, edit, and delete product reviews.'],
            ['name' => 'Manage product settings', 'slug' => 'product-settings.manage', 'group' => 'products', 'description' => 'Manage product page, shipping, payment, tax, and review settings.'],
            ['name' => 'View customers', 'slug' => 'customers.view', 'group' => 'customers', 'description' => 'View customer accounts and profiles.'],
            ['name' => 'Manage customers', 'slug' => 'customers.manage', 'group' => 'customers', 'description' => 'Edit customer accounts and account status.'],
            ['name' => 'Manage media library', 'slug' => 'media.manage', 'group' => 'content', 'description' => 'Upload, select, and delete media assets.'],
            ['name' => 'Manage pages', 'slug' => 'pages.manage', 'group' => 'content', 'description' => 'Create, edit, preview, and delete storefront pages.'],
            ['name' => 'Manage sliders', 'slug' => 'sliders.manage', 'group' => 'content', 'description' => 'Create, edit, and delete homepage sliders.'],
            ['name' => 'Manage themes', 'slug' => 'themes.manage', 'group' => 'theme', 'description' => 'Manage appearance, header, footer, and menu settings.'],
            ['name' => 'Manage Facebook marketing', 'slug' => 'marketing.facebook.manage', 'group' => 'marketing', 'description' => 'Manage Facebook Pixel and Conversion API settings.'],
            ['name' => 'Manage Google marketing', 'slug' => 'marketing.google.manage', 'group' => 'marketing', 'description' => 'Manage GTM, GA4, and Google Ads settings.'],
            ['name' => 'Manage SEO', 'slug' => 'marketing.seo.manage', 'group' => 'marketing', 'description' => 'Manage SEO settings, robots, and metadata.'],
            ['name' => 'Manage coupons', 'slug' => 'coupons.manage', 'group' => 'marketing', 'description' => 'Create, edit, and delete discount coupons.'],
            ['name' => 'Manage contact messages', 'slug' => 'contact-messages.manage', 'group' => 'support', 'description' => 'View and manage contact form messages.'],
            ['name' => 'Manage general settings', 'slug' => 'settings.general.manage', 'group' => 'settings', 'description' => 'Manage general store settings.'],
            ['name' => 'Manage fraud checker settings', 'slug' => 'settings.fraud.manage', 'group' => 'settings', 'description' => 'Manage fraud checker API and test tools.'],
            ['name' => 'Manage SMS integration', 'slug' => 'settings.sms.manage', 'group' => 'settings', 'description' => 'Manage SMS gateway, OTP templates, balance, and test tools.'],
            ['name' => 'View team members', 'slug' => 'team.view', 'group' => 'team', 'description' => 'View admin team members.'],
            ['name' => 'Manage team members', 'slug' => 'team.manage', 'group' => 'team', 'description' => 'Invite, edit, block, and manage team members.'],
            ['name' => 'Manage roles', 'slug' => 'roles.manage', 'group' => 'team', 'description' => 'Create and edit admin roles.'],
            ['name' => 'Manage permissions', 'slug' => 'permissions.manage', 'group' => 'team', 'description' => 'Create permissions and assign them to roles.'],
            ['name' => 'View audit logs', 'slug' => 'audit.view', 'group' => 'team', 'description' => 'View admin activity and audit logs.'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rolePermissions(): array
    {
        return [
            'admin' => [
                'dashboard.view',
                'orders.view',
                'orders.manage',
                'orders.fraud',
                'products.view',
                'products.manage',
                'categories.manage',
                'tags.manage',
                'attributes.manage',
                'reviews.manage',
                'product-settings.manage',
                'customers.view',
                'customers.manage',
                'media.manage',
                'pages.manage',
                'sliders.manage',
                'themes.manage',
                'marketing.facebook.manage',
                'marketing.google.manage',
                'marketing.seo.manage',
                'coupons.manage',
                'contact-messages.manage',
                'settings.general.manage',
                'settings.fraud.manage',
                'settings.sms.manage',
                'team.view',
                'audit.view',
            ],
            'manager' => [
                'dashboard.view',
                'orders.view',
                'orders.manage',
                'orders.fraud',
                'products.view',
                'products.manage',
                'categories.manage',
                'tags.manage',
                'attributes.manage',
                'reviews.manage',
                'customers.view',
                'media.manage',
                'pages.manage',
                'sliders.manage',
                'coupons.manage',
                'contact-messages.manage',
            ],
            'order-manager' => [
                'dashboard.view',
                'orders.view',
                'orders.manage',
                'orders.fraud',
                'customers.view',
                'contact-messages.manage',
            ],
            'product-manager' => [
                'dashboard.view',
                'products.view',
                'products.manage',
                'categories.manage',
                'tags.manage',
                'attributes.manage',
                'reviews.manage',
                'product-settings.manage',
                'media.manage',
            ],
            'marketing' => [
                'dashboard.view',
                'marketing.facebook.manage',
                'marketing.google.manage',
                'marketing.seo.manage',
                'coupons.manage',
                'sliders.manage',
                'pages.manage',
                'media.manage',
            ],
            'support' => [
                'dashboard.view',
                'orders.view',
                'orders.fraud',
                'customers.view',
                'reviews.manage',
                'contact-messages.manage',
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string, description: string}>
     */
    private function defaultRoles(): array
    {
        return [
            ['name' => 'Admin', 'slug' => 'admin', 'description' => 'Operational admin access for most store management tasks.'],
            ['name' => 'Manager', 'slug' => 'manager', 'description' => 'Store manager access for orders, products, content, and customers.'],
            ['name' => 'Order Manager', 'slug' => 'order-manager', 'description' => 'Order desk access for processing and fraud checks.'],
            ['name' => 'Product Manager', 'slug' => 'product-manager', 'description' => 'Catalogue access for products, categories, attributes, and reviews.'],
            ['name' => 'Marketing', 'slug' => 'marketing', 'description' => 'Marketing access for campaigns, SEO, coupons, pages, and sliders.'],
            ['name' => 'Support', 'slug' => 'support', 'description' => 'Customer support access for orders, reviews, customers, and messages.'],
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

        foreach ($this->defaultRoles() as $role) {
            DB::table('admin_roles')->updateOrInsert(
                ['slug' => $role['slug']],
                [
                    ...$role,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('admin_permissions')
            ->whereIn('slug', collect($this->permissions())->pluck('slug')->push('system.everything')->all())
            ->pluck('id', 'slug');

        $superAdminRoleId = DB::table('admin_roles')->where('slug', 'super-admin')->value('id');
        if ($superAdminRoleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('admin_permission_role')->updateOrInsert(
                    ['admin_role_id' => $superAdminRoleId, 'admin_permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        foreach ($this->rolePermissions() as $roleSlug => $slugs) {
            $roleId = DB::table('admin_roles')->where('slug', $roleSlug)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($slugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;

                if (! $permissionId) {
                    continue;
                }

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

        DB::table('admin_roles')
            ->whereIn('slug', collect($this->defaultRoles())->pluck('slug')->all())
            ->delete();
    }
};
