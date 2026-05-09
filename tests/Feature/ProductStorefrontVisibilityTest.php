<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
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
