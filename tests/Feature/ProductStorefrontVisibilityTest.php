<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStorefrontVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_campaign_product_is_excluded_from_public_catalog_but_detail_page_works(): void
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);

        $visibleProduct = $this->createProduct($category, 'Visible Serum', 'visible-serum');
        $hiddenProduct = $this->createProduct($category, 'Campaign Serum', 'campaign-serum', true);

        $catalogResponse = $this->getJson('/api/products');

        $catalogResponse
            ->assertOk()
            ->assertJsonFragment(['slug' => $visibleProduct->slug])
            ->assertJsonMissing(['slug' => $hiddenProduct->slug]);

        $this->getJson('/api/products/'.$hiddenProduct->slug)
            ->assertOk()
            ->assertJsonPath('data.slug', $hiddenProduct->slug);
    }

    public function test_hidden_campaign_product_exposes_selected_campaign_tracking(): void
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);
        StorefrontSetting::query()->create([
            'key' => 'facebook_marketing',
            'value' => [
                'campaign_pixels' => [
                    ['id' => 'fb_marketer_one', 'name' => 'Marketer One', 'pixel_id' => '12345678901', 'enabled' => true],
                    ['id' => 'fb_disabled', 'name' => 'Disabled', 'pixel_id' => '99999999999', 'enabled' => false],
                ],
            ],
        ]);
        StorefrontSetting::query()->create([
            'key' => 'google_marketing',
            'value' => [
                'campaign_tags' => [
                    ['id' => 'gg_marketer_one', 'name' => 'Marketer One GTM', 'type' => 'gtm', 'tracking_id' => 'GTM-ABC123', 'enabled' => true],
                ],
            ],
        ]);

        $product = $this->createProduct($category, 'Campaign Serum', 'campaign-serum', true);
        $product->update([
            'campaign_facebook_pixel_ids' => ['fb_marketer_one', 'fb_disabled'],
            'campaign_google_tag_ids' => ['gg_marketer_one'],
        ]);

        $this->getJson('/api/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.campaign_tracking.facebook_pixels.0.pixel_id', '12345678901')
            ->assertJsonPath('data.campaign_tracking.google_tags.0.tracking_id', 'GTM-ABC123')
            ->assertJsonMissing(['pixel_id' => '99999999999']);
    }

    public function test_visible_product_exposes_selected_product_page_tracking(): void
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);
        StorefrontSetting::query()->create([
            'key' => 'facebook_marketing',
            'value' => [
                'campaign_pixels' => [
                    ['id' => 'fb_product', 'name' => 'Product Pixel', 'pixel_id' => '12345678901', 'enabled' => true],
                ],
            ],
        ]);
        StorefrontSetting::query()->create([
            'key' => 'google_marketing',
            'value' => [
                'campaign_tags' => [
                    ['id' => 'gg_product', 'name' => 'Product GTM', 'type' => 'gtm', 'tracking_id' => 'GTM-PRODUCT', 'enabled' => true],
                ],
            ],
        ]);

        $product = $this->createProduct($category, 'Visible Serum', 'visible-serum');
        $product->update([
            'campaign_facebook_pixel_ids' => ['fb_product'],
            'campaign_google_tag_ids' => ['gg_product'],
        ]);

        $this->getJson('/api/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.campaign_tracking.facebook_pixels.0.pixel_id', '12345678901')
            ->assertJsonPath('data.campaign_tracking.google_tags.0.tracking_id', 'GTM-PRODUCT');
    }

    public function test_inactive_product_detail_is_not_publicly_visible(): void
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);
        $product = $this->createProduct($category, 'Draft Serum', 'draft-serum');
        $product->update(['is_active' => false]);

        $this->getJson('/api/products/'.$product->slug)
            ->assertNotFound();
    }

    private function createProduct(
        Category $category,
        string $name,
        string $slug,
        bool $hideFromStorefront = false,
    ): Product {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'sku' => strtoupper(str_replace('-', '-', $slug)),
            'brand' => 'Shirin Fashion',
            'short_description' => 'Short description',
            'description' => 'Full description',
            'price' => 100,
            'compare_price' => null,
            'inventory' => 10,
            'gallery' => [],
            'is_active' => true,
            'is_featured' => true,
            'hide_from_storefront' => $hideFromStorefront,
        ]);
    }
}
