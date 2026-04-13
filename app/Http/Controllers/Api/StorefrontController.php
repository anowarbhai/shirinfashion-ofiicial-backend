<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;

class StorefrontController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'categories' => Category::query()
                    ->withCount('products')
                    ->where('is_featured', true)
                    ->get(),
                'featured_products' => Product::query()
                    ->where('is_active', true)
                    ->where('is_featured', true)
                    ->latest()
                    ->take(6)
                    ->get(),
                'featured_reviews' => Review::query()
                    ->where('status', 'approved')
                    ->where('is_featured', true)
                    ->latest()
                    ->take(3)
                    ->get(),
            ],
        ]);
    }
}
