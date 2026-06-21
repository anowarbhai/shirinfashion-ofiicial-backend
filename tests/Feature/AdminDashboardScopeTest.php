<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Moderator;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_dashboard_only_counts_own_assigned_orders(): void
    {
        [$first, $second] = $this->createModerators(2);
        $role = $this->roleWithPermissions(['dashboard.view', 'moderator.view_assigned_orders']);
        $first->user->update(['role' => 'admin', 'admin_role_id' => $role->id, 'status' => 'active']);

        $ownOrder = $this->createOrder(180, 'Facebook');
        $otherOrder = $this->createOrder(320, 'Google');
        $this->assignOrderToModerator($ownOrder, $first);
        $this->assignOrderToModerator($otherOrder, $second);

        $this->getDashboard($first->user->fresh())
            ->assertOk()
            ->assertJsonPath('data.kpis.0.value', 'BDT 180.00')
            ->assertJsonPath('data.kpis.1.value', '1')
            ->assertJsonPath('data.today_summary.sales', 'BDT 180.00')
            ->assertJsonPath('data.today_summary.orders', '1')
            ->assertJsonPath('data.recent_orders.0.id', $ownOrder->order_number)
            ->assertJsonPath('data.charts.order_sources.0.label', 'Facebook')
            ->assertJsonPath('data.charts.order_sources.0.value', 1);
    }

    public function test_marketer_dashboard_counts_only_managed_moderator_orders(): void
    {
        $manager = $this->createAdminUser(['dashboard.view', 'moderator.manage_moderators']);
        [$managed, $unmanaged] = $this->createModerators(2);
        $managed->update(['digital_marketer_id' => $manager->id]);

        $managedOrder = $this->createOrder(250, 'Facebook');
        $unmanagedOrder = $this->createOrder(450, 'Google');
        $this->assignOrderToModerator($managedOrder, $managed);
        $this->assignOrderToModerator($unmanagedOrder, $unmanaged);

        $this->getDashboard($manager)
            ->assertOk()
            ->assertJsonPath('data.kpis.0.value', 'BDT 250.00')
            ->assertJsonPath('data.kpis.1.value', '1')
            ->assertJsonPath('data.today_summary.sales', 'BDT 250.00')
            ->assertJsonPath('data.today_summary.orders', '1')
            ->assertJsonPath('data.recent_orders.0.id', $managedOrder->order_number);
    }

    public function test_admin_dashboard_still_counts_all_orders(): void
    {
        [$first, $second] = $this->createModerators(2);
        $admin = $this->createAdminUser(['dashboard.view', 'orders.view']);

        $firstOrder = $this->createOrder(180, 'Facebook');
        $secondOrder = $this->createOrder(320, 'Google');
        $this->assignOrderToModerator($firstOrder, $first);
        $this->assignOrderToModerator($secondOrder, $second);

        $this->getDashboard($admin)
            ->assertOk()
            ->assertJsonPath('data.kpis.0.value', 'BDT 500.00')
            ->assertJsonPath('data.kpis.1.value', '2')
            ->assertJsonPath('data.today_summary.sales', 'BDT 500.00')
            ->assertJsonPath('data.today_summary.orders', '2');
    }

    public function test_dashboard_defaults_to_today_and_summary_follows_selected_range(): void
    {
        $admin = $this->createAdminUser(['dashboard.view', 'orders.view']);
        $todayOrder = $this->createOrder(180, 'Facebook', now());
        $this->createOrder(320, 'Google', now()->subDay());

        $this->getDashboard($admin, null)
            ->assertOk()
            ->assertJsonPath('data.filter.key', 'today')
            ->assertJsonPath('data.kpis.0.value', 'BDT 180.00')
            ->assertJsonPath('data.kpis.1.value', '1')
            ->assertJsonPath('data.today_summary.sales', 'BDT 180.00')
            ->assertJsonPath('data.today_summary.orders', '1')
            ->assertJsonPath('data.recent_orders.0.id', $todayOrder->order_number)
            ->assertJsonPath('data.charts.orders.current.0.value', 1);

        $this->getDashboard($admin, 'all_time')
            ->assertOk()
            ->assertJsonPath('data.filter.key', 'all_time')
            ->assertJsonPath('data.kpis.0.value', 'BDT 500.00')
            ->assertJsonPath('data.kpis.1.value', '2')
            ->assertJsonPath('data.today_summary.sales', 'BDT 500.00')
            ->assertJsonPath('data.today_summary.orders', '2');
    }

    /**
     * @return array<int, Moderator>
     */
    private function createModerators(int $count): array
    {
        return collect(range(1, $count))
            ->map(function (int $index): Moderator {
                $user = User::factory()->create([
                    'role' => 'admin',
                    'phone' => '0190000100'.$index,
                    'status' => 'active',
                ]);

                return Moderator::query()->create([
                    'user_id' => $user->id,
                    'status' => 'active',
                    'assignment_order' => $index,
                ])->load('user');
            })
            ->all();
    }

    /**
     * @param array<int, string> $permissions
     */
    private function createAdminUser(array $permissions): User
    {
        $role = $this->roleWithPermissions($permissions);

        return User::factory()->create([
            'role' => 'admin',
            'admin_role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function createOrder(float $grandTotal, string $source, mixed $placedAt = null): Order
    {
        return Order::query()->create([
            'order_number' => 'SBA-'.random_int(100000, 999999),
            'customer_name' => 'Dashboard Customer',
            'email' => strtolower((string) str()->random(8)).'@example.test',
            'phone' => '01911111111',
            'order_source' => $source,
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => $grandTotal - 80,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => $grandTotal,
            'shipping_address' => ['address' => 'Dhaka', 'city' => 'Dhaka', 'country' => 'Bangladesh'],
            'placed_at' => $placedAt ?? now(),
        ]);
    }

    private function assignOrderToModerator(Order $order, Moderator $moderator): void
    {
        $order->update([
            'assigned_moderator_id' => $moderator->user_id,
            'assignment_status' => 'assigned',
            'assignment_type' => 'manual_reassign',
            'assignment_status_type' => 'processing',
        ]);

        OrderAssignment::query()->create([
            'order_id' => $order->id,
            'moderator_id' => $moderator->id,
            'order_status_type' => 'processing',
            'assigned_type' => 'manual_reassign',
            'status' => 'assigned',
        ]);
    }

    /**
     * @param array<int, string> $slugs
     */
    private function roleWithPermissions(array $slugs): AdminRole
    {
        $role = AdminRole::query()->create([
            'name' => 'Dashboard Scope '.str()->random(8),
            'slug' => 'dashboard-scope-'.str()->random(12),
            'is_active' => true,
        ]);

        $permissionIds = collect($slugs)
            ->map(fn (string $slug) => AdminPermission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => str($slug)->headline()->toString(), 'group' => 'dashboard', 'description' => $slug, 'is_active' => true],
            )->id)
            ->all();

        $role->permissions()->sync($permissionIds);

        return $role;
    }

    private function getDashboard(User $user, ?string $range = 'all_time')
    {
        $token = app(JwtService::class)->issueToken($user);
        $url = '/api/admin/dashboard';

        if ($range !== null) {
            $url .= '?range='.$range;
        }

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson($url);
    }
}
