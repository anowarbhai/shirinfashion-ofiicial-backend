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
        $productPage['paymentMethods'][1]['active'] = true;

        $settings->saveGroup('product_page', $productPage, true);
        $settings->saveGroup('payment_gateway', [
            'enabled' => false,
            'store_id' => 'sandbox-store',
            'store_password' => 'sandbox-password',
        ]);

        $this->getJson('/api/product-page-settings')
            ->assertOk()
            ->assertJsonPath('data.paymentMethods.1.id', 'sslcommerz')
            ->assertJsonPath('data.paymentMethods.1.active', false);
    }

    public function test_public_settings_show_sslcommerz_when_gateway_is_enabled_and_configured(): void
    {
        $settings = app(AdminSettingsService::class);
        $productPage = $settings->defaults()['product_page'];
        $productPage['paymentMethods'][1]['active'] = true;

        $settings->saveGroup('product_page', $productPage, true);
        $settings->saveGroup('payment_gateway', [
            'enabled' => true,
            'store_id' => 'sandbox-store',
            'store_password' => 'sandbox-password',
        ]);

        $this->getJson('/api/product-page-settings')
            ->assertOk()
            ->assertJsonPath('data.paymentMethods.1.id', 'sslcommerz')
            ->assertJsonPath('data.paymentMethods.1.active', true);
    }
}
