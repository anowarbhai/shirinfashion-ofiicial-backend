<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\ProductDetailResource;
use App\Http\Resources\Mobile\ProductSummaryResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with(['category', 'categories', 'activeVolumeDiscounts.freeProduct'])
            ->withCount([
                'reviews as approved_reviews_count' => fn ($builder) => $builder->where('status', 'approved'),
            ])
            ->withAvg([
                'reviews as approved_reviews_avg_rating' => fn ($builder) => $builder->where('status', 'approved'),
            ], 'rating')
            ->withSum([
                'orderItems as sold_quantity' => fn ($builder) => $builder
                    ->where('is_free_gift', false)
                    ->whereHas('order', fn ($orderQuery) => $orderQuery
                        ->whereNotIn('status', ['incomplete', 'cancelled'])
                    ),
            ], 'quantity')
            ->where('is_active', true)
            ->visibleInStorefront();

        $this->applyFilters($query, $request);

        $sort = (string) $request->query('sort', 'latest');
        match ($sort) {
            'price_low' => $query->orderBy('price'),
            'price_high' => $query->orderByDesc('price'),
            'popular' => $query->orderByDesc('sold_quantity'),
            'featured' => $query->orderByDesc('is_featured')->latest(),
            default => $query->latest(),
        };

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
        $products = $query->paginate($perPage);
        $products->getCollection()->transform(
            fn (Product $product) => $this->applyApprovedReviewMetrics($product)
        );

        return response()->json([
            'data' => ProductSummaryResource::collection($products->getCollection()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->with([
                'category',
                'categories',
                'attributeTerms.attribute',
                'activeVolumeDiscounts.freeProduct',
                'reviews' => fn ($query) => $query
                    ->where('status', 'approved')
                    ->latest()
                    ->limit(20),
            ])
            ->withCount([
                'reviews as approved_reviews_count' => fn ($builder) => $builder->where('status', 'approved'),
            ])
            ->withAvg([
                'reviews as approved_reviews_avg_rating' => fn ($builder) => $builder->where('status', 'approved'),
            ], 'rating')
            ->withSum([
                'orderItems as sold_quantity' => fn ($builder) => $builder
                    ->where('is_free_gift', false)
                    ->whereHas('order', fn ($orderQuery) => $orderQuery
                        ->whereNotIn('status', ['incomplete', 'cancelled'])
                    ),
            ], 'quantity')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->visibleInStorefront()
            ->firstOrFail();

        return response()->json([
            'data' => new ProductDetailResource($this->applyApprovedReviewMetrics($product)),
        ]);
    }

    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->string('brand'));
        }

        if ($request->filled('category')) {
            $category = $request->string('category');
            $query->where(function ($builder) use ($category): void {
                $builder
                    ->whereHas('categories', fn ($categoryQuery) => $categoryQuery
                        ->where('slug', $category)
                        ->orWhere('name', $category))
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                        ->where('slug', $category)
                        ->orWhere('name', $category));
            });
        }

        if ($request->filled('skin_type')) {
            $query->whereJsonContains('skin_types', $request->string('skin_type')->toString());
        }

        if ($request->filled('price_min')) {
            $query->where('price', '>=', $request->float('price_min'));
        }

        if ($request->filled('price_max')) {
            $query->where('price', '<=', $request->float('price_max'));
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('stock_status')) {
            $query->where('stock_status', $request->query('stock_status') === 'out_of_stock' ? 'out_of_stock' : 'in_stock');
        }
    }

    protected function applyApprovedReviewMetrics(Product $product): Product
    {
        $approvedReviewCount = (int) ($product->approved_reviews_count ?? 0);
        $approvedReviewAverage = $approvedReviewCount > 0
            ? round((float) ($product->approved_reviews_avg_rating ?? 0), 1)
            : 0;

        $product->setAttribute('review_count', $approvedReviewCount);
        $product->setAttribute('rating', $approvedReviewAverage);
        $product->setAttribute('sold_quantity', (int) ($product->sold_quantity ?? 0));

        return $product;
    }
}

