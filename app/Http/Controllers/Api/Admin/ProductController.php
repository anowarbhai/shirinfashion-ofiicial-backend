<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    private const CSV_HEADERS = [
        'sku',
        'review_group_key',
        'review_source_product_id',
        'name',
        'slug',
        'brand',
        'category_slugs',
        'price',
        'compare_price',
        'inventory',
        'manage_stock',
        'stock_status',
        'is_active',
        'is_featured',
        'hide_from_storefront',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'gallery',
    ];

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

    public function export(): StreamedResponse
    {
        $fileName = 'products-export-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::CSV_HEADERS);

            Product::with(['category', 'categories'])
                ->orderBy('id')
                ->chunk(200, function ($products) use ($handle): void {
                    foreach ($products as $product) {
                        $categories = $product->categories->isNotEmpty()
                            ? $product->categories
                            : collect([$product->category])->filter();

                        fputcsv($handle, [
                            $product->sku,
                            $product->review_group_key,
                            $product->review_source_product_id,
                            $product->name,
                            $product->slug,
                            $product->brand,
                            $categories->pluck('slug')->filter()->implode(';'),
                            $product->price,
                            $product->compare_price,
                            $product->inventory,
                            $product->manage_stock ? '1' : '0',
                            $product->stock_status,
                            $product->is_active ? '1' : '0',
                            $product->is_featured ? '1' : '0',
                            $product->hide_from_storefront ? '1' : '0',
                            $product->short_description,
                            $product->description,
                            $product->meta_title,
                            $product->meta_description,
                            $product->meta_keywords,
                            collect($product->gallery)->filter()->implode(';'),
                        ]);
                    }
                });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function sampleImport(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::CSV_HEADERS);
            fputcsv($handle, [
                'SKU-00001',
                'sample-beauty-product',
                '',
                'Sample Beauty Product',
                'sample-beauty-product',
                'Shirin Fashion',
                'skincare;new-arrivals',
                '600',
                '700',
                '25',
                '1',
                'in_stock',
                '1',
                '0',
                '0',
                'Short product summary within 500 characters.',
                '<p>Full product description can include HTML.</p>',
                'Sample Beauty Product Meta Title',
                'Search-friendly product meta description.',
                'sample beauty product, skincare, cosmetics',
                'https://example.com/product-1.jpg;https://example.com/product-2.jpg',
            ]);
            fclose($handle);
        }, 'product-import-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return response()->json([
                'message' => 'Unable to read import file.',
            ], 422);
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers) || count($headers) === 0) {
            fclose($handle);

            return response()->json([
                'message' => 'CSV header row is missing.',
            ], 422);
        }

        $headers = array_map(fn ($header) => $this->normalizeCsvHeader((string) $header), $headers);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isEmptyCsvRow($row)) {
                continue;
            }

            $row = $this->combineCsvRow($headers, $row);

            try {
                $name = trim((string) ($row['name'] ?? ''));
                $sku = trim((string) ($row['sku'] ?? ''));
                $price = trim((string) ($row['price'] ?? ''));
                $categoryInput = trim((string) ($row['category_slugs'] ?? $row['categories'] ?? ''));

                if ($name === '' || $sku === '' || $price === '' || $categoryInput === '') {
                    throw ValidationException::withMessages([
                        'row' => ['SKU, name, category_slugs, and price are required.'],
                    ]);
                }

                $categoryIds = $this->resolveImportCategoryIds($categoryInput);
                $product = Product::query()->where('sku', $sku)->first();
                $isNewProduct = ! $product;

                if (! $product && ! empty($row['slug'])) {
                    $product = Product::query()->where('slug', trim((string) $row['slug']))->first();
                    $isNewProduct = ! $product;
                }

                $inventory = (int) ($row['inventory'] ?? 0);
                $manageStock = $this->parseCsvBoolean($row['manage_stock'] ?? null, true);
                $stockStatus = trim((string) ($row['stock_status'] ?? ''));
                $stockStatus = in_array($stockStatus, ['in_stock', 'out_of_stock'], true)
                    ? $stockStatus
                    : ($manageStock && $inventory <= 0 ? 'out_of_stock' : 'in_stock');
                $shortDescription = (string) ($row['short_description'] ?? '');
                $this->validateShortDescriptionLength($shortDescription);

                $attributes = [
                    'category_id' => $categoryIds[0],
                    'name' => $name,
                    'slug' => $this->resolveUniqueSlug((string) ($row['slug'] ?? $name), $product?->id),
                    'sku' => $sku,
                    'review_group_key' => trim((string) ($row['review_group_key'] ?? '')) ?: null,
                    'review_source_product_id' => $this->nullableInteger($row['review_source_product_id'] ?? null),
                    'brand' => trim((string) ($row['brand'] ?? '')) ?: 'Shirin Fashion',
                    'short_description' => $shortDescription,
                    'description' => (string) ($row['description'] ?? ''),
                    'meta_title' => trim((string) ($row['meta_title'] ?? '')) ?: null,
                    'meta_description' => trim((string) ($row['meta_description'] ?? '')) ?: null,
                    'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')) ?: null,
                    'price' => (float) $price,
                    'compare_price' => $this->nullableFloat($row['compare_price'] ?? null),
                    'inventory' => $inventory,
                    'manage_stock' => $manageStock,
                    'stock_status' => $stockStatus,
                    'is_active' => $this->parseCsvBoolean($row['is_active'] ?? null, true),
                    'is_featured' => $this->parseCsvBoolean($row['is_featured'] ?? null, false),
                    'hide_from_storefront' => $this->parseCsvBoolean($row['hide_from_storefront'] ?? null, false),
                    'gallery' => $this->splitCsvList((string) ($row['gallery'] ?? '')),
                ];

                $product = $product ?: new Product();
                $product->fill($attributes);
                $product->save();
                $product->categories()->sync($categoryIds);

                $isNewProduct ? $created++ : $updated++;
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage(),
                ];
            }
        }

        fclose($handle);

        $this->auditLogger->log(
            $request,
            'product.imported',
            "Imported products from CSV. Created {$created}, updated {$updated}, skipped {$skipped}.",
            null,
            ['created' => $created, 'updated' => $updated, 'skipped' => $skipped],
        );

        return response()->json([
            'message' => "Import finished. Created {$created}, updated {$updated}, skipped {$skipped}.",
            'data' => compact('created', 'updated', 'skipped', 'errors'),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product->load('category', 'categories', 'reviews', 'tags', 'attributeTerms.attribute'),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $before = $product->only(['name', 'sku', 'price', 'inventory', 'manage_stock', 'stock_status', 'is_active', 'is_featured', 'hide_from_storefront']);
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
                'after' => $updated->only(['name', 'sku', 'price', 'inventory', 'manage_stock', 'stock_status', 'is_active', 'is_featured', 'hide_from_storefront']),
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

    protected function normalizeCsvHeader(string $header): string
    {
        return Str::of($header)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->replaceMatches('/[^a-z0-9_]/', '')
            ->toString();
    }

    protected function combineCsvRow(array $headers, array $row): array
    {
        $row = array_pad($row, count($headers), '');
        $row = array_slice($row, 0, count($headers));

        return array_combine($headers, $row) ?: [];
    }

    protected function isEmptyCsvRow(array $row): bool
    {
        return collect($row)->every(fn ($value) => trim((string) $value) === '');
    }

    protected function splitCsvList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return collect(preg_split('/[;,]/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    protected function parseCsvBoolean(mixed $value, bool $default): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'active', 'published', 'on'], true);
    }

    protected function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }

    protected function nullableInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    protected function resolveImportCategoryIds(string $value): array
    {
        $categoryIds = collect($this->splitCsvList($value))
            ->map(function (string $categoryNameOrSlug): int {
                $slug = Str::slug($categoryNameOrSlug);
                $slug = $slug !== '' ? $slug : Str::random(8);

                $category = Category::query()
                    ->where('slug', $slug)
                    ->orWhere('name', $categoryNameOrSlug)
                    ->first();

                if (! $category) {
                    $category = Category::create([
                        'name' => Str::of(str_replace(['-', '_'], ' ', $categoryNameOrSlug))->title()->toString(),
                        'slug' => $this->resolveUniqueCategorySlug($slug),
                        'is_featured' => false,
                    ]);
                }

                return (int) $category->id;
            })
            ->unique()
            ->values()
            ->all();

        if (count($categoryIds) === 0) {
            throw ValidationException::withMessages([
                'category_slugs' => ['At least one category slug or name is required.'],
            ]);
        }

        return $categoryIds;
    }

    protected function resolveUniqueCategorySlug(string $value): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'category';
        $candidate = $base;
        $suffix = 2;

        while (Category::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
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
            'review_group_key' => ['nullable', 'string', 'max:255'],
            'review_source_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'brand' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric'],
            'compare_price' => ['nullable', 'numeric'],
            'inventory' => ['required', 'integer', 'min:0'],
            'manage_stock' => ['sometimes', 'boolean'],
            'stock_status' => ['nullable', 'string', 'in:in_stock,out_of_stock'],
            'badge' => ['nullable', 'string', 'max:255'],
            'skin_types' => ['nullable', 'array'],
            'gallery' => ['nullable', 'array'],
            'highlights' => ['nullable', 'array'],
            'ingredients' => ['nullable', 'array'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'hide_from_storefront' => ['sometimes', 'boolean'],
            'show_trust_badges' => ['sometimes', 'boolean'],
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
        $validated['highlights'] = $this->normalizeStringList($validated['highlights'] ?? []);
        $validated['ingredients'] = $this->normalizeStringList($validated['ingredients'] ?? []);

        if (! (bool) ($validated['hide_from_storefront'] ?? false)) {
            $validated['campaign_facebook_pixel_ids'] = [];
            $validated['campaign_google_tag_ids'] = [];
        }

        $validated['slug'] = $this->resolveUniqueSlug(
            $validated['slug'] ?? $validated['name'],
            $productId,
        );
        $validated['review_group_key'] = trim((string) ($validated['review_group_key'] ?? '')) ?: null;
        $validated['review_source_product_id'] = $this->normalizeReviewSourceProductId(
            $validated['review_source_product_id'] ?? null,
            $productId,
            (bool) ($validated['hide_from_storefront'] ?? false),
        );
        $validated['category_id'] = $validated['category_ids'][0];
        $validated['manage_stock'] = (bool) ($validated['manage_stock'] ?? true);
        $validated['show_trust_badges'] = (bool) ($validated['show_trust_badges'] ?? true);
        $validated['stock_status'] = $validated['stock_status'] ?? (
            ((int) $validated['inventory']) > 0 ? 'in_stock' : 'out_of_stock'
        );

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

    protected function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeReviewSourceProductId(
        mixed $value,
        ?int $productId,
        bool $isCampaignUrl,
    ): ?int {
        if (! $isCampaignUrl) {
            return null;
        }

        $sourceProductId = (int) ($value ?? 0);

        if ($sourceProductId <= 0) {
            return null;
        }

        if ($productId !== null && $sourceProductId === $productId) {
            throw ValidationException::withMessages([
                'review_source_product_id' => ['Select a different product as the review source.'],
            ]);
        }

        return $sourceProductId;
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
