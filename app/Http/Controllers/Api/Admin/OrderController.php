<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVolumeDiscount;
use App\Services\AdminSettingsService;
use App\Services\FraudCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        protected AdminSettingsService $settings,
        protected FraudCheckerService $fraudCheckerService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Order::with('items')->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['required', 'string', 'max:30'],
            'payment_method' => ['required', 'in:stripe,paypal,cod'],
            'payment_status' => ['nullable', 'string', 'max:255'],
            'shipping_method' => ['required', 'in:inside-dhaka,outside-dhaka'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.address' => ['required', 'string'],
            'shipping_address.city' => ['nullable', 'string'],
            'shipping_address.country' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.volume_discount_id' => ['nullable', 'integer', 'exists:product_volume_discounts,id'],
        ]);

        $order = DB::transaction(function () use ($payload) {
            $productIds = collect($payload['items'])->pluck('product_id')->all();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
            $tierIds = collect($payload['items'])->pluck('volume_discount_id')->filter()->all();
            $tiers = ProductVolumeDiscount::with('freeProduct')
                ->whereIn('id', $tierIds)
                ->get()
                ->keyBy('id');
            $subtotal = 0;
            $orderItems = [];

            foreach ($payload['items'] as $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more products could not be found.'],
                    ]);
                }

                $tier = ! empty($item['volume_discount_id'])
                    ? $tiers->get($item['volume_discount_id'])
                    : null;

                if ($tier) {
                    if ($tier->product_id !== $product->id || ! $tier->is_active) {
                        throw ValidationException::withMessages([
                            'items' => ["Selected volume discount is not available for {$product->name}."],
                        ]);
                    }

                    if ((int) $item['quantity'] !== $tier->quantity) {
                        throw ValidationException::withMessages([
                            'items' => ["{$tier->label} requires exactly {$tier->quantity} items."],
                        ]);
                    }
                }

                if ($product->inventory < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} does not have enough stock."],
                    ]);
                }

                $lineTotal = $tier
                    ? (float) $tier->flat_price
                    : (float) $product->price * (int) $item['quantity'];
                $subtotal += $lineTotal;
                $orderItems[] = [
                    'product' => $product,
                    'tier' => $tier,
                    'quantity' => (int) $item['quantity'],
                    'line_total' => $lineTotal,
                ];
            }

            $couponDiscount = $this->resolveCouponDiscount($payload['coupon_code'] ?? null, $subtotal);
            $manualDiscount = (float) ($payload['discount_total'] ?? 0);
            $discountTotal = min($subtotal, max($couponDiscount, $manualDiscount));
            $shippingTotal = array_key_exists('shipping_total', $payload)
                ? (float) $payload['shipping_total']
                : ($payload['shipping_method'] === 'outside-dhaka' ? 120 : 80);
            $grandTotal = max(0, $subtotal + $shippingTotal - $discountTotal);

            $order = Order::create([
                'order_number' => 'SBA-'.random_int(1000, 9999),
                'customer_name' => $payload['customer_name'],
                'email' => $payload['email'] ?? $this->buildGuestEmail($payload['phone']),
                'phone' => $payload['phone'],
                'status' => $payload['status'] ?? 'processing',
                'payment_method' => $payload['payment_method'],
                'payment_status' => $payload['payment_status']
                    ?? ($payload['payment_method'] === 'cod' ? 'pending_collection' : 'authorized'),
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shippingTotal,
                'grand_total' => $grandTotal,
                'shipping_address' => [
                    'address' => $payload['shipping_address']['address'],
                    'city' => $payload['shipping_address']['city']
                        ?? ($payload['shipping_method'] === 'inside-dhaka' ? 'Dhaka' : 'Outside Dhaka'),
                    'country' => $payload['shipping_address']['country'] ?? 'Bangladesh',
                ],
                'fraud_check' => $this->resolveFraudCheck($payload['phone']),
                'tracking_number' => $payload['tracking_number'] ?? 'TRK-'.random_int(100000, 999999),
                'placed_at' => Carbon::now(),
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($orderItems as $item) {
                /** @var Product $product */
                $product = $item['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'volume_discount_id' => $item['tier']?->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'price' => $item['tier']
                        ? round($item['line_total'] / max(1, $item['quantity']), 2)
                        : $product->price,
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                    'is_free_gift' => false,
                ]);

                $product->decrement('inventory', $item['quantity']);

                if ($item['tier']?->freeProduct) {
                    $gift = $item['tier']->freeProduct;

                    $order->items()->create([
                        'product_id' => $gift->id,
                        'volume_discount_id' => $item['tier']->id,
                        'product_name' => $gift->name.' (Free Gift)',
                        'sku' => $gift->sku,
                        'price' => 0,
                        'quantity' => 1,
                        'line_total' => 0,
                        'is_free_gift' => true,
                    ]);

                    if ($gift->inventory > 0) {
                        $gift->decrement('inventory');
                    }
                }
            }

            if (! empty($payload['coupon_code'])) {
                Coupon::where('code', strtoupper($payload['coupon_code']))->increment('used_count');
            }

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $order,
        ], 201);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:255'],
            'payment_status' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ]);

        $order->update($payload);

        return response()->json([
            'message' => 'Order updated successfully.',
            'data' => $order->fresh('items'),
        ]);
    }

    protected function resolveCouponDiscount(?string $couponCode, float $subtotal): float
    {
        if (! $couponCode) {
            return 0;
        }

        $coupon = Coupon::where('code', strtoupper($couponCode))
            ->where('is_active', true)
            ->first();

        if (! $coupon || $subtotal < (float) $coupon->minimum_order_amount) {
            return 0;
        }

        return $coupon->type === 'fixed'
            ? min((float) $coupon->value, $subtotal)
            : round($subtotal * ((float) $coupon->value / 100), 2);
    }

    protected function resolveFraudCheck(string $phone): ?array
    {
        $fraudSettings = $this->settings->getGroup('fraud_checker');

        if (! ($fraudSettings['enabled'] ?? false) || empty($fraudSettings['api_key'])) {
            return null;
        }

        try {
            return $this->fraudCheckerService->check($phone);
        } catch (Throwable $exception) {
            return [
                'phone' => $phone,
                'status' => 'Unavailable',
                'score' => 0,
                'total_parcel' => 0,
                'success_parcel' => 0,
                'cancel_parcel' => 0,
                'source' => 'ERROR',
                'couriers' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    protected function buildGuestEmail(string $phone): string
    {
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone) ?: 'guest';

        return sprintf('%s-%s@guest.admin-order', $normalizedPhone, strtolower((string) str()->random(6)));
    }
}
