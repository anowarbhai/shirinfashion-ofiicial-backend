<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CouponEligibilityService
{
    /**
     * @param  array{source?: ?string, phone?: ?string, email?: ?string}  $context
     */
    public function assertEligible(Coupon $coupon, array $context, ?User $customer = null): void
    {
        if ($coupon->mobile_app_only && ! $this->isMobileAppSource($context['source'] ?? null)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is only available in the mobile app.'],
            ]);
        }

        if ($coupon->registered_customer_only) {
            $customer ??= $this->resolveRegisteredCustomer(
                $context['phone'] ?? null,
                $context['email'] ?? null,
            );

            if (! $customer) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['Please login or register before using this coupon.'],
                ]);
            }
        }

        if ($coupon->first_order_only && $this->hasPreviousOrder($context, $customer)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is only available for the first order.'],
            ]);
        }
    }

    public function isMobileAppSource(?string $source): bool
    {
        $value = strtolower(trim((string) $source));

        return in_array($value, ['mobile', 'mobile_app', 'mobile app', 'app', 'android', 'ios'], true);
    }

    /**
     * @param  array{phone?: ?string, email?: ?string}  $context
     */
    public function hasPreviousOrder(array $context, ?User $customer = null): bool
    {
        $normalizedPhone = $this->normalizePhoneForMatch((string) ($context['phone'] ?? ''));
        $normalizedEmail = strtolower(trim((string) ($context['email'] ?? $customer?->email ?? '')));

        if (! $customer && $normalizedPhone === '' && $normalizedEmail === '') {
            return false;
        }

        return Order::query()
            ->whereNotIn('status', ['incomplete', 'cancelled', 'refunded'])
            ->where(function ($query) use ($customer, $normalizedPhone, $normalizedEmail): void {
                if ($customer) {
                    $query->orWhere('user_id', $customer->id);
                }

                if ($normalizedPhone !== '') {
                    $query
                        ->orWhere('normalized_phone', $normalizedPhone)
                        ->orWhere('phone', $normalizedPhone);
                }

                if ($normalizedEmail !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }
            })
            ->exists();
    }

    public function resolveRegisteredCustomer(?string $phone, ?string $email): ?User
    {
        $normalizedPhone = $this->normalizePhoneForMatch((string) $phone);
        $normalizedEmail = strtolower(trim((string) $email));

        if ($normalizedPhone === '' && $normalizedEmail === '') {
            return null;
        }

        return User::query()
            ->where('role', 'customer')
            ->where(function ($query) use ($normalizedPhone, $normalizedEmail): void {
                if ($normalizedPhone !== '') {
                    $query->orWhere('phone', $normalizedPhone);
                }

                if ($normalizedEmail !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }
            })
            ->first();
    }

    public function normalizePhoneForMatch(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }
}
