<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Services\ProductReviewService;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly ProductReviewService $productReviews)
    {
    }

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

        if ($request->filled('brand')) {
            $query->where('brand', $request->string('brand'));
        }

        if ($request->filled('category')) {
            $category = $request->string('category');

            $query->where(function ($builder) use ($category): void {
                $builder
                    ->whereHas('categories', function ($categoryQuery) use ($category): void {
                        $categoryQuery->where('slug', $category)
                            ->orWhere('name', $category);
                    })
                    ->orWhereHas('category', function ($categoryQuery) use ($category): void {
                        $categoryQuery->where('slug', $category)
                            ->orWhere('name', $category);
                    });
            });
        }

        if ($request->filled('skin_type')) {
            $skinType = $request->string('skin_type')->toString();
            $query->whereJsonContains('skin_types', $skinType);
        }

        if ($request->filled('price_max')) {
            $query->where('price', '<=', $request->float('price_max'));
        }

        $perPage = min(max((int) $request->integer('per_page', 12), 1), 100);
        $products = $query->latest()->paginate($perPage);
        $products->setCollection(
            $this->productReviews->applyApprovedReviewMetricsToCollection($products->getCollection())
        );

        return response()->json($products);
    }

    public function sitemap(): JsonResponse
    {
        return response()->json([
            'data' => Product::query()
                ->where('is_active', true)
                ->orderBy('updated_at', 'desc')
                ->get(['slug', 'updated_at', 'hide_from_storefront'])
                ->map(fn (Product $product): array => [
                    'slug' => $product->slug,
                    'updated_at' => optional($product->updated_at)->toISOString(),
                    'is_campaign' => (bool) $product->hide_from_storefront,
                ])
                ->values(),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        abort_if(! $product->is_active, 404);

        $product->load([
            'category',
            'categories',
            'attributeTerms.attribute',
            'activeVariations',
            'activeVolumeDiscounts.freeProduct',
        ]);
        $product->loadCount([
            'reviews as approved_reviews_count' => fn ($builder) => $builder->where('status', 'approved'),
        ]);
        $product->loadAvg([
            'reviews as approved_reviews_avg_rating' => fn ($builder) => $builder->where('status', 'approved'),
        ], 'rating');
        $product->loadSum([
            'orderItems as sold_quantity' => fn ($builder) => $builder
                ->where('is_free_gift', false)
                ->whereHas('order', fn ($orderQuery) => $orderQuery
                    ->whereNotIn('status', ['incomplete', 'cancelled'])
                ),
        ], 'quantity');

        $product = $this->productReviews->applyApprovedReviewMetrics($product);
        $this->productReviews->loadApprovedReviews($product);

        $product->setAttribute('campaign_tracking', $this->campaignTrackingFor($product));

        $product->setAttribute('image_alt_texts', $this->imageAltTextsFor($product));

        return response()->json([
            'data' => $product,
        ]);
    }

    private function imageAltTextsFor(Product $product): array
    {
        $gallery = collect($product->gallery)
            ->filter(fn ($image): bool => is_string($image) && trim($image) !== '')
            ->values();

        if ($gallery->isEmpty()) {
            return [];
        }

        $storedUrls = $gallery
            ->map(fn (string $image): ?string => MediaUrl::normalizeStored($image))
            ->filter()
            ->values();

        if ($storedUrls->isEmpty()) {
            return [];
        }

        $altTextByStoredUrl = MediaAsset::query()
            ->whereIn('url', $storedUrls->all())
            ->whereNotNull('alt_text')
            ->get(['url', 'alt_text'])
            ->mapWithKeys(fn (MediaAsset $media): array => [
                (string) $media->getRawOriginal('url') => trim((string) $media->alt_text),
            ]);

        return $gallery
            ->mapWithKeys(function (string $image) use ($altTextByStoredUrl): array {
                $storedUrl = MediaUrl::normalizeStored($image);
                $altText = is_string($storedUrl) ? $altTextByStoredUrl->get($storedUrl) : null;

                return is_string($altText) && $altText !== '' ? [$image => $altText] : [];
            })
            ->all();
    }

    private function campaignTrackingFor(Product $product): array
    {
        $facebookIds = collect($product->campaign_facebook_pixel_ids ?? [])
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values();
        $googleIds = collect($product->campaign_google_tag_ids ?? [])
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values();

        $facebookSettings = $this->settings('facebook_marketing');
        $googleSettings = $this->settings('google_marketing');

        return [
            'facebook_pixels' => collect($facebookSettings['campaign_pixels'] ?? [])
                ->filter(fn ($pixel): bool => is_array($pixel))
                ->filter(fn (array $pixel): bool => $facebookIds->contains((string) ($pixel['id'] ?? '')))
                ->filter(fn (array $pixel): bool => (bool) ($pixel['enabled'] ?? true))
                ->map(fn (array $pixel): array => [
                    'id' => (string) ($pixel['id'] ?? ''),
                    'pixel_id' => trim((string) ($pixel['pixel_id'] ?? '')),
                ])
                ->filter(fn (array $pixel): bool => $pixel['id'] !== '' && $pixel['pixel_id'] !== '')
                ->values()
                ->all(),
            'google_tags' => collect($googleSettings['campaign_tags'] ?? [])
                ->filter(fn ($tag): bool => is_array($tag))
                ->filter(fn (array $tag): bool => $googleIds->contains((string) ($tag['id'] ?? '')))
                ->filter(fn (array $tag): bool => (bool) ($tag['enabled'] ?? true))
                ->map(fn (array $tag): array => [
                    'id' => (string) ($tag['id'] ?? ''),
                    'type' => in_array($tag['type'] ?? '', ['gtm', 'ga4', 'google_ads'], true) ? (string) $tag['type'] : 'gtm',
                    'tracking_id' => trim((string) ($tag['tracking_id'] ?? '')),
                ])
                ->filter(fn (array $tag): bool => $tag['id'] !== '' && $tag['tracking_id'] !== '')
                ->values()
                ->all(),
        ];
    }

    private function settings(string $key): array
    {
        $stored = StorefrontSetting::query()
            ->where('key', $key)
            ->value('value');

        return is_array($stored) ? $stored : [];
    }
}
