<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Moderator;
use App\Models\Product;
use App\Models\ProductModeratorAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductModeratorAssignmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Product::query()
                ->with(['moderatorAssignment.moderator.user:id,name,email,phone,status'])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'is_active']),
            'moderators' => Moderator::query()
                ->active()
                ->with('user:id,name,email,phone,status')
                ->orderBy('assignment_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $payload = $request->validate([
            'moderator_id' => ['nullable', 'integer', 'exists:moderators,id'],
        ]);

        if (empty($payload['moderator_id'])) {
            ProductModeratorAssignment::query()->where('product_id', $product->id)->delete();

            return response()->json([
                'message' => 'Product moderator assignment removed successfully.',
            ]);
        }

        ProductModeratorAssignment::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'moderator_id' => (int) $payload['moderator_id'],
                'assigned_by' => $request->user()?->id,
            ],
        );

        return response()->json([
            'message' => 'Product moderator assignment saved successfully.',
            'data' => $product->fresh('moderatorAssignment.moderator.user'),
        ]);
    }
}
