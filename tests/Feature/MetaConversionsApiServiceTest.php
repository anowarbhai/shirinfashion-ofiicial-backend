<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Services\MetaConversionsApiService;
use App\Support\SensitiveSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConversionsApiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_deduplicated_campaign_purchase_with_hashed_customer_data(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1']),
        ]);
        $this->storeFacebookSettings();
        StorefrontPage::query()->create([
            'title' => 'Night Cream',
            'slug' => 'night-cream',
            'status' => 'published',
            'template' => 'landing',
            'campaign_facebook_pixel_ids' => ['campaign-night'],
            'builder_json' => [],
        ]);
        $order = $this->createOrder([
            'meta_landing_page_slug' => 'night-cream',
            'meta_fbp' => 'fb.1.1712345678.123456789',
            'meta_fbc' => 'fb.1.1712345678.click-id',
            'meta_event_source_url' => 'https://shirinfashion.com.bd/landing/night-cream?utm_source=facebook',
            'meta_user_agent' => 'Mozilla/5.0 Test Browser',
        ]);

        app(MetaConversionsApiService::class)->sendPurchase($order->load('items'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($order): bool {
            $event = $request['data'][0] ?? [];
            $userData = $event['user_data'] ?? [];

            return $request->url() === 'https://graph.facebook.com/v24.0/222222222222222/events'
                && $request['access_token'] === 'campaign-secret'
                && $event['event_name'] === 'Purchase'
                && $event['event_id'] === 'purchase:'.$order->order_number
                && $event['custom_data']['currency'] === 'BDT'
                && $event['custom_data']['value'] === 680.0
                && $event['custom_data']['content_ids'] === ['NIGHT-001']
                && $userData['ph'][0] === hash('sha256', '8801712345678')
                && $userData['em'][0] === hash('sha256', 'buyer@example.com')
                && $userData['fbp'] === 'fb.1.1712345678.123456789'
                && $userData['fbc'] === 'fb.1.1712345678.click-id'
                && $request['test_event_code'] === 'TEST123';
        });
        $this->assertNotNull($order->fresh()->meta_purchase_sent_at);

        app(MetaConversionsApiService::class)->sendPurchase($order->fresh('items'));
        Http::assertSentCount(1);
    }

    public function test_it_uses_the_global_pixel_when_the_landing_page_has_no_campaign_pixel(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $this->storeFacebookSettings();
        StorefrontPage::query()->create([
            'title' => 'General Offer',
            'slug' => 'general-offer',
            'status' => 'published',
            'template' => 'landing',
            'campaign_facebook_pixel_ids' => [],
            'builder_json' => [],
        ]);
        $order = $this->createOrder(['meta_landing_page_slug' => 'general-offer']);

        app(MetaConversionsApiService::class)->sendPurchase($order->load('items'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v24.0/111111111111111/events'
            && $request['access_token'] === 'global-secret'
        );
    }

    public function test_it_uses_the_selected_campaign_pixel_for_a_single_product_page_order(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $this->storeFacebookSettings();
        $category = Category::query()->create(['name' => 'Skincare', 'slug' => 'skincare']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Night Cream',
            'slug' => 'night-cream',
            'sku' => 'NIGHT-001',
            'brand' => 'Shirin Fashion',
            'price' => 600,
            'inventory' => 10,
            'gallery' => [],
            'is_active' => true,
            'hide_from_storefront' => false,
            'campaign_facebook_pixel_ids' => ['campaign-night'],
        ]);
        $order = $this->createOrder([
            'meta_event_source_url' => 'https://shirinfashion.com.bd/products/night-cream?fbclid=click-id',
            'meta_campaign_facebook_pixel_ids' => ['campaign-night'],
        ]);
        $order->items()->update(['product_id' => $product->id]);

        app(MetaConversionsApiService::class)->sendPurchase($order->fresh('items'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v24.0/222222222222222/events'
            && $request['access_token'] === 'campaign-secret'
            && $request['data'][0]['event_source_url'] === 'https://shirinfashion.com.bd/products/night-cream?fbclid=click-id'
        );
    }

    public function test_provider_failure_never_blocks_the_order_and_remains_retryable(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Temporary failure']], 503)]);
        $this->storeFacebookSettings();
        $order = $this->createOrder();

        app(MetaConversionsApiService::class)->sendPurchase($order->load('items'));

        $this->assertNull($order->fresh()->meta_purchase_sent_at);
        $this->assertSame(1, $order->fresh()->meta_purchase_attempts);
        $this->assertNotNull($order->fresh()->meta_purchase_last_attempt_at);
    }

    public function test_it_never_sends_purchase_for_an_unconfirmed_order(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $this->storeFacebookSettings();
        $order = $this->createOrder([
            'status' => 'pending',
            'payment_method' => 'sslcommerz',
            'payment_status' => 'pending',
            'placed_at' => null,
        ]);

        app(MetaConversionsApiService::class)->sendPurchase($order->load('items'));

        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->meta_purchase_attempts);
    }

    public function test_tracking_context_is_not_exposed_when_an_order_is_serialized(): void
    {
        $order = $this->createOrder([
            'meta_fbp' => 'fb.1.1712345678.123456789',
            'meta_fbc' => 'fb.1.1712345678.click-id',
            'meta_user_agent' => 'Secret Browser Context',
        ]);

        $serialized = $order->toArray();

        $this->assertArrayNotHasKey('meta_fbp', $serialized);
        $this->assertArrayNotHasKey('meta_fbc', $serialized);
        $this->assertArrayNotHasKey('meta_user_agent', $serialized);
    }

    public function test_retry_command_resends_a_recent_failed_purchase(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $this->storeFacebookSettings();
        $order = $this->createOrder([
            'meta_user_agent' => 'Mozilla/5.0',
            'meta_purchase_attempts' => 1,
            'meta_purchase_last_attempt_at' => now()->subMinutes(20),
        ]);

        Artisan::call('meta:retry-purchases');

        Http::assertSentCount(1);
        $this->assertNotNull($order->fresh()->meta_purchase_sent_at);
        $this->assertSame(2, $order->fresh()->meta_purchase_attempts);
    }

    private function storeFacebookSettings(): void
    {
        StorefrontSetting::query()->create([
            'key' => 'facebook_marketing',
            'value' => SensitiveSettings::protectFacebook([
                'pixel_enabled' => true,
                'pixel_id' => '111111111111111',
                'capi_enabled' => true,
                'access_token' => 'global-secret',
                'test_event_code' => '',
                'campaign_pixels' => [[
                    'id' => 'campaign-night',
                    'name' => 'Night Campaign',
                    'pixel_id' => '222222222222222',
                    'capi_enabled' => true,
                    'access_token' => 'campaign-secret',
                    'test_event_code' => 'TEST123',
                    'enabled' => true,
                ]],
            ]),
        ]);
    }

    private function createOrder(array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'SBA-12345678',
            'customer_name' => 'Anowar Hossain',
            'email' => 'buyer@example.com',
            'phone' => '01712345678',
            'client_ip' => '203.0.113.10',
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'pending_collection',
            'subtotal' => 600,
            'discount_total' => 0,
            'shipping_total' => 80,
            'grand_total' => 680,
            'shipping_address' => [
                'address' => 'Dhaka, Bangladesh',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
            ],
            'placed_at' => now(),
        ], $overrides));
        $order->items()->create([
            'product_name' => 'Night Cream',
            'sku' => 'NIGHT-001',
            'price' => 600,
            'quantity' => 1,
            'line_total' => 600,
            'is_free_gift' => false,
        ]);

        return $order;
    }
}
