<?php

namespace App\Http\Resources\Mobile;

use App\Http\Resources\Mobile\Concerns\ResolvesMobileUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSummaryResource extends JsonResource
{
    use ResolvesMobileUrls;

    public function toArray(Request $request): array
    {
        $categories = $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ])->values(), collect());

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'name' => $this->name,
            'brand' => $this->brand,
            'price' => (float) $this->price,
            'compare_price' => $this->compare_price === null ? null : (float) $this->compare_price,
            'rating' => (float) ($this->rating ?? 0),
            'review_count' => (int) ($this->review_count ?? 0),
            'sold_quantity' => (int) ($this->sold_quantity ?? 0),
            'badge' => $this->badge,
            'inventory' => (int) $this->inventory,
            'manage_stock' => (bool) $this->manage_stock,
            'stock_status' => $this->stock_status,
            'is_featured' => (bool) $this->is_featured,
            'show_trust_badges' => (bool) ($this->show_trust_badges ?? true),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'categories' => $categories,
            'thumbnail' => $this->mobileUrl($this->gallery[0] ?? null, $request),
            'gallery' => $this->mobileUrlList($this->gallery, $request),
            'short_description' => $this->short_description,
            'active_volume_discounts' => ProductVolumeDiscountResource::collection(
                $this->whenLoaded('activeVolumeDiscounts', fn () => $this->activeVolumeDiscounts, collect())
            ),
        ];
    }
}
