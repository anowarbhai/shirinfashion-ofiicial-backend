<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
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
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
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

        if ($coupon->maximum_order_amount !== null && $subtotal > (float) $coupon->maximum_order_amount) {
            return response()->json([
                'message' => 'Order maximum has been exceeded for this coupon.',
            ], 422);
        }

        if ($this->couponPerUserLimitReached($coupon, $payload['phone'] ?? null, $payload['email'] ?? null)) {
            return response()->json([
                'message' => 'This coupon usage limit has been reached for this customer.',
            ], 422);
        }

        return response()->json([
            'data' => $coupon,
        ]);
    }

    protected function couponPerUserLimitReached(Coupon $coupon, ?string $phone, ?string $email): bool
    {
        $limit = (int) ($coupon->per_user_limit ?? 0);

        if ($limit < 1) {
            return false;
        }

        $normalizedPhone = $this->normalizePhoneForMatch($phone ?? '');
        $normalizedEmail = strtolower(trim((string) $email));

        if ($normalizedPhone === '' && $normalizedEmail === '') {
            return false;
        }

        $count = Order::query()
            ->where('coupon_code', $coupon->code)
            ->whereNotIn('status', ['incomplete', 'cancelled', 'refunded'])
            ->where(function ($query) use ($normalizedPhone, $normalizedEmail): void {
                if ($normalizedPhone !== '') {
                    $query
                        ->orWhere('normalized_phone', $normalizedPhone)
                        ->orWhere('phone', $normalizedPhone);
                }

                if ($normalizedEmail !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }
            })
            ->count();

        return $count >= $limit;
    }

    protected function normalizePhoneForMatch(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }
}
