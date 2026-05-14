<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\CategoryResource;
use App\Http\Resources\Mobile\ProductSummaryResource;
use App\Http\Resources\Mobile\SliderResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Slider;
use App\Services\ThemeSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(private readonly ThemeSettingsService $themeSettings)
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
            ->map(fn (Product $product) => $this->applyApprovedReviewMetrics($product));

        $newProducts = (clone $baseProductQuery)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => $this->applyApprovedReviewMetrics($product));

        $categories = Category::query()
            ->with('parent')
            ->withCount([
                'relatedProducts as products_count' => fn ($query) => $query
                    ->where('is_active', true)
                    ->visibleInStorefront(),
            ])
            ->where('is_featured', true)
            ->orderBy('name')
            ->limit(12)
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
        }

        return $url;
    }

    protected function mobileMediaProxyUrl(string $url, Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/')
            .'/api/v1/mobile/media?url='
            .rawurlencode($url);
    }
}
