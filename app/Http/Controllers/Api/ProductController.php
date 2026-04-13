<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with('category')
            ->withCount([
                'reviews as approved_reviews_count' => fn ($builder) => $builder->where('status', 'approved'),
            ])
            ->withAvg([
                'reviews as approved_reviews_avg_rating' => fn ($builder) => $builder->where('status', 'approved'),
            ], 'rating')
            ->where('is_active', true);

        if ($request->filled('brand')) {
            $query->where('brand', $request->string('brand'));
        }

        if ($request->filled('category')) {
            $query->whereHas('category', function ($builder) use ($request): void {
                $builder->where('slug', $request->string('category'))
                    ->orWhere('name', $request->string('category'));
            });
        }

        if ($request->filled('skin_type')) {
            $skinType = $request->string('skin_type')->toString();
            $query->whereJsonContains('skin_types', $skinType);
        }

        if ($request->filled('price_max')) {
            $query->where('price', '<=', $request->float('price_max'));
        }

        $products = $query->latest()->paginate(12);
        $products->getCollection()->transform(
            fn (Product $product) => $this->applyApprovedReviewMetrics($product)
        );

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load([
            'category',
            'attributeTerms.attribute',
            'reviews' => fn ($query) => $query
                ->where('status', 'approved')
                ->latest(),
        ]);
        $product->loadCount([
            'reviews as approved_reviews_count' => fn ($builder) => $builder->where('status', 'approved'),
        ]);
        $product->loadAvg([
            'reviews as approved_reviews_avg_rating' => fn ($builder) => $builder->where('status', 'approved'),
        ], 'rating');

        return response()->json([
            'data' => $this->applyApprovedReviewMetrics($product),
        ]);
    }

    private function applyApprovedReviewMetrics(Product $product): Product
    {
        $approvedReviewCount = (int) ($product->approved_reviews_count ?? 0);
        $approvedReviewAverage = $approvedReviewCount > 0
            ? round((float) ($product->approved_reviews_avg_rating ?? 0), 1)
            : 0;

        $product->setAttribute('review_count', $approvedReviewCount);
        $product->setAttribute('rating', $approvedReviewAverage);

        return $product;
    }
}
