<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicOrderTrackingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_requires_matching_phone_and_hides_private_order_data(): void
    {
        $order = Order::query()->create([
            'order_number' => 'SBA-12345678',
            'customer_name' => 'Private Customer',
            'email' => 'private@example.test',
            'phone' => '01712345678',
            'normalized_phone' => '01712345678',
            'client_ip' => '127.0.0.1',
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => 600,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 680,
            'shipping_address' => ['address' => 'Private address'],
            'tracking_number' => 'TRK-123456',
            'placed_at' => now(),
        ]);

        $this->postJson('/api/orders/track', ['order_number' => $order->order_number])
            ->assertUnprocessable();

        $this->postJson('/api/orders/track', [
            'order_number' => $order->order_number,
            'phone' => '01812345678',
        ])->assertNotFound();

        $this->postJson('/api/orders/track', [
            'order_number' => $order->order_number,
            'phone' => '01712345678',
        ])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.shipping_address')
            ->assertJsonMissingPath('data.client_ip')
            ->assertJsonMissingPath('data.fraud_check');
    }

    public function test_purchase_verification_requires_a_valid_server_signature(): void
    {
        $order = Order::query()->create([
            'order_number' => 'SBA-87654321',
            'customer_name' => 'Verified Customer',
            'email' => 'verified@example.test',
            'phone' => '01712345678',
            'normalized_phone' => '01712345678',
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => 600,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 680,
            'shipping_address' => ['address' => 'Dhaka'],
            'placed_at' => now(),
        ]);
        $token = hash_hmac(
            'sha256',
            'purchase|'.$order->id.'|'.$order->order_number,
            (string) config('app.key'),
        );

        $this->postJson('/api/orders/verify-purchase', [
            'order_number' => $order->order_number,
            'verification_token' => str_repeat('0', 64),
        ])->assertNotFound()->assertJsonPath('confirmed', false);

        $this->postJson('/api/orders/verify-purchase', [
            'order_number' => $order->order_number,
            'verification_token' => $token,
        ])
            ->assertOk()
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('data.grand_total', '680.00');
    }
}
