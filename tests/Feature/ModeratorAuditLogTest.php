<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\Moderator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OrderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModeratorAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_status_changes_record_actor_and_state(): void
    {
        $admin = $this->admin();
        $moderator = $this->moderator('Moderator One');

        $this->asAdmin($admin)
            ->patchJson("/api/admin/moderators/{$moderator->id}", [
                'digital_marketer_id' => null,
                'status' => 'inactive',
                'assignment_order' => 1,
            ])
            ->assertOk();

        $log = AdminAuditLog::query()->where('action', 'moderator.status_changed')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($moderator->id, $log->subject_id);
        $this->assertSame('active', $log->metadata['before']['status']);
        $this->assertSame('inactive', $log->metadata['after']['status']);
    }

    public function test_product_moderator_assignment_records_before_and_after_state(): void
    {
        $admin = $this->admin();
        $moderator = $this->moderator('Moderator Two');
        $product = $this->product();

        $this->asAdmin($admin)
            ->patchJson("/api/admin/product-moderator-assignments/{$product->id}", [
                'moderator_ids' => [$moderator->id],
            ])
            ->assertOk();

        $log = AdminAuditLog::query()
            ->where('action', 'product.moderator_assignments_changed')
            ->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame([], $log->metadata['before_moderator_ids']);
        $this->assertSame([$moderator->id], $log->metadata['after_moderator_ids']);
    }

    public function test_order_reassignment_records_actor_and_moderators(): void
    {
        $admin = $this->admin();
        $first = $this->moderator('Moderator Three');
        $second = $this->moderator('Moderator Four', 2);
        $order = $this->order();
        app(OrderAssignmentService::class)->reassignOrder($order->id, $first->id, null);

        $this->asAdmin($admin)
            ->postJson("/api/admin/orders/{$order->id}/reassign", [
                'moderator_id' => $second->id,
                'note' => 'Load balancing',
            ])
            ->assertOk();

        $log = AdminAuditLog::query()->where('action', 'order.reassigned')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($order->id, $log->subject_id);
        $this->assertSame($first->id, $log->metadata['previous_moderator_id']);
        $this->assertSame($second->id, $log->metadata['new_moderator_id']);
    }

    public function test_bulk_order_reassignment_records_the_actual_target_moderator(): void
    {
        $admin = $this->admin();
        $first = $this->moderator('Moderator Five');
        $second = $this->moderator('Moderator Six', 2);
        $order = $this->order();
        app(OrderAssignmentService::class)->reassignOrder($order->id, $first->id, null);

        $this->asAdmin($admin)
            ->postJson('/api/admin/orders/bulk-reassign', [
                'order_ids' => [$order->id],
                'moderator_ids' => [$second->id],
                'note' => 'Bulk load balancing',
            ])
            ->assertOk()
            ->assertJsonPath('message', '1 orders reassigned successfully.');

        $log = AdminAuditLog::query()
            ->where('action', 'order.reassigned')
            ->where('subject_id', $order->id)
            ->firstOrFail();

        $this->assertTrue($log->metadata['bulk']);
        $this->assertSame($first->id, $log->metadata['previous_moderator_id']);
        $this->assertSame($second->id, $log->metadata['new_moderator_id']);
    }

    private function asAdmin(User $admin): static
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.app(JwtService::class)->issueToken($admin),
        );
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function moderator(string $name, int $order = 1): Moderator
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'admin',
            'status' => 'active',
        ]);

        return Moderator::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'assignment_order' => $order,
        ]);
    }

    private function product(): Product
    {
        $category = Category::query()->create([
            'name' => 'Audit Category',
            'slug' => 'audit-category',
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Audit Product',
            'slug' => 'audit-product',
            'sku' => 'AUDIT-001',
            'brand' => 'Shirin Fashion',
            'price' => 100,
            'inventory' => 10,
            'gallery' => [],
            'is_active' => true,
        ]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'order_number' => 'SBA-10000001',
            'customer_name' => 'Audit Customer',
            'email' => 'audit@example.test',
            'phone' => '01700000000',
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => 100,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 180,
            'shipping_address' => ['address' => 'Dhaka'],
        ]);
    }
}
