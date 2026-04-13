<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttributeTerm;
use App\Models\ProductAttribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttributeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ProductAttribute::with('terms')
                ->withCount('terms')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $attribute = ProductAttribute::create([
            'name' => $validated['name'],
            'slug' => $this->resolveUniqueAttributeSlug($validated['slug'] ?? $validated['name']),
        ]);

        return response()->json([
            'message' => 'Attribute created successfully.',
            'data' => $attribute->load('terms')->loadCount('terms'),
        ], 201);
    }

    public function show(ProductAttribute $attribute): JsonResponse
    {
        return response()->json([
            'data' => $attribute->load('terms')->loadCount('terms'),
        ]);
    }

    public function update(Request $request, ProductAttribute $attribute): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $attribute->update([
            'name' => $validated['name'],
            'slug' => $this->resolveUniqueAttributeSlug($validated['slug'] ?? $validated['name'], $attribute->id),
        ]);

        return response()->json([
            'message' => 'Attribute updated successfully.',
            'data' => $attribute->fresh()->load('terms')->loadCount('terms'),
        ]);
    }

    public function destroy(ProductAttribute $attribute): JsonResponse
    {
        $attribute->delete();

        return response()->json([
            'message' => 'Attribute deleted successfully.',
        ]);
    }

    public function storeTerm(Request $request, ProductAttribute $attribute): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $term = AttributeTerm::create([
            'attribute_id' => $attribute->id,
            'name' => $validated['name'],
            'slug' => $this->resolveUniqueTermSlug($attribute, $validated['name']),
        ]);

        return response()->json([
            'message' => 'Attribute term created successfully.',
            'data' => $term,
        ], 201);
    }

    public function updateTerm(Request $request, AttributeTerm $term): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $term->update([
            'name' => $validated['name'],
            'slug' => $this->resolveUniqueTermSlug($term->attribute, $validated['name'], $term->id),
        ]);

        return response()->json([
            'message' => 'Attribute term updated successfully.',
            'data' => $term->fresh(),
        ]);
    }

    public function destroyTerm(AttributeTerm $term): JsonResponse
    {
        $term->delete();

        return response()->json([
            'message' => 'Attribute term deleted successfully.',
        ]);
    }

    protected function resolveUniqueAttributeSlug(string $value, ?int $ignoreId = null): string
    {
        $base = $this->normalizeAttributeSlug($value);
        $candidate = $base;
        $suffix = 2;

        while (
            ProductAttribute::query()
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function resolveUniqueTermSlug(ProductAttribute $attribute, string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value);
        $base = $base !== '' ? $base : 'term';
        $candidate = $base;
        $suffix = 2;

        while (
            $attribute->terms()
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function normalizeAttributeSlug(string $value): string
    {
        $base = Str::slug($value);
        $base = Str::replaceFirst('pa-', '', $base);
        $base = $base !== '' ? $base : 'attribute';

        return 'pa_'.Str::replace('-', '_', $base);
    }
}
