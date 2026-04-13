<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Coupon::latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $coupon = Coupon::create($this->validated($request));

        return response()->json([
            'message' => 'Coupon created successfully.',
            'data' => $coupon,
        ], 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return response()->json([
            'data' => $coupon,
        ]);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($this->validated($request, $coupon->id));

        return response()->json([
            'message' => 'Coupon updated successfully.',
            'data' => $coupon->fresh(),
        ]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return response()->json([
            'message' => 'Coupon deleted successfully.',
        ]);
    }

    protected function validated(Request $request, ?int $couponId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:255', 'unique:coupons,code,'.($couponId ?? 'NULL').',id'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'free_shipping' => ['sometimes', 'boolean'],
            'individual_use' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
