<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVolumeDiscountController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product->volumeDiscounts()
                ->with('freeProduct:id,name,slug,gallery')
                ->orderBy('sort_order')
                ->orderBy('quantity')
                ->get(),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $payload = $request->validate([
            'tiers' => ['array'],
            'tiers.*.id' => ['nullable', 'integer', 'exists:product_volume_discounts,id'],
            'tiers.*.quantity' => ['required', 'integer', 'min:1', 'distinct'],
            'tiers.*.flat_price' => ['required', 'numeric', 'min:0'],
            'tiers.*.label' => ['nullable', 'string', 'max:255'],
            'tiers.*.free_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'tiers.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'tiers.*.is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($payload, $product): void {
            $keepIds = [];

            foreach ($payload['tiers'] ?? [] as $index => $tier) {
                $attributes = [
                    'quantity' => $tier['quantity'],
                    'flat_price' => $tier['flat_price'],
                    'label' => $tier['label'] ?: 'Buy '.$tier['quantity'].' PCS',
                    'free_product_id' => $tier['free_product_id'] ?? null,
                    'sort_order' => $tier['sort_order'] ?? $index,
                    'is_active' => $tier['is_active'] ?? true,
                ];

                $model = ! empty($tier['id'])
                    ? $product->volumeDiscounts()->whereKey($tier['id'])->firstOrFail()
                    : $product->volumeDiscounts()->make();

                $model->fill($attributes);
                $model->save();

                $keepIds[] = $model->id;
            }

            $product->volumeDiscounts()
                ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
                ->delete();
        });

        return response()->json([
            'message' => 'Volume discount tiers saved successfully.',
            'data' => $product->volumeDiscounts()
                ->with('freeProduct:id,name,slug,gallery')
                ->orderBy('sort_order')
                ->orderBy('quantity')
                ->get(),
        ]);
    }
}
