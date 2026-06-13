<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderAssignmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigned_filter_includes_pending_manual_review_assignments(): void
    {
        $admin = $this->createAdminUser(['moderator.view_all_moderator_orders']);
        $order = $this->createOrder();
        $assignment = OrderAssignment::query()->create([
            'order_id' => $order->id,
            'moderator_id' => null,
            'order_status_type' => 'incomplete',
            'assigned_type' => 'auto_round_robin',
            'status' => 'pending_manual_review',
            'note' => 'No active moderator was available.',
        ]);

        $token = app(JwtService::class)->issueToken($admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/order-assignments?moderator_id=unassigned')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $assignment->id)
            ->assertJsonPath('data.data.0.order.id', $order->id);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function createAdminUser(array $permissions): User
    {
        $role = AdminRole::query()->create([
            'name' => 'Assignment Dashboard '.str()->random(8),
            'slug' => 'assignment-dashboard-'.str()->random(12),
            'is_active' => true,
        ]);

        $permissionIds = collect($permissions)
            ->map(fn (string $slug) => AdminPermission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => str($slug)->headline()->toString(), 'group' => 'moderator', 'description' => $slug, 'is_active' => true],
            )->id)
            ->all();

        $role->permissions()->sync($permissionIds);

        return User::factory()->create([
            'role' => 'admin',
            'admin_role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function createOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'SBA-'.random_int(100000, 999999),
            'customer_name' => 'Assignment Customer',
            'email' => strtolower((string) str()->random(8)).'@example.test',
            'phone' => '01911111111',
            'status' => 'incomplete',
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => 100,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 180,
            'shipping_address' => ['address' => 'Dhaka', 'city' => 'Dhaka', 'country' => 'Bangladesh'],
            'placed_at' => now(),
        ]);
    }
}
