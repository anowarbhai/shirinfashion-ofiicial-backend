<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Review::with('product', 'user')->latest()->paginate(50),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', 'in:pending,approved,rejected'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $user = null;

        if (!empty($payload['author_email'])) {
            $user = User::query()->where('email', $payload['author_email'])->first();
        }

        $review = Review::create([
            ...$payload,
            'user_id' => $user?->id,
            'is_featured' => (bool) ($payload['is_featured'] ?? false),
        ]);

        $review->load('product', 'user');
        $this->refreshProductMetrics($review->product);

        return response()->json([
            'message' => 'Review created successfully.',
            'data' => $review->fresh(['product', 'user']),
        ], 201);
    }

    public function update(Request $request, Review $review): JsonResponse
    {
        $payload = $request->validate([
            'product_id' => ['sometimes', 'exists:products,id'],
            'author_name' => ['sometimes', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $originalProduct = $review->product;
        $user = null;

        if (array_key_exists('author_email', $payload) && !empty($payload['author_email'])) {
            $user = User::query()->where('email', $payload['author_email'])->first();
        }

        $review->update([
            ...$payload,
            'user_id' => array_key_exists('author_email', $payload)
                ? $user?->id
                : $review->user_id,
        ]);

        $review->load('product', 'user');

        $this->refreshProductMetrics($review->product);

        if ($originalProduct->isNot($review->product)) {
            $this->refreshProductMetrics($originalProduct);
        }

        return response()->json([
            'message' => 'Review updated successfully.',
            'data' => $review->fresh(['product', 'user']),
        ]);
    }

    public function destroy(Review $review): JsonResponse
    {
        $product = $review->product;

        $review->delete();
        $this->refreshProductMetrics($product);

        return response()->json([
            'message' => 'Review deleted successfully.',
        ]);
    }

    protected function refreshProductMetrics(Product $product): void
    {
        $approved = $product->reviews()->where('status', 'approved');

        $product->update([
            'rating' => round((float) $approved->avg('rating'), 1),
            'review_count' => $approved->count(),
        ]);
    }
}
