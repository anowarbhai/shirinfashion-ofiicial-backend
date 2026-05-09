<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StorefrontSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with(['category', 'activeVolumeDiscounts.freeProduct'])
            ->withCount([
                'reviews as approved_reviews_count' => fn ($builder) => $builder->where('status', 'approved'),
            ])
            ->withAvg([
                'reviews as approved_reviews_avg_rating' => fn ($builder) => $builder->where('status', 'approved'),
            ], 'rating')
            ->where('is_active', true)
            ->visibleInStorefront();

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

        $perPage = min(max((int) $request->integer('per_page', 12), 1), 100);
        $products = $query->latest()->paginate($perPage);
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
            'activeVolumeDiscounts.freeProduct',
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

        $product = $this->applyApprovedReviewMetrics($product);

        if ($product->hide_from_storefront) {
            $product->setAttribute('campaign_tracking', $this->campaignTrackingFor($product));
        }

        return response()->json([
            'data' => $product,
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
