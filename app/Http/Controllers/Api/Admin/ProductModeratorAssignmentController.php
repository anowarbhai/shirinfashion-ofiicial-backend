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
                ->with(['moderatorAssignments.moderator.user:id,name,email,phone,status'])
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
            'moderator_ids' => ['nullable', 'array'],
            'moderator_ids.*' => ['integer', 'exists:moderators,id'],
        ]);

        $moderatorIds = collect($payload['moderator_ids'] ?? [])
            ->when(
                isset($payload['moderator_id']) && $payload['moderator_id'],
                fn ($ids) => $ids->push((int) $payload['moderator_id']),
            )
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($moderatorIds->isEmpty()) {
            ProductModeratorAssignment::query()->where('product_id', $product->id)->delete();

            return response()->json([
                'message' => 'Product moderator assignment removed successfully.',
            ]);
        }

        ProductModeratorAssignment::query()
            ->where('product_id', $product->id)
            ->whereNotIn('moderator_id', $moderatorIds->all())
            ->delete();

        foreach ($moderatorIds as $moderatorId) {
            ProductModeratorAssignment::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'moderator_id' => $moderatorId,
                ],
                [
                    'assigned_by' => $request->user()?->id,
                ],
            );
        }

        return response()->json([
            'message' => 'Product moderator assignments saved successfully.',
            'data' => $product->fresh('moderatorAssignments.moderator.user'),
        ]);
    }
}
