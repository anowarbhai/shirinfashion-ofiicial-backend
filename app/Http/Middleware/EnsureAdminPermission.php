<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $permission = $this->permissionFor($request);

        if (! $permission) {
            return $next($request);
        }

        $permissions = is_array($permission) ? $permission : [$permission];
        $hasPermission = collect($permissions)->contains(
            fn (string $requiredPermission): bool => (bool) $request->user()?->hasAdminPermission($requiredPermission)
        );

        if (! $hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to access this admin area.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }

    private function permissionFor(Request $request): string|array|null
    {
        $path = trim($request->path(), '/');
        $adminPath = preg_replace('#^api/admin/?#', '', $path) ?? '';
        $method = strtoupper($request->method());

        if ($adminPath === '' || $adminPath === 'profile' || $adminPath === 'avatar' || $adminPath === 'password') {
            return null;
        }

        $isRead = $method === 'GET' || $method === 'HEAD';

        return match (true) {
            $adminPath === 'dashboard' => 'dashboard.view',
            str_starts_with($adminPath, 'moderators') && $isRead => ['moderator.manage_moderators', 'moderator.view_all_moderator_orders', 'moderator.view_assigned_orders'],
            str_starts_with($adminPath, 'moderators') => 'moderator.manage_moderators',
            str_starts_with($adminPath, 'product-moderator-assignments') && $isRead => ['moderator.assign_product_to_moderator', 'moderator.manage_moderators'],
            str_starts_with($adminPath, 'product-moderator-assignments') => 'moderator.assign_product_to_moderator',
            str_starts_with($adminPath, 'order-assignments') => ['moderator.view_all_moderator_orders', 'moderator.view_assigned_orders'],
            str_contains($adminPath, 'assignment-history') => ['moderator.view_all_moderator_orders', 'moderator.view_assigned_orders'],
            str_starts_with($adminPath, 'orders/bulk-reassign') => 'moderator.bulk_reassign_orders',
            str_contains($adminPath, '/fraud-check') => ['orders.fraud', 'settings.fraud.manage'],
            str_contains($adminPath, '/reassign') => 'moderator.reassign_orders',
            str_starts_with($adminPath, 'orders') && $isRead => ['orders.view', 'moderator.view_assigned_orders', 'moderator.view_all_moderator_orders'],
            str_starts_with($adminPath, 'orders') && $method === 'POST' => ['orders.create', 'orders.manage'],
            str_starts_with($adminPath, 'orders') && $method === 'DELETE' => ['orders.delete', 'orders.manage'],
            str_starts_with($adminPath, 'orders') => ['orders.edit', 'orders.manage'],
            str_starts_with($adminPath, 'customers') => $isRead ? 'customers.view' : 'customers.manage',
            str_starts_with($adminPath, 'product-page-settings'),
            str_starts_with($adminPath, 'products/page-settings'),
            str_contains($adminPath, 'volume-discounts') => 'product-settings.manage',
            str_starts_with($adminPath, 'products') => $isRead ? 'products.view' : 'products.manage',
            str_starts_with($adminPath, 'categories') => 'categories.manage',
            str_starts_with($adminPath, 'tags') => 'tags.manage',
            str_starts_with($adminPath, 'attributes'),
            str_starts_with($adminPath, 'attribute-terms') => 'attributes.manage',
            str_starts_with($adminPath, 'reviews') => 'reviews.manage',
            str_starts_with($adminPath, 'media') => 'media.manage',
            str_starts_with($adminPath, 'pages') => 'pages.manage',
            str_starts_with($adminPath, 'sliders') => 'sliders.manage',
            str_starts_with($adminPath, 'coupons') => 'coupons.manage',
            str_starts_with($adminPath, 'contact-messages') => 'contact-messages.manage',
            str_starts_with($adminPath, 'team-members') => $isRead ? 'team.view' : 'team.manage',
            str_starts_with($adminPath, 'audit-logs') => ['audit.view', 'audit.view.all'],
            str_starts_with($adminPath, 'marketing/facebook') => 'marketing.facebook.manage',
            str_starts_with($adminPath, 'marketing/google') => 'marketing.google.manage',
            str_starts_with($adminPath, 'marketing/seo') => 'marketing.seo.manage',
            str_starts_with($adminPath, 'themes') => 'themes.manage',
            str_starts_with($adminPath, 'settings/general') => 'settings.general.manage',
            str_starts_with($adminPath, 'settings/fraud-checker/test') => ['orders.fraud', 'settings.fraud.manage'],
            str_starts_with($adminPath, 'settings/fraud-checker') => 'settings.fraud.manage',
            str_starts_with($adminPath, 'settings/sms-integration') => 'settings.sms.manage',
            str_starts_with($adminPath, 'settings/checkout-guard') => 'settings.checkout-guard.manage',
            str_starts_with($adminPath, 'settings/mail-setup') => 'settings.mail.manage',
            str_starts_with($adminPath, 'settings/roles') => 'roles.manage',
            str_starts_with($adminPath, 'settings/permissions') => 'permissions.manage',
            default => null,
        };
    }
}
