<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\AdminSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncompleteOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_order_is_updated_instead_of_duplicated(): void
    {
        $product = $this->createProduct();
        $payload = $this->orderPayload($product, quantity: 1);

        $this->postJson('/api/orders/incomplete', $payload)->assertOk();
        $this->postJson('/api/orders/incomplete', $this->orderPayload($product, quantity: 2, address: 'Road 2, Dhaka'))
            ->assertOk();

        $this->assertDatabaseCount('orders', 1);
        $order = Order::query()->with('items')->firstOrFail();

        $this->assertSame('incomplete', $order->status);
        $this->assertSame('Road 2, Dhaka', $order->shipping_address['address']);
        $this->assertSame(2, $order->items->first()->quantity);
        $this->assertNotNull($order->last_activity_at);
        $this->assertNull($order->placed_at);
    }

    public function test_final_order_converts_matching_incomplete_order_to_processing(): void
    {
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders/incomplete', $payload)->assertOk();
        $incompleteOrderId = Order::query()->value('id');

        $this->postJson('/api/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseCount('orders', 1);

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame($incompleteOrderId, $order->id);
        $this->assertSame('processing', $order->status);
        $this->assertNotNull($order->placed_at);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(9, $product->fresh()->inventory);
    }

    public function test_final_order_rejects_inactive_product(): void
    {
        $product = $this->createProduct();
        $product->update(['is_active' => false]);

        $this->postJson('/api/orders', $this->orderPayload($product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_final_order_rejects_out_of_stock_product(): void
    {
        $product = $this->createProduct();
        $product->update([
            'inventory' => 0,
            'stock_status' => 'out_of_stock',
        ]);

        $this->postJson('/api/orders', $this->orderPayload($product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_same_guest_session_updates_incomplete_order_when_phone_changes(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/orders/incomplete', $this->orderPayload($product, phone: '01919012186'))
            ->assertOk();
        $this->postJson('/api/orders/incomplete', $this->orderPayload($product, phone: '01829312186'))
            ->assertOk();

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('01829312186', Order::query()->value('phone'));
    }

    public function test_incomplete_order_is_skipped_during_checkout_guard_cooldown(): void
    {
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders', $payload)->assertCreated();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'New Road, Dhaka',
            cartSessionId: 'new-cart-session',
        ))
            ->assertOk()
            ->assertJsonPath('incomplete_order_skipped', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('processing', Order::query()->value('status'));
    }

    public function test_incomplete_order_protection_uses_recent_order_even_when_individual_signals_are_disabled(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => true,
            'block_by_phone' => false,
            'block_by_ip' => false,
            'block_by_device' => false,
            'protect_incomplete_orders' => true,
            'cooldown_minutes' => 180,
            'message' => 'You can place another order after {{time}}.',
        ]);

        $product = $this->createProduct();

        $this->postJson('/api/orders', $this->orderPayload($product))->assertCreated();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'New Road, Dhaka',
            cartSessionId: 'another-cart-session',
        ))
            ->assertOk()
            ->assertJsonPath('incomplete_order_skipped', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('processing', Order::query()->value('status'));
    }

    public function test_incomplete_order_is_skipped_after_converted_order_completes(): void
    {
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders/incomplete', $payload)->assertOk();
        $this->postJson('/api/orders', $payload)->assertCreated();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'New Road, Dhaka',
            phone: '01829312186',
            cartSessionId: 'test-cart-session',
        ))
            ->assertOk()
            ->assertJsonPath('incomplete_order_skipped', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('processing', Order::query()->value('status'));
    }

    public function test_order_is_blocked_after_converted_order_completes_for_same_session(): void
    {
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders/incomplete', $payload)->assertOk();
        $this->postJson('/api/orders', $payload)->assertCreated();

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'New Road, Dhaka',
            phone: '01829312186',
            cartSessionId: 'test-cart-session',
        ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.blocked', true);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_incomplete_order_guard_can_be_disabled(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => true,
            'block_by_phone' => true,
            'block_by_ip' => true,
            'block_by_device' => true,
            'protect_incomplete_orders' => false,
            'cooldown_minutes' => 180,
            'message' => 'You can place another order after {{time}}.',
        ]);

        $product = $this->createProduct();

        $this->postJson('/api/orders', $this->orderPayload($product))->assertCreated();
        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'New Road, Dhaka',
            cartSessionId: 'new-cart-session',
        ))
            ->assertOk()
            ->assertJsonPath('data.status', 'incomplete');

        $this->assertDatabaseCount('orders', 2);
    }

    private function createProduct(): Product
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Glow Cream',
            'slug' => 'glow-cream',
            'sku' => 'GLW-001',
            'brand' => 'Shirin Fashion',
            'price' => 100,
            'inventory' => 10,
            'gallery' => [],
            'is_active' => true,
        ]);
    }

    private function orderPayload(
        Product $product,
        int $quantity = 1,
        string $address = 'Road 1, Dhaka',
        string $phone = '01919012186',
        string $cartSessionId = 'test-cart-session',
    ): array {
        return [
            'customer_name' => 'Test Customer',
            'phone' => $phone,
            'payment_method' => 'cod',
            'shipping_method' => 'inside-dhaka',
            'device_id' => 'test-device',
            'cart_session_id' => $cartSessionId,
            'shipping_address' => [
                'address' => $address,
            ],
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ],
            ],
        ];
    }
}
