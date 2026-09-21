<?php

namespace Tests\Feature;

use App\Services\AdminSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPageSettingsPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_hide_sslcommerz_when_gateway_is_disabled(): void
    {
        $settings = app(AdminSettingsService::class);
        $productPage = $settings->defaults()['product_page'];
        $sslCommerzIndex = collect($productPage['paymentMethods'])->search(
            fn (array $method) => ($method['id'] ?? null) === 'sslcommerz'
        );
        $productPage['paymentMethods'][$sslCommerzIndex]['active'] = true;

        $settings->saveGroup('product_page', $productPage, true);
        $settings->saveGroup('payment_gateway', [
            'enabled' => false,
            'store_id' => 'sandbox-store',
            'store_password' => 'sandbox-password',
        ]);

        $response = $this->getJson('/api/product-page-settings')->assertOk();
        $sslCommerz = collect($response->json('data.paymentMethods'))->firstWhere('id', 'sslcommerz');

        $this->assertIsArray($sslCommerz);
        $this->assertFalse($sslCommerz['active']);
    }

    public function test_public_settings_show_sslcommerz_when_gateway_is_enabled_and_configured(): void
    {
        $settings = app(AdminSettingsService::class);
        $productPage = $settings->defaults()['product_page'];
        $sslCommerzIndex = collect($productPage['paymentMethods'])->search(
            fn (array $method) => ($method['id'] ?? null) === 'sslcommerz'
        );
        $productPage['paymentMethods'][$sslCommerzIndex]['active'] = true;

        $settings->saveGroup('product_page', $productPage, true);
        $settings->saveGroup('payment_gateway', [
            'enabled' => true,
            'store_id' => 'sandbox-store',
            'store_password' => 'sandbox-password',
        ]);

        $response = $this->getJson('/api/product-page-settings')->assertOk();
        $sslCommerz = collect($response->json('data.paymentMethods'))->firstWhere('id', 'sslcommerz');

        $this->assertIsArray($sslCommerz);
        $this->assertTrue($sslCommerz['active']);
    }
}
