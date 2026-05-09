<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\Category;
use App\Models\Moderator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductModeratorAssignment;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OrderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_and_incomplete_orders_use_separate_round_robin_sequences(): void
    {
        [$first, $second, $third] = $this->createModerators(3);
        $service = app(OrderAssignmentService::class);

        $processingOne = $this->createOrder('processing');
        $processingTwo = $this->createOrder('processing');
        $incompleteOne = $this->createOrder('incomplete');
        $processingThree = $this->createOrder('processing');
        $incompleteTwo = $this->createOrder('incomplete');

        $service->assignProcessingOrder($processingOne);
        $service->assignProcessingOrder($processingTwo);
        $service->assignIncompleteOrder($incompleteOne);
        $service->assignProcessingOrder($processingThree);
        $service->assignIncompleteOrder($incompleteTwo);

        $this->assertSame($first->user_id, $processingOne->fresh()->assigned_moderator_id);
        $this->assertSame($second->user_id, $processingTwo->fresh()->assigned_moderator_id);
        $this->assertSame($third->user_id, $processingThree->fresh()->assigned_moderator_id);
        $this->assertSame($first->user_id, $incompleteOne->fresh()->assigned_moderator_id);
        $this->assertSame($second->user_id, $incompleteTwo->fresh()->assigned_moderator_id);
    }

    public function test_inactive_moderators_are_skipped(): void
    {
        [$first, $second] = $this->createModerators(2);
        $first->update(['status' => 'inactive']);

        $order = $this->createOrder('processing');
        app(OrderAssignmentService::class)->assignProcessingOrder($order);

        $this->assertSame($second->user_id, $order->fresh()->assigned_moderator_id);
    }

    public function test_product_specific_assignment_overrides_round_robin(): void
    {
        [$first, $second] = $this->createModerators(2);
        $product = $this->createProduct('specific-product');
        ProductModeratorAssignment::query()->create([
            'product_id' => $product->id,
            'moderator_id' => $second->id,
        ]);

        $order = $this->createOrder('processing', $product);
        app(OrderAssignmentService::class)->assignProcessingOrder($order);

        $this->assertSame($second->user_id, $order->fresh()->assigned_moderator_id);
        $this->assertSame('product_specific', $order->fresh()->assignment_type);
        $this->assertNotSame($first->user_id, $order->fresh()->assigned_moderator_id);
    }

    public function test_inactive_product_specific_moderator_goes_pending_manual_review(): void
    {
        [$moderator] = $this->createModerators(1);
        $moderator->update(['status' => 'inactive']);
        $product = $this->createProduct('inactive-specific-product');
        ProductModeratorAssignment::query()->create([
            'product_id' => $product->id,
            'moderator_id' => $moderator->id,
        ]);

        $order = $this->createOrder('processing', $product);
        app(OrderAssignmentService::class)->assignProcessingOrder($order);

        $this->assertNull($order->fresh()->assigned_moderator_id);
        $this->assertSame('pending_manual_review', $order->fresh()->assignment_status);
    }

    public function test_admin_can_reassign_order(): void
    {
        [$first, $second] = $this->createModerators(2);
        $order = $this->createOrder('processing');
        $service = app(OrderAssignmentService::class);
        $service->assignProcessingOrder($order);

        $service->reassignOrder($order->id, $second->id, null, 'Testing reassignment');

        $this->assertSame($second->user_id, $order->fresh()->assigned_moderator_id);
        $this->assertSame('manual_reassign', $order->fresh()->assignment_type);
        $this->assertNotSame($first->user_id, $order->fresh()->assigned_moderator_id);
    }

    public function test_moderator_can_only_view_own_orders(): void
    {
        [$first, $second] = $this->createModerators(2);
        $role = $this->roleWithPermissions(['moderator.view_assigned_orders']);
        $first->user->update(['role' => 'admin', 'admin_role_id' => $role->id, 'status' => 'active']);

        $ownOrder = $this->createOrder('processing');
        $otherOrder = $this->createOrder('processing');
        app(OrderAssignmentService::class)->reassignOrder($ownOrder->id, $first->id, null);
        app(OrderAssignmentService::class)->reassignOrder($otherOrder->id, $second->id, null);

        $token = app(JwtService::class)->issueToken($first->user->fresh());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ownOrder->id);
    }

    public function test_moderator_with_orders_view_still_cannot_view_other_or_unassigned_orders(): void
    {
        [$first, $second] = $this->createModerators(2);
        $role = $this->roleWithPermissions(['orders.view']);
        $first->user->update(['role' => 'admin', 'admin_role_id' => $role->id, 'status' => 'active']);

        $ownOrder = $this->createOrder('processing');
        $otherOrder = $this->createOrder('processing');
        $unassignedOrder = $this->createOrder('processing');
        app(OrderAssignmentService::class)->reassignOrder($ownOrder->id, $first->id, null);
        app(OrderAssignmentService::class)->reassignOrder($otherOrder->id, $second->id, null);

        $token = app(JwtService::class)->issueToken($first->user->fresh());

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ownOrder->id);

        $orderIds = collect($response->json('data.data'))->pluck('id');
        $this->assertFalse($orderIds->contains($otherOrder->id));
        $this->assertFalse($orderIds->contains($unassignedOrder->id));
    }

    public function test_incomplete_to_processing_conversion_keeps_existing_moderator(): void
    {
        [$first, $second] = $this->createModerators(2);
        $order = $this->createOrder('incomplete');
        $service = app(OrderAssignmentService::class);
        $service->assignIncompleteOrder($order);

        $order->update(['status' => 'processing']);
        $service->keepExistingModeratorForStatus($order->fresh(), 'processing')
            ?? $service->assignProcessingOrder($order->fresh());

        $this->assertSame($first->user_id, $order->fresh()->assigned_moderator_id);
        $this->assertNotSame($second->user_id, $order->fresh()->assigned_moderator_id);
        $this->assertSame('processing', $order->fresh()->assignment_status_type);
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
                    'phone' => '0190000000'.$index,
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

    private function createOrder(string $status, ?Product $product = null): Order
    {
        $product ??= $this->createProduct('product-'.uniqid());

        $order = Order::query()->create([
            'order_number' => 'SBA-'.random_int(100000, 999999),
            'customer_name' => 'Customer',
            'email' => strtolower((string) str()->random(8)).'@example.test',
            'phone' => '01911111111',
            'status' => $status,
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => 100,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 180,
            'shipping_address' => ['address' => 'Dhaka', 'city' => 'Dhaka', 'country' => 'Bangladesh'],
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'price' => 100,
            'quantity' => 1,
            'line_total' => 100,
        ]);

        return $order->fresh('items');
    }

    private function createProduct(string $slug): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'assignment-category'],
            ['name' => 'Assignment Category'],
        );

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'sku' => strtoupper(substr(hash('sha1', $slug), 0, 8)),
            'brand' => 'Shirin Fashion',
            'price' => 100,
            'inventory' => 10,
            'gallery' => [],
            'is_active' => true,
        ]);
    }

    /**
     * @param array<int, string> $slugs
     */
    private function roleWithPermissions(array $slugs): AdminRole
    {
        $role = AdminRole::query()->create([
            'name' => 'Moderator Test Role',
            'slug' => 'moderator-test-role',
            'is_active' => true,
        ]);

        $permissionIds = collect($slugs)
            ->map(fn (string $slug) => AdminPermission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => str($slug)->headline()->toString(), 'group' => 'moderator', 'description' => $slug, 'is_active' => true],
            )->id)
            ->all();

        $role->permissions()->sync($permissionIds);

        return $role;
    }
}
