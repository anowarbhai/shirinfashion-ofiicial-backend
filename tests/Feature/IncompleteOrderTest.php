<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SmsOtp;
use App\Services\AdminSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class IncompleteOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('checkout-surge:global');
    }

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

    public function test_same_phone_updates_incomplete_order_across_different_cart_sessions(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 1,
            cartSessionId: 'first-cart-session',
        ))->assertOk();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'Road 2, Dhaka',
            cartSessionId: 'second-cart-session',
        ))->assertOk();

        $this->assertDatabaseCount('orders', 1);
        $order = Order::query()->with('items')->firstOrFail();

        $this->assertSame('incomplete', $order->status);
        $this->assertSame('01919012186', $order->phone);
        $this->assertSame('second-cart-session', $order->cart_session_id);
        $this->assertSame('Road 2, Dhaka', $order->shipping_address['address']);
        $this->assertSame(2, $order->items->first()->quantity);
    }

    public function test_incomplete_order_save_cleans_existing_duplicate_phone_rows(): void
    {
        $product = $this->createProduct();

        Order::query()->create($this->incompleteOrderPayload([
            'order_number' => 'SBA-1001',
            'cart_session_id' => 'first-cart-session',
            'last_activity_at' => now()->subMinutes(2),
        ]));

        $latestDuplicate = Order::query()->create($this->incompleteOrderPayload([
            'order_number' => 'SBA-1002',
            'cart_session_id' => 'second-cart-session',
            'last_activity_at' => now()->subMinute(),
        ]));

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            address: 'Road 3, Dhaka',
            cartSessionId: 'third-cart-session',
        ))->assertOk();

        $this->assertDatabaseCount('orders', 1);
        $order = Order::query()->with('items')->firstOrFail();

        $this->assertSame($latestDuplicate->id, $order->id);
        $this->assertSame('third-cart-session', $order->cart_session_id);
        $this->assertSame('Road 3, Dhaka', $order->shipping_address['address']);
        $this->assertSame(2, $order->items->first()->quantity);
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

    public function test_final_order_converts_matching_incomplete_order_by_phone_when_session_changes(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            cartSessionId: 'first-cart-session',
        ))->assertOk();
        $incompleteOrderId = Order::query()->value('id');

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            quantity: 2,
            cartSessionId: 'second-cart-session',
        ))
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseCount('orders', 1);

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame($incompleteOrderId, $order->id);
        $this->assertSame('processing', $order->status);
        $this->assertSame('second-cart-session', $order->cart_session_id);
        $this->assertSame(2, $order->items->first()->quantity);
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

    public function test_incomplete_order_is_skipped_for_same_phone_even_when_incomplete_protection_toggle_is_off(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => true,
            'block_by_phone' => true,
            'block_by_ip' => false,
            'block_by_device' => false,
            'protect_incomplete_orders' => false,
            'cooldown_minutes' => 180,
            'message' => 'You can place another order after {{time}}.',
        ]);

        $product = $this->createProduct();

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            phone: '+8801406899706',
            cartSessionId: 'first-cart-session',
        ))->assertCreated();

        $this->postJson('/api/orders/incomplete', $this->orderPayload(
            $product,
            quantity: 2,
            phone: '01406899706',
            cartSessionId: 'second-cart-session',
        ))
            ->assertOk()
            ->assertJsonPath('incomplete_order_skipped', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('processing', Order::query()->value('status'));
    }

    public function test_incomplete_order_is_blocked_for_same_device_after_completed_order_with_different_phone(): void
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
            ->assertJsonPath('incomplete_order_skipped', true)
            ->assertJsonPath('checkout_guard.matched_by.0', 'ip');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, Order::query()->where('status', 'processing')->count());
        $this->assertSame(0, Order::query()->where('status', 'incomplete')->count());
    }

    public function test_order_is_blocked_after_completed_order_for_different_phone_on_same_device(): void
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
            ->assertJsonPath('checkout_guard.blocked', true)
            ->assertJsonPath('checkout_guard.matched_by.0', 'ip');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_guard_checks_device_even_when_phone_blocking_is_enabled(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => true,
            'block_by_phone' => true,
            'block_by_ip' => false,
            'block_by_device' => true,
            'cooldown_minutes' => 180,
        ]);

        $product = $this->createProduct();

        $this->withHeader('X-Forwarded-For', '203.0.113.10')
            ->postJson('/api/orders', $this->orderPayload($product, phone: '01919012186'))
            ->assertCreated();

        $this->withHeader('X-Forwarded-For', '203.0.113.11')
            ->postJson('/api/orders', $this->orderPayload(
                $product,
                phone: '01829312186',
                cartSessionId: 'another-cart-session',
            ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'device');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_guard_checks_ip_when_phone_changes(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => true,
            'block_by_phone' => true,
            'block_by_ip' => true,
            'block_by_device' => false,
            'cooldown_minutes' => 180,
        ]);

        $product = $this->createProduct();

        $this->withHeader('X-Forwarded-For', '203.0.113.20')
            ->postJson('/api/orders', $this->orderPayload($product, phone: '01919012186'))
            ->assertCreated();

        $this->withHeader('X-Forwarded-For', '203.0.113.20')
            ->postJson('/api/orders', $this->orderPayload(
                $product,
                phone: '01829312186',
                cartSessionId: 'another-cart-session',
            ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'ip');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_suspicious_repeat_order_requires_otp_when_enabled(): void
    {
        $this->enableSuspiciousOrderOtp();
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders', $payload)->assertCreated();

        $this->postJson('/api/orders', $payload)
            ->assertStatus(428)
            ->assertJsonPath('requires_otp', true)
            ->assertJsonPath('otp_reason', 'suspicious_order');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_verified_otp_allows_configured_suspicious_repeat_order(): void
    {
        $this->enableSuspiciousOrderOtp();
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders', $payload)->assertCreated();

        SmsOtp::query()->create([
            'session_token' => 'verified-suspicious-order-token',
            'purpose' => 'order',
            'phone' => '01919012186',
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);

        $payload['otp_session_token'] = 'verified-suspicious-order-token';
        $payload['cart_session_id'] = 'second-cart-session';

        $this->postJson('/api/orders', $payload)->assertCreated();

        $this->assertDatabaseCount('orders', 2);
        $this->assertNotNull(SmsOtp::query()->firstOrFail()->consumed_at);
    }

    public function test_disabled_conditional_signal_keeps_hard_checkout_block(): void
    {
        $this->enableSuspiciousOrderOtp([
            'suspicious_otp_by_ip' => false,
        ]);
        $product = $this->createProduct();
        $payload = $this->orderPayload($product);

        $this->postJson('/api/orders', $payload)->assertCreated();

        $this->postJson('/api/orders', $payload)
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.blocked', true);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_conditional_order_otp_cannot_be_sent_without_a_suspicious_signal(): void
    {
        $this->enableSuspiciousOrderOtp();

        $this->postJson('/api/orders/send-otp', [
            'phone' => '01919012186',
            'customer_name' => 'Test Customer',
            'device_id' => 'new-device',
            'cart_session_id' => 'new-cart-session',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'OTP verification is not required for this order.');

        $this->assertDatabaseCount('sms_otps', 0);
    }

    public function test_enabled_fraud_rules_enforce_daily_phone_limit(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', ['enabled' => false]);
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'enabled' => true,
            'max_orders_per_phone_per_day' => 1,
            'max_orders_per_ip_per_day' => 99,
        ]);

        $product = $this->createProduct();
        $this->postJson('/api/orders', $this->orderPayload($product))->assertCreated();

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            phone: '+8801919012186',
            cartSessionId: 'another-cart-session',
        ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'phone');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_enabled_fraud_rules_block_blacklisted_phone_before_order_creation(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', ['enabled' => false]);
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'enabled' => true,
            'blacklist_phones' => ['+8801919012186'],
        ]);

        $product = $this->createProduct();

        $this->postJson('/api/orders', $this->orderPayload($product))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'phone');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_enabled_fraud_rules_enforce_daily_ip_limit_when_phone_changes(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', ['enabled' => false]);
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'enabled' => true,
            'max_orders_per_phone_per_day' => 99,
            'max_orders_per_ip_per_day' => 1,
        ]);

        $product = $this->createProduct();
        $this->withHeader('X-Forwarded-For', '203.0.113.40')
            ->postJson('/api/orders', $this->orderPayload($product, phone: '01919012186'))
            ->assertCreated();

        $this->withHeader('X-Forwarded-For', '203.0.113.40')
            ->postJson('/api/orders', $this->orderPayload(
                $product,
                phone: '01829312186',
                cartSessionId: 'another-cart-session',
            ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'ip');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_enabled_fraud_rules_block_blacklisted_ip_before_order_creation(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', ['enabled' => false]);
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'enabled' => true,
            'blacklist_ips' => ['203.0.113.50'],
        ]);

        $product = $this->createProduct();

        $this->withHeader('X-Forwarded-For', '203.0.113.50')
            ->postJson('/api/orders', $this->orderPayload($product))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'ip');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_local_daily_limits_stay_active_when_external_fraud_api_is_disabled(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => false,
            'surge_protection_enabled' => false,
        ]);
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'enabled' => false,
            'local_rules_enabled' => true,
            'max_orders_per_phone_per_day' => 1,
            'max_orders_per_ip_per_day' => 99,
        ]);

        $product = $this->createProduct();
        $this->postJson('/api/orders', $this->orderPayload($product))->assertCreated();

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            cartSessionId: 'second-cart-session',
        ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'phone');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_surge_protection_blocks_rotating_identity_orders_after_threshold(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => false,
            'surge_protection_enabled' => true,
            'surge_window_minutes' => 10,
            'surge_order_threshold' => 2,
            'surge_action' => 'block',
            'surge_message' => 'Unusual checkout activity detected.',
        ]);
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'local_rules_enabled' => false,
        ]);

        $product = $this->createProduct();
        $this->withHeader('X-Forwarded-For', '203.0.113.1')
            ->postJson('/api/orders', $this->orderPayload($product, phone: '01919012181', cartSessionId: 'cart-1'))
            ->assertCreated();
        $this->withHeader('X-Forwarded-For', '203.0.113.2')
            ->postJson('/api/orders', $this->orderPayload($product, phone: '01919012182', cartSessionId: 'cart-2'))
            ->assertCreated();

        $this->withHeader('X-Forwarded-For', '203.0.113.3')
            ->postJson('/api/orders', $this->orderPayload($product, phone: '01919012183', cartSessionId: 'cart-3'))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.matched_by.0', 'store_velocity')
            ->assertJsonPath('message', 'Unusual checkout activity detected.');

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_checkout_guard_can_be_disabled_for_incomplete_orders(): void
    {
        app(AdminSettingsService::class)->saveGroup('checkout_guard', [
            'enabled' => false,
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

    public function test_order_is_blocked_for_same_phone_when_session_changes(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            phone: '01406899706',
            cartSessionId: 'first-cart-session',
        ))->assertCreated();

        $this->postJson('/api/orders', $this->orderPayload(
            $product,
            quantity: 2,
            phone: '+8801406899706',
            cartSessionId: 'second-cart-session',
        ))
            ->assertStatus(429)
            ->assertJsonPath('checkout_guard.blocked', true)
            ->assertJsonPath('checkout_guard.matched_by.0', 'phone');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_rejects_duplicate_products_and_oversized_quantities(): void
    {
        $product = $this->createProduct();
        $duplicatePayload = $this->orderPayload($product);
        $duplicatePayload['items'][] = $duplicatePayload['items'][0];

        $this->postJson('/api/orders', $duplicatePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.product_id']);

        $this->postJson('/api/orders', $this->orderPayload($product, quantity: 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->inventory);
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

    private function enableSuspiciousOrderOtp(array $overrides = []): void
    {
        app(AdminSettingsService::class)->saveGroup('sms_integration', [
            'enabled' => true,
            'enable_order_otp' => false,
        ]);
        app(AdminSettingsService::class)->saveGroup('checkout_guard', array_merge([
            'enabled' => true,
            'block_by_phone' => true,
            'block_by_ip' => true,
            'block_by_device' => true,
            'cooldown_minutes' => 180,
            'suspicious_otp_enabled' => true,
            'suspicious_otp_by_phone' => true,
            'suspicious_otp_by_ip' => true,
            'suspicious_otp_by_device' => true,
        ], $overrides));
    }

    private function incompleteOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'order_number' => 'SBA-1000',
            'customer_name' => 'Test Customer',
            'email' => '01919012186-guest@guest.checkout',
            'phone' => '01919012186',
            'normalized_phone' => '01919012186',
            'status' => 'incomplete',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'subtotal' => 100,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 180,
            'shipping_address' => [
                'address' => 'Road 1, Dhaka',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
            ],
            'cart_hash' => hash('sha256', 'test-cart'),
            'normalized_address_hash' => hash('sha256', 'test-address'),
            'last_activity_at' => now(),
        ], $overrides);
    }
}
