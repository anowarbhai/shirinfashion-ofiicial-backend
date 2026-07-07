<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVolumeDiscount;
use App\Models\User;
use App\Services\AdminSettingsService;
use App\Services\AiOrderCallingService;
use App\Services\CustomerNotificationService;
use App\Services\CouponEligibilityService;
use App\Services\FraudCheckerService;
use App\Services\JwtService;
use App\Services\OrderAssignmentService;
use App\Services\SmsGatewayService;
use App\Services\SmsOtpService;
use App\Services\SslCommerzService;
use App\Support\BangladeshPhone;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected SmsOtpService $smsOtpService,
        protected SmsGatewayService $smsGatewayService,
        protected AdminSettingsService $settings,
        protected AiOrderCallingService $aiOrderCallingService,
        protected FraudCheckerService $fraudCheckerService,
        protected OrderAssignmentService $orderAssignmentService,
        protected CustomerNotificationService $customerNotificationService,
        protected CouponEligibilityService $couponEligibility,
        protected SslCommerzService $sslCommerzService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('items')
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'incomplete')
            ->latest()
            ->get();

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validateOrderPayload($request);

        $customer = $this->resolveAuthenticatedUser($request);
        $clientIp = $this->resolveClientIp($request);
        $checkoutGuard = $this->resolveCheckoutGuardBlock(
            $payload['phone'],
            $clientIp,
            $payload['device_id'] ?? null,
            $payload['cart_session_id'] ?? null,
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

        if ($payload['payment_method'] === 'sslcommerz') {
            return $this->storeSslCommerzOrder($payload, $customer, $clientIp);
        }

        $order = DB::transaction(function () use ($customer, $payload, $clientIp) {
            $prepared = $this->prepareOrderPayload($payload, true, false, $customer);
            $order = $this->findMatchingIncompleteOrder($customer, $prepared)
                ?? new Order(['order_number' => $this->generateOrderNumber()]);
            $hadIncompleteAssignment = $order->exists && $order->status === 'incomplete';

            $this->fillOrderFromPreparedPayload(
                $order,
                $customer,
                $payload,
                $prepared,
                $clientIp,
                'processing',
            );
            $order->payment_status = $payload['payment_method'] === 'cod' ? 'pending_collection' : 'authorized';
            $order->tracking_number = $order->tracking_number ?: 'TRK-'.random_int(100000, 999999);
            $order->placed_at = Carbon::now();
            $order->completed_at = Carbon::now();
            $order->last_activity_at = Carbon::now();
            $order->save();

            $this->replaceOrderItems($order, $prepared['order_items'], true);
            $keptAssignment = $hadIncompleteAssignment
                ? $this->orderAssignmentService->keepExistingModeratorForStatus($order, 'processing')
                : null;
            $keptAssignment ?? $this->orderAssignmentService->assignProcessingOrder($order);

            if (! empty($prepared['coupon_code'])) {
                Coupon::where('code', $prepared['coupon_code'])->increment('used_count');
            }

            $this->deleteDuplicateIncompleteOrders($order, $customer, $prepared);

            return $order->load('items');
        });

        $this->dispatchPostResponseOrderWork($order);

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $order,
            'checkout_guard' => $this->resolveNextCheckoutGuardState($order),
        ], 201);
    }

    public function sslCommerzSuccess(Request $request): JsonResponse|RedirectResponse
    {
        $order = $this->findSslCommerzOrder($request);

        if (! $order) {
            return $this->sslCommerzRedirect('fail', null, 'Order not found.');
        }

        try {
            $validation = $this->sslCommerzService->validateTransaction((string) $request->input('val_id'));
            $this->finalizeSslCommerzOrder($order, $validation);

            return $this->sslCommerzRedirect('success', $order);
        } catch (Throwable $exception) {
            $this->markSslCommerzOrderFailed($order, 'payment_validation_failed', $exception->getMessage());

            return $this->sslCommerzRedirect('fail', $order, 'Payment validation failed.');
        }
    }

    public function sslCommerzIpn(Request $request): JsonResponse
    {
        $order = $this->findSslCommerzOrder($request);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        try {
            $validationId = (string) ($request->input('val_id') ?: $request->input('val_id'));
            $validation = $validationId
                ? $this->sslCommerzService->validateTransaction($validationId)
                : $request->all();
            $this->finalizeSslCommerzOrder($order, $validation, false);

            return response()->json(['message' => 'Payment verified successfully.']);
        } catch (Throwable $exception) {
            $this->markSslCommerzOrderFailed($order, 'payment_ipn_failed', $exception->getMessage());

            return response()->json(['message' => 'Payment validation failed.'], 422);
        }
    }

    public function sslCommerzFail(Request $request): JsonResponse|RedirectResponse
    {
        $order = $this->findSslCommerzOrder($request);

        if ($order) {
            $this->markSslCommerzOrderFailed($order, 'payment_failed', $request->input('failedreason'));
        }

        return $this->sslCommerzRedirect('fail', $order, 'Payment failed.');
    }

    public function sslCommerzCancel(Request $request): JsonResponse|RedirectResponse
    {
        $order = $this->findSslCommerzOrder($request);

        if ($order) {
            $this->markSslCommerzOrderFailed($order, 'payment_cancelled', 'Customer cancelled payment.');
        }

        return $this->sslCommerzRedirect('cancel', $order, 'Payment cancelled.');
    }

    protected function storeSslCommerzOrder(array $payload, ?User $customer, ?string $clientIp): JsonResponse
    {
        $order = DB::transaction(function () use ($customer, $payload, $clientIp) {
            $prepared = $this->prepareOrderPayload($payload, true, false, $customer);
            $order = $this->findMatchingIncompleteOrder($customer, $prepared)
                ?? new Order(['order_number' => $this->generateOrderNumber()]);

            $this->fillOrderFromPreparedPayload(
                $order,
                $customer,
                $payload,
                $prepared,
                $clientIp,
                'pending',
            );
            $order->payment_status = 'pending';
            $order->tracking_number = null;
            $order->placed_at = null;
            $order->completed_at = null;
            $order->last_activity_at = Carbon::now();
            $order->save();

            $this->replaceOrderItems($order, $prepared['order_items'], false);
            $this->deleteDuplicateIncompleteOrders($order, $customer, $prepared);

            return $order->load('items');
        });

        try {
            $paymentSession = $this->sslCommerzService->initiate($order);
        } catch (Throwable $exception) {
            $this->markSslCommerzOrderFailed($order, 'payment_initiation_failed', $exception->getMessage());
            Log::warning('SSLCommerz payment initiation failed.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->publicSslCommerzFailureMessage($exception),
                'data' => $order->fresh('items'),
            ], 422);
        }

        return response()->json([
            'message' => 'SSLCommerz payment session created successfully.',
            'data' => $order,
            'payment' => [
                'provider' => 'sslcommerz',
                'redirect_url' => $paymentSession['GatewayPageURL'],
                'session_key' => $paymentSession['sessionkey'] ?? null,
            ],
            'checkout_guard' => $this->resolveNextCheckoutGuardState($order),
        ], 201);
    }

    protected function publicSslCommerzFailureMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return match (true) {
            $message === 'SSLCommerz payment is not enabled.' =>
                'SSLCommerz payment is not enabled yet. Please choose Cash on Delivery.',
            $message === 'SSLCommerz credentials are not configured.' =>
                'SSLCommerz credentials are missing. Please check payment gateway settings.',
            str_contains($message, 'could not be created') =>
                'SSLCommerz service is not responding right now. Please try again or choose Cash on Delivery.',
            str_contains($message, 'did not return a payment URL') =>
                'SSLCommerz did not return a payment link. Please check sandbox/live credential mode.',
            $message !== '' =>
                "SSLCommerz rejected the payment request: {$message}",
            default =>
                'SSLCommerz payment could not be started. Please try again or choose Cash on Delivery.',
        };
    }

    protected function findSslCommerzOrder(Request $request): ?Order
    {
        $orderId = $request->input('value_a');
        $orderNumber = $request->input('tran_id') ?: $request->input('value_b');

        if (! $orderId && ! $orderNumber) {
            return null;
        }

        return Order::query()
            ->with('items')
            ->when($orderId, fn ($query) => $query->whereKey($orderId))
            ->when(! $orderId && $orderNumber, fn ($query) => $query->where('order_number', $orderNumber))
            ->first();
    }

    protected function finalizeSslCommerzOrder(Order $order, array $validation, bool $dispatchWork = true): void
    {
        if (! $this->sslCommerzService->isSuccessful($validation)) {
            throw new RuntimeException('SSLCommerz payment was not valid.');
        }

        $validatedTransactionId = (string) ($validation['tran_id'] ?? '');

        if ($validatedTransactionId && $validatedTransactionId !== $order->order_number) {
            throw new RuntimeException('SSLCommerz transaction does not match this order.');
        }

        $validatedAmount = round((float) ($validation['amount'] ?? $validation['store_amount'] ?? 0), 2);
        $orderAmount = round((float) $order->grand_total, 2);

        if ($validatedAmount <= 0 || abs($validatedAmount - $orderAmount) > 0.01) {
            throw new RuntimeException('SSLCommerz amount does not match this order.');
        }

        $shouldDispatch = false;

        DB::transaction(function () use ($order, &$shouldDispatch): void {
            $lockedOrder = Order::query()
                ->with('items')
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->payment_status === 'paid') {
                return;
            }

            $this->decrementInventoryForPlacedOrder($lockedOrder);

            $lockedOrder->payment_status = 'paid';
            $lockedOrder->status = 'processing';
            $lockedOrder->tracking_number = $lockedOrder->tracking_number ?: 'TRK-'.random_int(100000, 999999);
            $lockedOrder->placed_at = $lockedOrder->placed_at ?: Carbon::now();
            $lockedOrder->completed_at = $lockedOrder->completed_at ?: Carbon::now();
            $lockedOrder->last_activity_at = Carbon::now();
            $lockedOrder->save();

            $this->orderAssignmentService->assignProcessingOrder($lockedOrder);

            if (! empty($lockedOrder->coupon_code)) {
                Coupon::where('code', $lockedOrder->coupon_code)->increment('used_count');
            }

            $order->setRawAttributes($lockedOrder->getAttributes(), true);
            $shouldDispatch = true;
        });

        $freshOrder = $order->fresh('items');

        if ($dispatchWork && $shouldDispatch) {
            $this->dispatchPostResponseOrderWork($freshOrder);

            return;
        }

        $this->dispatchPostResponseFraudCheck($freshOrder);
    }

    protected function decrementInventoryForPlacedOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = Product::query()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->first();

            if (! $product || ! $product->manage_stock) {
                continue;
            }

            if ($item->is_free_gift) {
                if ($product->inventory > 0) {
                    Product::query()
                        ->whereKey($product->id)
                        ->where('inventory', '>', 0)
                        ->decrement('inventory');
                }

                continue;
            }

            $quantity = max(1, (int) $item->quantity);
            $updated = Product::query()
                ->whereKey($product->id)
                ->where('inventory', '>=', $quantity)
                ->decrement('inventory', $quantity);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} does not have enough stock."],
                ]);
            }
        }
    }

    protected function markSslCommerzOrderFailed(Order $order, string $statusDetail, ?string $message = null): void
    {
        $notes = trim(implode("\n", array_filter([
            $order->notes,
            'SSLCommerz: '.$statusDetail.($message ? ' - '.$message : ''),
        ])));

        $order->forceFill([
            'status' => 'cancelled',
            'payment_status' => 'failed',
            'last_activity_at' => Carbon::now(),
            'notes' => $notes ?: null,
        ])->save();
    }

    protected function sslCommerzRedirect(string $state, ?Order $order, ?string $message = null): RedirectResponse
    {
        $path = $state === 'success' ? '/checkout/success' : '/checkout';
        $query = [
            'payment' => 'sslcommerz',
            'status' => $state,
        ];

        if ($order) {
            $query['order_id'] = $order->order_number;
        }

        if ($message) {
            $query['message'] = $message;
        }

        return redirect()->away($this->sslCommerzService->frontendUrl($path, $query));
    }

    public function storeIncomplete(Request $request): JsonResponse
    {
        $payload = $this->validateOrderPayload($request);
        $customer = $this->resolveAuthenticatedUser($request);
        $clientIp = $this->resolveClientIp($request);
        $checkoutGuard = $this->resolveIncompleteOrderGuardBlock(
            $payload['phone'],
            $clientIp,
            $payload['device_id'] ?? null,
            $payload['cart_session_id'] ?? null,
        );

        if ($checkoutGuard) {
            return response()->json([
                'message' => 'Incomplete order skipped during checkout cooldown.',
                'data' => null,
                'checkout_guard' => $checkoutGuard,
                'incomplete_order_skipped' => true,
            ]);
        }

        $order = DB::transaction(function () use ($customer, $payload, $clientIp) {
            $prepared = $this->prepareOrderPayload($payload, false, false, $customer, false);
            $recentCompletedOrder = $this->findRecentCompletedOrder($customer, $prepared);

            if ($recentCompletedOrder) {
                return $recentCompletedOrder->load('items');
            }

            $order = $this->findMatchingIncompleteOrder($customer, $prepared)
                ?? new Order(['order_number' => $this->generateOrderNumber()]);

            $this->fillOrderFromPreparedPayload(
                $order,
                $customer,
                $payload,
                $prepared,
                $clientIp,
                'incomplete',
            );
            $order->payment_status = 'pending';
            $order->tracking_number = null;
            $order->placed_at = null;
            $order->completed_at = null;
            $order->last_activity_at = Carbon::now();
            $order->save();

            $this->replaceOrderItems($order, $prepared['order_items'], false);
            $this->orderAssignmentService->assignIncompleteOrder($order);
            $this->deleteDuplicateIncompleteOrders($order, $customer, $prepared);

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Incomplete order saved successfully.',
            'data' => $order,
        ]);
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
            'phone' => ['required', 'string', 'max:30'],
        ]);

        try {
            $phone = BangladeshPhone::normalizeToLocal($payload['phone']);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Order could not be found.'], 404);
        }
        $phoneVariants = [$phone, '880'.substr($phone, 1), '+880'.substr($phone, 1)];

        $order = Order::query()
            ->where(function ($query) use ($payload): void {
                if (! empty($payload['order_number'])) {
                    $query->where('order_number', $payload['order_number']);
                } else {
                    $query->where('tracking_number', $payload['tracking_number']);
                }
            })
            ->where(function ($query) use ($phone, $phoneVariants): void {
                $query->where('normalized_phone', $this->normalizePhoneForMatch($phone))
                    ->orWhereIn('phone', $phoneVariants);
            })
            ->with('items')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order could not be found.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'placed_at' => $order->placed_at,
                'updated_at' => $order->updated_at,
                'items' => $order->items->map(fn ($item): array => [
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])->values(),
            ],
        ]);
    }

    protected function validateOrderPayload(Request $request): array
    {
        $payload = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'payment_method' => ['required', 'in:stripe,paypal,cod,sslcommerz'],
            'shipping_method' => ['required', 'in:inside-dhaka,outside-dhaka'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
            'otp_session_token' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'cart_session_id' => ['nullable', 'string', 'max:120'],
            'order_source' => ['nullable', 'string', 'max:80'],
            'order_source_detail' => ['nullable', 'string', 'max:255'],
            'referrer_url' => ['nullable', 'string', 'max:2000'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.address' => ['required', 'string', 'max:1000'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'],
            'shipping_address.country' => ['nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.volume_discount_id' => ['nullable', 'integer', 'exists:product_volume_discounts,id'],
        ]);

        try {
            $payload['phone'] = BangladeshPhone::normalizeToLocal($payload['phone']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'phone' => [$exception->getMessage()],
            ]);
        }

        return $payload;
    }

    protected function prepareOrderPayload(
        array $payload,
        bool $enforceInventory,
        bool $includeFraudCheck,
        ?User $customer = null,
        bool $enforceCouponUsageLimit = true,
    ): array {
        $productIds = collect($payload['items'])->pluck('product_id')->unique()->sort()->values()->all();
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->when($enforceInventory, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('id');
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

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} is not available for checkout."],
                ]);
            }

            if ($product->stock_status === 'out_of_stock') {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} is out of stock."],
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

                if (! $this->tierSupportsQuantity($tier, (int) $item['quantity'])) {
                    throw ValidationException::withMessages([
                        'items' => [$this->tierQuantityMessage($tier)],
                    ]);
                }
            }

            if ($enforceInventory && $product->manage_stock && $product->inventory < $item['quantity']) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} does not have enough stock."],
                ]);
            }

            $lineTotal = $tier
                ? $this->calculateVolumeDiscountLineTotal($tier, (int) $item['quantity'])
                : (float) $product->price * (int) $item['quantity'];
            $subtotal += $lineTotal;
            $orderItems[] = [
                'product' => $product,
                'tier' => $tier,
                'quantity' => (int) $item['quantity'],
                'line_total' => $lineTotal,
            ];
        }

        $coupon = $this->resolveCoupon(
            $payload['coupon_code'] ?? null,
            $subtotal,
            $enforceCouponUsageLimit,
        );

        if ($coupon && $enforceCouponUsageLimit) {
            $this->couponEligibility->assertEligible(
                $coupon,
                [
                    'source' => $payload['order_source'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'email' => $payload['email'] ?? null,
                ],
                $customer,
            );
            $this->ensureCouponPerUserLimit($coupon, $payload, $customer);
        }

        $discountTotal = $coupon ? $this->calculateCouponDiscount($coupon, $subtotal) : 0;
        $shippingAddress = [
            'address' => $payload['shipping_address']['address'],
            'city' => $payload['shipping_address']['city']
                ?? ($payload['shipping_method'] === 'inside-dhaka' ? 'Dhaka' : 'Outside Dhaka'),
            'country' => $payload['shipping_address']['country'] ?? 'Bangladesh',
        ];
        $shippingTotal = $this->resolveShippingTotal($payload['shipping_method'], $subtotal);

        return [
            'order_items' => $orderItems,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'coupon_code' => $coupon?->code,
            'shipping_total' => $shippingTotal,
            'grand_total' => $subtotal + $shippingTotal - $discountTotal,
            'shipping_address' => $shippingAddress,
            'fraud_check' => $includeFraudCheck ? $this->resolveFraudCheck($payload['phone']) : null,
            'normalized_phone' => $this->normalizePhoneForMatch($payload['phone']),
            'normalized_address_hash' => $this->hashAddressForMatch($shippingAddress),
            'cart_hash' => $this->hashCartForMatch($payload),
            'cart_session_id' => $payload['cart_session_id'] ?? null,
        ];
    }

    protected function fillOrderFromPreparedPayload(
        Order $order,
        ?User $customer,
        array $payload,
        array $prepared,
        ?string $clientIp,
        string $status,
    ): void {
        $order->fill([
            'user_id' => $customer?->id,
            'customer_name' => $payload['customer_name'],
            'email' => $payload['email'] ?? $customer?->email ?? $order->email ?? $this->buildGuestEmail($payload['phone']),
            'phone' => $payload['phone'],
            'normalized_phone' => $prepared['normalized_phone'],
            'client_ip' => $clientIp,
            'device_id' => $payload['device_id'] ?? null,
            'cart_session_id' => $payload['cart_session_id'] ?? null,
            'cart_hash' => $prepared['cart_hash'],
            'order_source' => $this->normalizeOrderSource($payload['order_source'] ?? null, $payload['utm_source'] ?? null, $payload['referrer_url'] ?? null),
            'order_source_detail' => $payload['order_source_detail'] ?? null,
            'referrer_url' => $payload['referrer_url'] ?? null,
            'utm_source' => $payload['utm_source'] ?? null,
            'status' => $status,
            'payment_method' => $payload['payment_method'],
            'subtotal' => $prepared['subtotal'],
            'discount_total' => $prepared['discount_total'],
            'coupon_code' => $prepared['coupon_code'],
            'shipping_total' => $prepared['shipping_total'],
            'grand_total' => $prepared['grand_total'],
            'shipping_address' => $prepared['shipping_address'],
            'normalized_address_hash' => $prepared['normalized_address_hash'],
            'fraud_check' => $prepared['fraud_check'],
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    protected function replaceOrderItems(Order $order, array $orderItems, bool $decrementInventory): void
    {
        $order->items()->delete();

        foreach ($orderItems as $item) {
            /** @var Product $product */
            $product = $item['product'];

            $order->items()->create($this->orderItemPayload([
                'product_id' => $product->id,
                'volume_discount_id' => $item['tier']?->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'product_image' => $this->firstProductImage($product),
                'price' => $item['tier']
                    ? round($item['line_total'] / max(1, $item['quantity']), 2)
                    : $product->price,
                'quantity' => $item['quantity'],
                'line_total' => $item['line_total'],
                'is_free_gift' => false,
            ]));

            if ($decrementInventory && $product->manage_stock) {
                $updated = Product::query()
                    ->whereKey($product->id)
                    ->where('inventory', '>=', $item['quantity'])
                    ->decrement('inventory', $item['quantity']);

                if ($updated !== 1) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} does not have enough stock."],
                    ]);
                }
            }

            if ($item['tier']?->freeProduct) {
                $gift = $item['tier']->freeProduct;

                $order->items()->create($this->orderItemPayload([
                    'product_id' => $gift->id,
                    'volume_discount_id' => $item['tier']->id,
                    'product_name' => $gift->name.' (Free Gift)',
                    'sku' => $gift->sku,
                    'product_image' => $this->firstProductImage($gift),
                    'price' => 0,
                    'quantity' => 1,
                    'line_total' => 0,
                    'is_free_gift' => true,
                ]));

                if ($decrementInventory && $gift->manage_stock && $gift->inventory > 0) {
                    Product::query()
                        ->whereKey($gift->id)
                        ->where('inventory', '>', 0)
                        ->decrement('inventory');
                }
            }
        }
    }

    protected function firstProductImage(Product $product): ?string
    {
        return $product->gallery[0] ?? null;
    }

    protected function tierSupportsQuantity(ProductVolumeDiscount $tier, int $quantity): bool
    {
        if ($quantity === (int) $tier->quantity) {
            return true;
        }

        return $quantity > (int) $tier->quantity && $tier->extra_unit_price !== null;
    }

    protected function tierQuantityMessage(ProductVolumeDiscount $tier): string
    {
        if ($tier->extra_unit_price !== null) {
            return "{$tier->label} requires at least {$tier->quantity} items.";
        }

        return "{$tier->label} requires exactly {$tier->quantity} items.";
    }

    protected function calculateVolumeDiscountLineTotal(ProductVolumeDiscount $tier, int $quantity): float
    {
        $baseQuantity = max(1, (int) $tier->quantity);
        $extraQuantity = max(0, $quantity - $baseQuantity);

        return (float) $tier->flat_price + ($extraQuantity * (float) ($tier->extra_unit_price ?? 0));
    }

    protected function orderItemPayload(array $payload): array
    {
        if (! $this->orderItemsHaveProductImageColumn()) {
            unset($payload['product_image']);
        }

        return $payload;
    }

    protected function orderItemsHaveProductImageColumn(): bool
    {
        static $hasColumn = null;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('order_items', 'product_image');
        }

        return $hasColumn;
    }

    protected function findMatchingIncompleteOrder(?User $customer, array $prepared): ?Order
    {
        $query = Order::query()->where('status', 'incomplete');

        if ($customer) {
            $query->where('user_id', $customer->id);
        } else {
            $cartSessionId = $prepared['cart_session_id'] ?? null;
            $normalizedPhone = $prepared['normalized_phone'] ?? null;

            if (! $cartSessionId && ! $normalizedPhone) {
                return null;
            }

            $query->where(function ($query) use ($cartSessionId, $normalizedPhone): void {
                if ($cartSessionId) {
                    $query->orWhere('cart_session_id', $cartSessionId);
                }

                if ($normalizedPhone) {
                    $query->orWhere('normalized_phone', $normalizedPhone);
                }
            });
        }

        return $query
            ->latest('last_activity_at')
            ->latest()
            ->first();
    }

    protected function findRecentCompletedOrder(?User $customer, array $prepared): ?Order
    {
        $query = Order::query()
            ->where('status', '!=', 'incomplete')
            ->where('cart_hash', $prepared['cart_hash'])
            ->where('completed_at', '>=', Carbon::now()->subMinutes(10));

        if ($customer) {
            $query->where('user_id', $customer->id);
        } else {
            $cartSessionId = $prepared['cart_session_id'] ?? null;
            $normalizedPhone = $prepared['normalized_phone'] ?? null;

            if (! $cartSessionId && ! $normalizedPhone) {
                return null;
            }

            $query->where(function ($query) use ($cartSessionId, $normalizedPhone): void {
                if ($normalizedPhone) {
                    $query->orWhere('normalized_phone', $normalizedPhone);

                    return;
                }

                if ($cartSessionId) {
                    $query->orWhere('cart_session_id', $cartSessionId);
                }
            });
        }

        return $query->latest('completed_at')->first();
    }

    protected function deleteDuplicateIncompleteOrders(
        Order $completedOrder,
        ?User $customer,
        array $prepared,
    ): void {
        $query = Order::query()
            ->where('status', 'incomplete')
            ->whereKeyNot($completedOrder->id);

        if ($customer) {
            $query->where('user_id', $customer->id);
        } else {
            $cartSessionId = $prepared['cart_session_id'] ?? null;
            $normalizedPhone = $prepared['normalized_phone'] ?? null;

            if (! $cartSessionId && ! $normalizedPhone) {
                return;
            }

            $query->where(function ($query) use ($cartSessionId, $normalizedPhone): void {
                if ($cartSessionId) {
                    $query->orWhere('cart_session_id', $cartSessionId);
                }

                if ($normalizedPhone) {
                    $query->orWhere('normalized_phone', $normalizedPhone);
                }
            });
        }

        $query->get()->each(function (Order $order): void {
            $order->items()->delete();
            $order->delete();
        });
    }

    protected function normalizePhoneForMatch(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }

    /**
     * @return array<int, string>
     */
    protected function phoneVariantsForMatch(string $phone): array
    {
        $normalized = $this->normalizePhoneForMatch($phone);
        $variants = [trim($phone), $normalized];

        if (str_starts_with($normalized, '0') && strlen($normalized) === 11) {
            $international = '880'.substr($normalized, 1);
            $variants[] = $international;
            $variants[] = '+'.$international;
        }

        return array_values(array_unique(array_filter(
            $variants,
            fn (string $value): bool => trim($value) !== '',
        )));
    }

    protected function hashAddressForMatch(array $shippingAddress): string
    {
        $normalized = collect([
            $shippingAddress['address'] ?? '',
            $shippingAddress['city'] ?? '',
            $shippingAddress['country'] ?? '',
        ])
            ->map(fn ($value) => preg_replace('/\s+/', ' ', strtolower(trim((string) $value))))
            ->filter()
            ->implode('|');

        return hash('sha256', $normalized);
    }

    protected function hashCartForMatch(array $payload): string
    {
        $items = collect($payload['items'])
            ->map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'volume_discount_id' => isset($item['volume_discount_id'])
                    ? (int) $item['volume_discount_id']
                    : null,
            ])
            ->sortBy([
                ['product_id', 'asc'],
                ['volume_discount_id', 'asc'],
                ['quantity', 'asc'],
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'items' => $items,
            'shipping_method' => $payload['shipping_method'],
            'coupon_code' => strtoupper((string) ($payload['coupon_code'] ?? '')),
        ]));
    }

    protected function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'SBA-'.random_int(10000000, 99999999);
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    protected function resolveCoupon(?string $couponCode, float $subtotal, bool $lockForUpdate = false): ?Coupon
    {
        if (! $couponCode) {
            return null;
        }

        $coupon = Coupon::where('code', strtoupper($couponCode))
            ->where('is_active', true)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $coupon) {
            return null;
        }

        if (($coupon->starts_at && $coupon->starts_at->isFuture()) ||
            ($coupon->ends_at && $coupon->ends_at->isPast())) {
            return null;
        }

        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            return null;
        }

        if ($subtotal < (float) $coupon->minimum_order_amount) {
            return null;
        }

        if ($coupon->maximum_order_amount !== null && $subtotal > (float) $coupon->maximum_order_amount) {
            return null;
        }

        return $coupon;
    }

    protected function calculateCouponDiscount(Coupon $coupon, float $subtotal): float
    {
        return $coupon->type === 'fixed'
            ? min((float) $coupon->value, $subtotal)
            : round($subtotal * ((float) $coupon->value / 100), 2);
    }

    protected function ensureCouponPerUserLimit(Coupon $coupon, array $payload, ?User $customer): void
    {
        $limit = (int) ($coupon->per_user_limit ?? 0);

        if ($limit < 1) {
            return;
        }

        $count = $this->couponUsageCount($coupon, $payload, $customer);

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'coupon_code' => ["This coupon can only be used {$limit} time(s) per customer."],
            ]);
        }
    }

    protected function couponUsageCount(Coupon $coupon, array $payload, ?User $customer): int
    {
        $normalizedPhone = $this->normalizePhoneForMatch((string) ($payload['phone'] ?? ''));
        $normalizedEmail = strtolower(trim((string) ($payload['email'] ?? $customer?->email ?? '')));

        if (! $customer && $normalizedPhone === '' && $normalizedEmail === '') {
            return 0;
        }

        return Order::query()
            ->where('coupon_code', $coupon->code)
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
            ->count();
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
        $provider = (string) ($fraudSettings['provider'] ?? 'onesoftcode');
        $apiKey = $provider === 'bd_courier'
            ? trim((string) ($fraudSettings['bd_courier_api_key'] ?? ''))
            : trim((string) ($fraudSettings['onesoftcode_api_key'] ?? $fraudSettings['api_key'] ?? ''));

        if (! ($fraudSettings['enabled'] ?? false) || $apiKey === '') {
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
        ?string $cartSessionId = null,
        bool $forceCustomerSignals = false,
    ): ?array {
        $settings = $this->settings->getGroup('checkout_guard');

        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        $cooldownMinutes = max(1, (int) ($settings['cooldown_minutes'] ?? 180));
        $cutoff = Carbon::now()->subMinutes($cooldownMinutes);
        $matches = [];
        $normalizedDeviceId = $deviceId ? trim($deviceId) : null;
        $normalizedCartSessionId = $cartSessionId ? trim($cartSessionId) : null;
        $normalizedClientIp = $clientIp ? trim($clientIp) : null;
        $hasPhoneMatch = (($settings['block_by_phone'] ?? true) || $forceCustomerSignals) && trim($phone) !== '';

        if ($hasPhoneMatch) {
            $matches['phone'] = trim($phone);
            $matches['normalized_phone'] = $this->normalizePhoneForMatch($phone);
            $matches['phone_variants'] = $this->phoneVariantsForMatch($phone);
        }

        if (! $hasPhoneMatch && ((($settings['block_by_ip'] ?? true) || $forceCustomerSignals) && $normalizedClientIp)) {
            $matches['ip'] = $normalizedClientIp;
        }

        if (! $hasPhoneMatch && ((($settings['block_by_device'] ?? true) || $forceCustomerSignals) && $normalizedDeviceId)) {
            $matches['device'] = $normalizedDeviceId;
        }

        if (! $hasPhoneMatch && ((($settings['block_by_device'] ?? true) || $forceCustomerSignals) && $normalizedCartSessionId)) {
            $matches['cart_session'] = $normalizedCartSessionId;
        }

        if ($matches === []) {
            return null;
        }

        $recentOrder = Order::query()
            ->where(DB::raw('COALESCE(placed_at, completed_at, created_at)'), '>=', $cutoff)
            ->whereNotIn('status', ['cancelled', 'refunded', 'incomplete'])
            ->where(function ($query) use ($matches): void {
                if (! empty($matches['phone_variants'])) {
                    $query->orWhereIn('phone', $matches['phone_variants']);
                }

                if (isset($matches['normalized_phone'])) {
                    $query->orWhere('normalized_phone', $matches['normalized_phone']);
                }

                if (isset($matches['ip'])) {
                    $query->orWhere('client_ip', $matches['ip']);
                }

                if (isset($matches['device'])) {
                    $query->orWhere('device_id', $matches['device']);
                }

                if (isset($matches['cart_session'])) {
                    $query->orWhere('cart_session_id', $matches['cart_session']);
                }
            })
            ->orderByDesc(DB::raw('COALESCE(placed_at, completed_at, created_at)'))
            ->first();

        $recentOrderAt = $recentOrder?->placed_at ?? $recentOrder?->completed_at ?? $recentOrder?->created_at;

        if (! $recentOrder || ! $recentOrderAt) {
            return null;
        }

        $availableAt = $recentOrderAt->copy()->addMinutes($cooldownMinutes);

        if ($availableAt->isPast()) {
            return null;
        }

        $remainingSeconds = max(1, Carbon::now()->diffInSeconds($availableAt, false));
        $messageTemplate = $settings['message'] ?: 'You can place another order after {{time}}.';
        $readableTime = $this->formatCheckoutGuardDuration($remainingSeconds);
        $matchedBy = [];

        if (
            in_array($recentOrder->phone, $matches['phone_variants'] ?? [], true) ||
            (($matches['normalized_phone'] ?? null) && ($matches['normalized_phone'] ?? null) === $recentOrder->normalized_phone)
        ) {
            $matchedBy[] = 'phone';
        }

        if (($matches['ip'] ?? null) === $recentOrder->client_ip) {
            $matchedBy[] = 'ip';
        }

        if (
            ($matches['device'] ?? null) === $recentOrder->device_id ||
            (($matches['cart_session'] ?? null) && ($matches['cart_session'] ?? null) === $recentOrder->cart_session_id)
        ) {
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

    protected function resolveIncompleteOrderGuardBlock(
        string $phone,
        ?string $clientIp,
        ?string $deviceId,
        ?string $cartSessionId = null,
    ): ?array {
        $settings = $this->settings->getGroup('checkout_guard');

        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        return $this->resolveCheckoutGuardBlock($phone, $clientIp, $deviceId, $cartSessionId, true);
    }

    protected function resolveNextCheckoutGuardState(Order $order): ?array
    {
        $settings = $this->settings->getGroup('checkout_guard');

        $orderPlacedAt = $order->placed_at ?? $order->completed_at ?? $order->created_at;

        if (! ($settings['enabled'] ?? false) || ! $orderPlacedAt) {
            return null;
        }

        $cooldownMinutes = max(1, (int) ($settings['cooldown_minutes'] ?? 180));
        $availableAt = $orderPlacedAt->copy()->addMinutes($cooldownMinutes);
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

    protected function normalizeOrderSource(?string $source, ?string $utmSource, ?string $referrerUrl): string
    {
        $value = strtolower(trim((string) ($utmSource ?: $source)));

        if ($value !== '') {
            return match (true) {
                str_contains($value, 'facebook'), str_contains($value, 'fb') => 'Facebook',
                str_contains($value, 'google'), str_contains($value, 'gads'), str_contains($value, 'adwords') => 'Google',
                str_contains($value, 'instagram'), str_contains($value, 'ig') => 'Instagram',
                str_contains($value, 'whatsapp') => 'WhatsApp',
                str_contains($value, 'youtube') => 'YouTube',
                str_contains($value, 'tiktok') => 'TikTok',
                str_contains($value, 'direct') => 'Direct',
                default => str($value)->replace(['-', '_'], ' ')->title()->toString(),
            };
        }

        $host = parse_url((string) $referrerUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'Direct';
        }

        $host = strtolower($host);

        return match (true) {
            str_contains($host, 'facebook.com'), str_contains($host, 'fb.com') => 'Facebook',
            str_contains($host, 'google.') => 'Google',
            str_contains($host, 'instagram.com') => 'Instagram',
            str_contains($host, 'whatsapp.') || str_contains($host, 'wa.me') => 'WhatsApp',
            str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be') => 'YouTube',
            str_contains($host, 'tiktok.com') => 'TikTok',
            default => 'Direct',
        };
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

    protected function dispatchPostResponseOrderWork(Order $order): void
    {
        $orderId = (int) $order->id;

        app()->terminating(function () use ($orderId): void {
            $order = Order::query()->find($orderId);

            if (! $order) {
                return;
            }

            $this->sendOrderNotification($order);
            $this->aiOrderCallingService->triggerForOrder($order);

            if ($order->user_id) {
                $this->customerNotificationService->sendToUser(
                    (int) $order->user_id,
                    'Order placed successfully',
                    'Your order '.($order->order_number ?: '#'.$order->id).' has been placed.',
                    'order_status',
                    [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'grand_total' => (string) $order->grand_total,
                        'new_status' => $order->status,
                    ],
                );
            }

            $this->runPostResponseFraudCheck($order);
        });
    }

    protected function dispatchPostResponseFraudCheck(Order $order): void
    {
        $orderId = (int) $order->id;

        app()->terminating(function () use ($orderId): void {
            $order = Order::query()->find($orderId);

            if (! $order) {
                return;
            }

            $this->runPostResponseFraudCheck($order);
        });
    }

    protected function runPostResponseFraudCheck(Order $order): void
    {
        if (! $order->phone || $order->fraud_check !== null) {
            return;
        }

        $order->forceFill([
            'fraud_check' => $this->resolveFraudCheck($order->phone),
        ])->save();
    }
}
