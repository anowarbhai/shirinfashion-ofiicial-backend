<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_detail_shows_approved_reviews_from_same_review_group(): void
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);
        $mainProduct = $this->createProduct($category, 'Glow Cream', 'glow-cream', 'glow-cream');
        $campaignProduct = $this->createProduct($category, 'Glow Cream Campaign', 'glow-cream-campaign', 'glow-cream');
        $otherProduct = $this->createProduct($category, 'Other Cream', 'other-cream', 'other-cream');

        Review::query()->create([
            'product_id' => $mainProduct->id,
            'author_name' => 'Tanjiila Rahman',
            'author_phone' => '01700000000',
            'rating' => 5,
            'body' => 'Skin feels fresh after using this product.',
            'status' => 'approved',
        ]);
        Review::query()->create([
            'product_id' => $campaignProduct->id,
            'author_name' => 'Nusrat Jahan',
            'author_phone' => '01700000001',
            'rating' => 4,
            'body' => 'Soft feel and good packaging.',
            'status' => 'approved',
        ]);
        Review::query()->create([
            'product_id' => $otherProduct->id,
            'author_name' => 'Other Customer',
            'author_phone' => '01700000002',
            'rating' => 5,
            'body' => 'This should not appear.',
            'status' => 'approved',
        ]);

        $this->getJson('/api/products/'.$campaignProduct->slug)
            ->assertOk()
            ->assertJsonPath('data.review_count', 2)
            ->assertJsonPath('data.rating', '4.5')
            ->assertJsonFragment(['author_name' => 'Tanjiila Rahman'])
            ->assertJsonFragment(['author_name' => 'Nusrat Jahan'])
            ->assertJsonMissing(['author_name' => 'Other Customer']);
    }

    public function test_campaign_product_can_use_selected_product_as_review_source(): void
    {
        $category = Category::query()->create([
            'name' => 'Skincare',
            'slug' => 'skincare',
        ]);
        $mainProduct = $this->createProduct($category, 'Glow Cream', 'glow-cream', null);
        $campaignProduct = $this->createProduct($category, 'Glow Cream Campaign', 'glow-cream-campaign', null);
        $campaignProduct->update(['review_source_product_id' => $mainProduct->id]);

        Review::query()->create([
            'product_id' => $mainProduct->id,
            'author_name' => 'Main Customer',
            'author_phone' => '01700000003',
            'rating' => 5,
            'body' => 'Main product review.',
            'status' => 'approved',
        ]);
        Review::query()->create([
            'product_id' => $campaignProduct->id,
            'author_name' => 'Campaign Customer',
            'author_phone' => '01700000004',
            'rating' => 4,
            'body' => 'Campaign product review.',
            'status' => 'approved',
        ]);

        $this->getJson('/api/products/'.$campaignProduct->slug)
            ->assertOk()
            ->assertJsonPath('data.review_count', 2)
            ->assertJsonPath('data.rating', '4.5')
            ->assertJsonFragment(['author_name' => 'Main Customer'])
            ->assertJsonFragment(['author_name' => 'Campaign Customer']);

        $this->getJson('/api/products/'.$mainProduct->slug)
            ->assertOk()
            ->assertJsonPath('data.review_count', 2)
            ->assertJsonFragment(['author_name' => 'Campaign Customer']);
    }

    private function createProduct(
        Category $category,
        string $name,
        string $slug,
        ?string $reviewGroupKey,
    ): Product {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'sku' => strtoupper($slug),
            'review_group_key' => $reviewGroupKey,
            'brand' => 'Shirin Fashion',
            'short_description' => 'Short description',
            'description' => 'Full description',
            'price' => 100,
            'compare_price' => null,
            'inventory' => 10,
            'gallery' => [],
            'is_active' => true,
            'is_featured' => true,
            'hide_from_storefront' => false,
        ]);
    }
}
