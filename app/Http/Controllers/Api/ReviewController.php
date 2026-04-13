<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Review::query()
            ->with('product')
            ->where('status', 'approved');

        if ($request->filled('product')) {
            $query->whereHas('product', function ($builder) use ($request): void {
                $builder->where('slug', $request->string('product'));
            });
        }

        return response()->json([
            'data' => $query->latest()->paginate(12),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'author_phone' => ['required', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $user = $request->user();

        $review = Review::create([
            'product_id' => $payload['product_id'],
            'user_id' => $user?->id,
            'author_name' => $payload['author_name'],
            'author_email' => $payload['author_email'] ?? $user?->email,
            'author_phone' => $payload['author_phone'],
            'rating' => $payload['rating'],
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? null,
            'status' => 'approved',
        ]);

        $this->refreshProductMetrics($review->product);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'data' => $review,
        ], 201);
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
