<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminBrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $brands = Brand::orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get()
            ->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'logoUrl' => $brand->logo_url,
                    'description' => $brand->description,
                    'isActive' => (bool) $brand->is_active,
                    'sortOrder' => (int) $brand->sort_order,
                    'productsCount' => Product::where('brand', $brand->name)->count(),
                    'createdAt' => $brand->created_at?->toIso8601String(),
                    'updatedAt' => $brand->updated_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => $brands,
        ]);
    }

    public function publicIndex(): JsonResponse
    {
        $brands = Brand::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get()
            ->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'logoUrl' => $brand->logo_url,
                    'description' => $brand->description,
                ];
            });

        return response()->json([
            'data' => $brands,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:brands,slug'],
            'logoUrl' => ['nullable', 'string', 'max:1000'],
            'logo_url' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'isActive' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $name = trim($validated['name']);
        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($name);

        // Ensure slug uniqueness
        $originalSlug = $slug;
        $count = 1;
        while (Brand::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        $brand = Brand::create([
            'name' => $name,
            'slug' => $slug,
            'logo_url' => $validated['logoUrl'] ?? $validated['logo_url'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['isActive'] ?? $validated['is_active'] ?? true,
            'sort_order' => $validated['sortOrder'] ?? $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Brand created successfully.',
            'data' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logoUrl' => $brand->logo_url,
                'description' => $brand->description,
                'isActive' => (bool) $brand->is_active,
                'sortOrder' => (int) $brand->sort_order,
                'productsCount' => 0,
            ],
        ], 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($brand->id)],
            'logoUrl' => ['nullable', 'string', 'max:1000'],
            'logo_url' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'isActive' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $oldName = $brand->name;
        $name = isset($validated['name']) ? trim($validated['name']) : $brand->name;
        $slug = isset($validated['slug']) && !empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : ($name !== $oldName ? Str::slug($name) : $brand->slug);

        $brand->update([
            'name' => $name,
            'slug' => $slug,
            'logo_url' => array_key_exists('logoUrl', $validated) || array_key_exists('logo_url', $validated)
                ? ($validated['logoUrl'] ?? $validated['logo_url'] ?? null)
                : $brand->logo_url,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $brand->description,
            'is_active' => array_key_exists('isActive', $validated) || array_key_exists('is_active', $validated)
                ? ($validated['isActive'] ?? $validated['is_active'] ?? $brand->is_active)
                : $brand->is_active,
            'sort_order' => array_key_exists('sortOrder', $validated) || array_key_exists('sort_order', $validated)
                ? ($validated['sortOrder'] ?? $validated['sort_order'] ?? $brand->sort_order)
                : $brand->sort_order,
        ]);

        // If brand name changed, update product brand fields
        if ($oldName !== $name) {
            Product::where('brand', $oldName)->update(['brand' => $name]);
        }

        return response()->json([
            'message' => 'Brand updated successfully.',
            'data' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logoUrl' => $brand->logo_url,
                'description' => $brand->description,
                'isActive' => (bool) $brand->is_active,
                'sortOrder' => (int) $brand->sort_order,
                'productsCount' => Product::where('brand', $brand->name)->count(),
            ],
        ]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $brand->delete();

        return response()->json([
            'message' => 'Brand deleted successfully.',
        ]);
    }
}
