<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\AdminSettingsService;
use App\Services\FraudCheckerService;
use App\Services\SslCommerzService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SslCommerzFraudCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_sslcommerz_ipn_runs_fraud_check_after_payment_is_finalized(): void
    {
        app(AdminSettingsService::class)->saveGroup('fraud_checker', [
            'enabled' => true,
            'provider' => 'onesoftcode',
            'api_key' => 'test-key',
            'onesoftcode_api_key' => 'test-key',
        ]);

        $order = Order::query()->create([
            'order_number' => 'SBA-TESTPAY',
            'customer_name' => 'Test Customer',
            'email' => 'test@example.com',
            'phone' => '01729312186',
            'normalized_phone' => '01729312186',
            'status' => 'pending',
            'payment_method' => 'sslcommerz',
            'payment_status' => 'pending',
            'subtotal' => 100,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 180,
            'shipping_address' => [
                'address' => 'Ashulia',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
            ],
            'cart_hash' => hash('sha256', 'sslcommerz-cart'),
            'normalized_address_hash' => hash('sha256', 'sslcommerz-address'),
            'last_activity_at' => now(),
        ]);

        $this->mock(SslCommerzService::class, function ($mock): void {
            $mock->shouldReceive('validateTransaction')
                ->once()
                ->with('valid-payment')
                ->andReturn([
                    'status' => 'VALID',
                    'tran_id' => 'SBA-TESTPAY',
                    'amount' => '180.00',
                ]);
            $mock->shouldReceive('isSuccessful')
                ->once()
                ->andReturnTrue();
        });

        $this->mock(FraudCheckerService::class, function ($mock): void {
            $mock->shouldReceive('check')
                ->once()
                ->with('01729312186')
                ->andReturn([
                    'phone' => '01729312186',
                    'status' => 'Safe',
                    'score' => 90,
                    'total_parcel' => 10,
                    'success_parcel' => 9,
                    'cancel_parcel' => 1,
                    'source' => 'TEST',
                    'couriers' => [],
                ]);
        });

        $this->postJson('/api/payments/sslcommerz/ipn', [
            'value_a' => $order->id,
            'tran_id' => $order->order_number,
            'val_id' => 'valid-payment',
        ])->assertOk();

        $order->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame('Safe', $order->fraud_check['status'] ?? null);
    }
}
