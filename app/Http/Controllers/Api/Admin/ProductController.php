<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(protected AdminAuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 500);

        return response()->json([
            'data' => Product::with(['category', 'categories', 'tags', 'attributeTerms.attribute'])
                ->latest()
                ->paginate($perPage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $product = Product::create($validated['attributes']);
        $product->categories()->sync($validated['category_ids']);
        $product->tags()->sync($validated['tag_ids']);
        $product->attributeTerms()->sync($validated['attribute_term_ids']);

        $this->auditLogger->log(
            $request,
            'product.created',
            "Created product {$product->name}.",
            $product,
            ['sku' => $product->sku, 'price' => $product->price],
        );

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => $product->load(['category', 'categories', 'tags', 'attributeTerms.attribute']),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product->load('category', 'categories', 'reviews', 'tags', 'attributeTerms.attribute'),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $before = $product->only(['name', 'sku', 'price', 'inventory', 'is_active', 'is_featured', 'hide_from_storefront']);
        $validated = $this->validated($request, $product->id);
        $product->update($validated['attributes']);
        $product->categories()->sync($validated['category_ids']);
        $product->tags()->sync($validated['tag_ids']);
        $product->attributeTerms()->sync($validated['attribute_term_ids']);
        $updated = $product->fresh();

        $this->auditLogger->log(
            $request,
            'product.updated',
            "Updated product {$updated->name}.",
            $updated,
            [
                'before' => $before,
                'after' => $updated->only(['name', 'sku', 'price', 'inventory', 'is_active', 'is_featured', 'hide_from_storefront']),
            ],
        );

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => $updated->load(['category', 'categories', 'tags', 'attributeTerms.attribute']),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $metadata = ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name];
        $name = $product->name;
        $product->delete();

        $this->auditLogger->log(
            $request,
            'product.deleted',
            "Deleted product {$name}.",
            null,
            $metadata,
        );

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    protected function validated(Request $request, ?int $productId = null): array
    {
        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', 'unique:products,sku,'.($productId ?? 'NULL').',id'],
            'brand' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric'],
            'compare_price' => ['nullable', 'numeric'],
            'inventory' => ['required', 'integer', 'min:0'],
            'badge' => ['nullable', 'string', 'max:255'],
            'skin_types' => ['nullable', 'array'],
            'gallery' => ['nullable', 'array'],
            'highlights' => ['nullable', 'array'],
            'ingredients' => ['nullable', 'array'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'hide_from_storefront' => ['sometimes', 'boolean'],
            'campaign_facebook_pixel_ids' => ['nullable', 'array'],
            'campaign_facebook_pixel_ids.*' => ['string', 'max:80'],
            'campaign_google_tag_ids' => ['nullable', 'array'],
            'campaign_google_tag_ids.*' => ['string', 'max:80'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'attribute_term_ids' => ['nullable', 'array'],
            'attribute_term_ids.*' => ['integer', 'exists:attribute_terms,id'],
        ]);

        $this->validateShortDescriptionLength($validated['short_description'] ?? null);

        if (! (bool) ($validated['hide_from_storefront'] ?? false)) {
            $validated['campaign_facebook_pixel_ids'] = [];
            $validated['campaign_google_tag_ids'] = [];
        }

        $validated['slug'] = $this->resolveUniqueSlug(
            $validated['slug'] ?? $validated['name'],
            $productId,
        );
        $validated['category_id'] = $validated['category_ids'][0];

        return [
            'attributes' => collect($validated)
                ->except(['category_ids', 'tag_ids', 'attribute_term_ids'])
                ->all(),
            'category_ids' => $validated['category_ids'] ?? [],
            'tag_ids' => $validated['tag_ids'] ?? [],
            'attribute_term_ids' => $validated['attribute_term_ids'] ?? [],
        ];
    }

    protected function validateShortDescriptionLength(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $plainText = trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ) ?? '');

        if (Str::length($plainText) > 500) {
            throw ValidationException::withMessages([
                'short_description' => ['The short description may not be greater than 500 characters.'],
            ]);
        }
    }

    protected function resolveUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'product';
        $candidate = $base;
        $suffix = 2;

        while (
            Product::query()
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
