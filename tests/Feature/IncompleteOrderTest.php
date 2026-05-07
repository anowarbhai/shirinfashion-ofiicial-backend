<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
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

    private function orderPayload(Product $product, int $quantity = 1, string $address = 'Road 1, Dhaka'): array
    {
        return [
            'customer_name' => 'Test Customer',
            'phone' => '01919012186',
            'payment_method' => 'cod',
            'shipping_method' => 'inside-dhaka',
            'device_id' => 'test-device',
            'cart_session_id' => 'test-cart-session',
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
