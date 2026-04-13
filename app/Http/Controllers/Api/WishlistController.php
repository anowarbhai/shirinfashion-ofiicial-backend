<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $wishlist = WishlistItem::query()
            ->with('product.category')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $wishlist,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $wishlistItem = WishlistItem::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $payload['product_id'],
        ]);

        return response()->json([
            'message' => 'Product saved to wishlist.',
            'data' => $wishlistItem->load('product'),
        ], 201);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        WishlistItem::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json([
            'message' => 'Product removed from wishlist.',
        ]);
    }
}
