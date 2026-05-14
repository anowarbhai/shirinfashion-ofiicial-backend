<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\CategoryResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->with('parent')
            ->select('categories.*')
            ->selectSub(
                Product::query()
                    ->selectRaw('COUNT(DISTINCT products.id)')
                    ->leftJoin('category_product', 'category_product.product_id', '=', 'products.id')
                    ->where('products.is_active', true)
                    ->visibleInStorefront()
                    ->where(function ($query): void {
                        $query->whereColumn('products.category_id', 'categories.id')
                            ->orWhereColumn('category_product.category_id', 'categories.id');
                    }),
                'products_count',
            )
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => CategoryResource::collection($categories),
        ]);
    }
}
