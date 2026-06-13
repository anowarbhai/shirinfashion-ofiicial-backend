<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Order;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_orders_can_be_filtered_by_order_source(): void
    {
        $admin = $this->createAdminUser(['orders.view']);
        $facebookOrder = $this->createOrder('Facebook');
        $this->createOrder('Google');
        $this->createOrder(null);

        $this->getOrders($admin, 'Facebook')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $facebookOrder->id)
            ->assertJsonPath('summary.total', 1);
    }

    public function test_direct_source_filter_includes_blank_sources(): void
    {
        $admin = $this->createAdminUser(['orders.view']);
        $directOrder = $this->createOrder(null);
        $this->createOrder('Facebook');

        $this->getOrders($admin, 'Direct')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $directOrder->id)
            ->assertJsonPath('summary.total', 1);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function createAdminUser(array $permissions): User
    {
        $role = AdminRole::query()->create([
            'name' => 'Order Source Filter '.str()->random(8),
            'slug' => 'order-source-filter-'.str()->random(12),
            'is_active' => true,
        ]);

        $permissionIds = collect($permissions)
            ->map(fn (string $slug) => AdminPermission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => str($slug)->headline()->toString(), 'group' => 'orders', 'description' => $slug, 'is_active' => true],
            )->id)
            ->all();

        $role->permissions()->sync($permissionIds);

        return User::factory()->create([
            'role' => 'admin',
            'admin_role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function createOrder(?string $source): Order
    {
        return Order::query()->create([
            'order_number' => 'SBA-'.random_int(100000, 999999),
            'customer_name' => 'Source Customer',
            'email' => strtolower((string) str()->random(8)).'@example.test',
            'phone' => '01911111111',
            'order_source' => $source,
            'status' => 'processing',
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

    private function getOrders(User $user, string $source)
    {
        $token = app(JwtService::class)->issueToken($user);

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/orders?order_source='.urlencode($source));
    }
}
