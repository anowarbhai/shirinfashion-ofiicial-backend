<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductReviewService
{
    public function approvedReviewQuery(Product $product): Builder
    {
        return Review::query()
            ->where('status', 'approved')
            ->whereHas('product', function (Builder $query) use ($product): void {
                $reviewGroupKey = $this->reviewGroupKey($product);

                if ($reviewGroupKey !== null) {
                    $query->where('review_group_key', $reviewGroupKey);

                    return;
                }

                $query->whereKey($product->id);
            });
    }

    public function loadApprovedReviews(Product $product, ?int $limit = null): Product
    {
        $query = $this->approvedReviewQuery($product)
            ->latest();

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $product->setRelation('reviews', $query->get());
    }

    public function applyApprovedReviewMetrics(Product $product): Product
    {
        $approved = $this->approvedReviewQuery($product);
        $count = (int) (clone $approved)->count();
        $average = $count > 0
            ? round((float) (clone $approved)->avg('rating'), 1)
            : 0;

        $product->setAttribute('review_count', $count);
        $product->setAttribute('rating', $average);
        $product->setAttribute('sold_quantity', (int) ($product->sold_quantity ?? 0));

        return $product;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    public function applyApprovedReviewMetricsToCollection(Collection $products): Collection
    {
        return $products->map(fn (Product $product) => $this->applyApprovedReviewMetrics($product));
    }

    public function refreshProductGroupMetrics(Product $product): void
    {
        $products = $this->relatedProducts($product);

        foreach ($products as $relatedProduct) {
            $approved = $this->approvedReviewQuery($relatedProduct);
            $count = (int) (clone $approved)->count();
            $average = $count > 0
                ? round((float) (clone $approved)->avg('rating'), 1)
                : 0;

            $relatedProduct->update([
                'rating' => $average,
                'review_count' => $count,
            ]);
        }
    }

    /**
     * @return Collection<int, Product>
     */
    private function relatedProducts(Product $product): Collection
    {
        $reviewGroupKey = $this->reviewGroupKey($product);

        if ($reviewGroupKey === null) {
            return Product::query()
                ->whereKey($product->id)
                ->get();
        }

        return Product::query()
            ->where('review_group_key', $reviewGroupKey)
            ->get();
    }

    private function reviewGroupKey(Product $product): ?string
    {
        $key = trim((string) ($product->review_group_key ?? ''));

        return $key !== '' ? $key : null;
    }
}
