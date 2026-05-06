<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVolumeDiscount;
use App\Models\User;
use App\Services\AdminSettingsService;
use App\Services\FraudCheckerService;
use App\Services\JwtService;
use App\Services\SmsGatewayService;
use App\Services\SmsOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected SmsOtpService $smsOtpService,
        protected SmsGatewayService $smsGatewayService,
        protected AdminSettingsService $settings,
        protected FraudCheckerService $fraudCheckerService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['required', 'string', 'max:30'],
            'payment_method' => ['required', 'in:stripe,paypal,cod'],
            'shipping_method' => ['required', 'in:inside-dhaka,outside-dhaka'],
            'coupon_code' => ['nullable', 'string'],
            'otp_session_token' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.address' => ['required', 'string'],
            'shipping_address.city' => ['nullable', 'string'],
            'shipping_address.country' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.volume_discount_id' => ['nullable', 'integer', 'exists:product_volume_discounts,id'],
        ]);

        $customer = $this->resolveAuthenticatedUser($request);
        $clientIp = $this->resolveClientIp($request);
        $checkoutGuard = $this->resolveCheckoutGuardBlock(
            $payload['phone'],
            $clientIp,
            $payload['device_id'] ?? null,
        );

        if ($checkoutGuard) {
            return response()->json([
                'message' => $checkoutGuard['message'],
                'checkout_guard' => $checkoutGuard,
            ], 429);
        }

        if ($this->smsOtpService->isEnabled('order')) {
            if (empty($payload['otp_session_token'])) {
                throw ValidationException::withMessages([
                    'otp_session_token' => ['Please verify the order OTP before placing your order.'],
                ]);
            }

            $this->smsOtpService->consumeVerified(
                'order',
                $payload['otp_session_token'],
                $payload['phone'],
            );
        }

        $order = DB::transaction(function () use ($customer, $payload, $clientIp) {
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

            $discountTotal = $this->resolveDiscount($payload['coupon_code'] ?? null, $subtotal);
            $shippingAddress = [
                'address' => $payload['shipping_address']['address'],
                'city' => $payload['shipping_address']['city']
                    ?? ($payload['shipping_method'] === 'inside-dhaka' ? 'Dhaka' : 'Outside Dhaka'),
                'country' => $payload['shipping_address']['country'] ?? 'Bangladesh',
            ];
            $shippingTotal = $this->resolveShippingTotal($payload['shipping_method'], $subtotal);
            $grandTotal = $subtotal + $shippingTotal - $discountTotal;
            $fraudCheck = $this->resolveFraudCheck($payload['phone']);

            $order = Order::create([
                'order_number' => 'SBA-'.random_int(1000, 9999),
                'user_id' => $customer?->id,
                'customer_name' => $payload['customer_name'],
                'email' => $payload['email'] ?? $customer?->email ?? $this->buildGuestEmail($payload['phone']),
                'phone' => $payload['phone'],
                'client_ip' => $clientIp,
                'device_id' => $payload['device_id'] ?? null,
                'status' => 'processing',
                'payment_method' => $payload['payment_method'],
                'payment_status' => $payload['payment_method'] === 'cod' ? 'pending_collection' : 'authorized',
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shippingTotal,
                'grand_total' => $grandTotal,
                'shipping_address' => $shippingAddress,
                'fraud_check' => $fraudCheck,
                'tracking_number' => 'TRK-'.random_int(100000, 999999),
                'placed_at' => Carbon::now(),
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

        $this->sendOrderNotification($order);

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $order,
            'checkout_guard' => $this->resolveNextCheckoutGuardState($order),
        ], 201);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $otp = $this->smsOtpService->issue('order', $payload['phone'], null, [
                'name' => $payload['customer_name'] ?? 'Customer',
            ]);

            return response()->json([
                'message' => 'Order OTP sent successfully.',
                'data' => $otp,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'otp_session_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        try {
            $result = $this->smsOtpService->verify(
                'order',
                $payload['otp_session_token'],
                $payload['code'],
                $payload['phone'],
            );

            return response()->json([
                'message' => 'Order OTP verified successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function track(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_number' => ['nullable', 'string', 'required_without:tracking_number'],
            'tracking_number' => ['nullable', 'string', 'required_without:order_number'],
        ]);

        $order = Order::query()
            ->when(
                $payload['order_number'] ?? null,
                fn ($query, $value) => $query->where('order_number', $value)
            )
            ->when(
                $payload['tracking_number'] ?? null,
                fn ($query, $value) => $query->orWhere('tracking_number', $value)
            )
            ->with('items')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order could not be found.',
            ], 404);
        }

        return response()->json([
            'data' => $order,
        ]);
    }

    protected function resolveDiscount(?string $couponCode, float $subtotal): float
    {
        if (! $couponCode) {
            return 0;
        }

        $coupon = Coupon::where('code', strtoupper($couponCode))
            ->where('is_active', true)
            ->first();

        if (! $coupon) {
            return 0;
        }

        if ($subtotal < (float) $coupon->minimum_order_amount) {
            return 0;
        }

        return $coupon->type === 'fixed'
            ? min((float) $coupon->value, $subtotal)
            : round($subtotal * ((float) $coupon->value / 100), 2);
    }

    protected function resolveShippingTotal(string $shippingMethod, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0;
        }

        return $shippingMethod === 'outside-dhaka' ? 120 : 80;
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

    protected function resolveCheckoutGuardBlock(
        string $phone,
        ?string $clientIp,
        ?string $deviceId,
    ): ?array {
        $settings = $this->settings->getGroup('checkout_guard');

        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        $cooldownMinutes = max(1, (int) ($settings['cooldown_minutes'] ?? 180));
        $cutoff = Carbon::now()->subMinutes($cooldownMinutes);
        $matches = [];
        $normalizedDeviceId = $deviceId ? trim($deviceId) : null;
        $normalizedClientIp = $clientIp ? trim($clientIp) : null;

        if (($settings['block_by_phone'] ?? true) && trim($phone) !== '') {
            $matches['phone'] = trim($phone);
        }

        if (($settings['block_by_ip'] ?? true) && $normalizedClientIp) {
            $matches['ip'] = $normalizedClientIp;
        }

        if (($settings['block_by_device'] ?? true) && $normalizedDeviceId) {
            $matches['device'] = $normalizedDeviceId;
        }

        if ($matches === []) {
            return null;
        }

        $recentOrder = Order::query()
            ->where('placed_at', '>=', $cutoff)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(function ($query) use ($matches): void {
                if (isset($matches['phone'])) {
                    $query->orWhere('phone', $matches['phone']);
                }

                if (isset($matches['ip'])) {
                    $query->orWhere('client_ip', $matches['ip']);
                }

                if (isset($matches['device'])) {
                    $query->orWhere('device_id', $matches['device']);
                }
            })
            ->latest('placed_at')
            ->first();

        if (! $recentOrder || ! $recentOrder->placed_at) {
            return null;
        }

        $availableAt = $recentOrder->placed_at->copy()->addMinutes($cooldownMinutes);

        if ($availableAt->isPast()) {
            return null;
        }

        $remainingSeconds = max(1, Carbon::now()->diffInSeconds($availableAt, false));
        $messageTemplate = $settings['message'] ?: 'You can place another order after {{time}}.';
        $readableTime = $this->formatCheckoutGuardDuration($remainingSeconds);
        $matchedBy = [];

        if (($matches['phone'] ?? null) === $recentOrder->phone) {
            $matchedBy[] = 'phone';
        }

        if (($matches['ip'] ?? null) === $recentOrder->client_ip) {
            $matchedBy[] = 'ip';
        }

        if (($matches['device'] ?? null) === $recentOrder->device_id) {
            $matchedBy[] = 'device';
        }

        return [
            'blocked' => true,
            'message' => str_replace('{{time}}', $readableTime, $messageTemplate),
            'available_at' => $availableAt->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
            'matched_by' => $matchedBy,
        ];
    }

    protected function resolveNextCheckoutGuardState(Order $order): ?array
    {
        $settings = $this->settings->getGroup('checkout_guard');

        if (! ($settings['enabled'] ?? false) || ! $order->placed_at) {
            return null;
        }

        $cooldownMinutes = max(1, (int) ($settings['cooldown_minutes'] ?? 180));
        $availableAt = $order->placed_at->copy()->addMinutes($cooldownMinutes);
        $remainingSeconds = max(1, Carbon::now()->diffInSeconds($availableAt, false));

        return [
            'blocked' => false,
            'available_at' => $availableAt->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    protected function resolveClientIp(Request $request): ?string
    {
        $forwardedFor = $request->header('x-forwarded-for');

        if ($forwardedFor) {
            return trim(explode(',', $forwardedFor)[0]) ?: null;
        }

        return $request->ip();
    }

    protected function formatCheckoutGuardDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%d hour%s %d minute%s', $hours, $hours === 1 ? '' : 's', $minutes, $minutes === 1 ? '' : 's');
        }

        return sprintf('%d minute%s', max(1, $minutes), $minutes === 1 ? '' : 's');
    }

    protected function buildGuestEmail(string $phone): string
    {
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone) ?: 'guest';

        return sprintf('%s-%s@guest.checkout', $normalizedPhone, strtolower((string) str()->random(6)));
    }

    protected function resolveAuthenticatedUser(Request $request): ?User
    {
        if ($request->user() instanceof User) {
            return $request->user();
        }

        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        try {
            $payload = $this->jwtService->decode($token);
            $user = User::find($payload->sub);

            if (! $user || $user->role !== 'customer') {
                return null;
            }

            $request->setUserResolver(fn () => $user);

            return $user;
        } catch (Throwable) {
            return null;
        }
    }

    protected function sendOrderNotification(Order $order): void
    {
        $smsSettings = $this->settings->getGroup('sms_integration');

        if (
            ! ($smsSettings['enabled'] ?? false) ||
            ! ($smsSettings['enable_order_notification_sms'] ?? true) ||
            ! $order->phone
        ) {
            return;
        }

        try {
            $message = $this->smsOtpService->renderOrderTemplate([
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'total' => number_format((float) $order->grand_total, 0),
                'phone' => $order->phone,
            ]);

            $this->smsGatewayService->sendMessage($order->phone, $message);
        } catch (Throwable) {
            // Do not block successful order placement if SMS provider is unavailable.
        }
    }
}
