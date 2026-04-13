<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CouponController extends Controller
{
    public function validateCode(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'code' => ['required', 'string'],
            'subtotal' => ['nullable', 'numeric'],
        ]);

        $coupon = Coupon::where('code', strtoupper($payload['code']))->first();

        if (! $coupon || ! $coupon->is_active) {
            return response()->json([
                'message' => 'Coupon is invalid.',
            ], 404);
        }

        $now = Carbon::now();

        if (($coupon->starts_at && $coupon->starts_at->isFuture()) ||
            ($coupon->ends_at && $coupon->ends_at->isPast())) {
            return response()->json([
                'message' => 'Coupon is not currently active.',
            ], 422);
        }

        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return response()->json([
                'message' => 'Coupon usage limit has been reached.',
            ], 422);
        }

        $subtotal = (float) ($payload['subtotal'] ?? 0);

        if ($subtotal < (float) $coupon->minimum_order_amount) {
            return response()->json([
                'message' => 'Order minimum has not been reached for this coupon.',
            ], 422);
        }

        return response()->json([
            'data' => $coupon,
        ]);
    }
}
