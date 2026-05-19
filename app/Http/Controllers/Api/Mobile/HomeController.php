<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\CategoryResource;
use App\Http\Resources\Mobile\ProductSummaryResource;
use App\Http\Resources\Mobile\SliderResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Slider;
use App\Services\ProductReviewService;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(
        private readonly ThemeSettingsService $themeSettings,
        private readonly ProductReviewService $productReviews,
    )
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->integer('limit', 12), 4), 20);
        $appearance = $this->themeSettings->getGroup('appearance');

        $baseProductQuery = Product::query()
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

        $featuredProducts = (clone $baseProductQuery)
            ->where('is_featured', true)
            ->latest()
            ->limit($limit)
            ->get()
            ->pipe(fn ($products) => $this->productReviews->applyApprovedReviewMetricsToCollection($products));

        $newProducts = (clone $baseProductQuery)
            ->latest()
            ->limit($limit)
            ->get()
            ->pipe(fn ($products) => $this->productReviews->applyApprovedReviewMetricsToCollection($products));

        $categories = Category::query()
            ->with('parent')
            ->select('categories.*')
            ->selectSub($this->categoryProductsCountQuery(), 'products_count')
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->get();

        $sliders = Slider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(8)
            ->get();

        return response()->json([
            'data' => [
                'brand' => [
                    'company_name' => $appearance['company_name'] ?? 'Shirin Fashion',
                    'logo_url' => $this->mobileUrl((string) ($appearance['logo_url'] ?? ''), $request),
                    'favicon_url' => $this->mobileUrl((string) ($appearance['favicon_url'] ?? ''), $request),
                    'tagline' => $appearance['tagline'] ?? '',
                    'colors' => $appearance['colors'] ?? [],
                ],
                'sliders' => SliderResource::collection($sliders),
                'categories' => CategoryResource::collection($categories),
                'featured_products' => ProductSummaryResource::collection($featuredProducts),
                'new_products' => ProductSummaryResource::collection($newProducts),
            ],
        ]);
    }

    protected function mobileUrl(?string $url, Request $request): ?string
    {
        if (! is_string($url) || $url === '' || str_starts_with($url, 'data:image/')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($host) && is_string($path)) {
            if (in_array($host, ['localhost', '127.0.0.1'], true) && str_starts_with($path, '/storage/')) {
                return $this->mobileMediaProxyUrl($url, $request);
            }

            if ($host === 'cdn.shirinfashionbd.com' && str_starts_with($path, '/products/')) {
                return $this->mobileMediaProxyUrl($url, $request);
            }

            if ($host === 'shirin-fashion-cdn.s3.us-east-1.amazonaws.com' && str_starts_with($path, '/media/')) {
                return $this->mobileMediaProxyUrl($url, $request);
            }
        }

        return $url;
    }

    protected function mobileMediaProxyUrl(string $url, Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/')
            .'/api/v1/mobile/media?url='
            .rawurlencode($url);
    }

    protected function categoryProductsCountQuery()
    {
        return Product::query()
            ->selectRaw('COUNT(DISTINCT products.id)')
            ->leftJoin('category_product', 'category_product.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->visibleInStorefront()
            ->where(function ($query): void {
                $query->whereColumn('products.category_id', 'categories.id')
                    ->orWhereColumn('category_product.category_id', 'categories.id');
            });
    }
}
