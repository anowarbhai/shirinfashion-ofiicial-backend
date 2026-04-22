<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminSettingsService;
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
            'shipping_address' => ['required', 'array'],
            'shipping_address.address' => ['required', 'string'],
            'shipping_address.city' => ['nullable', 'string'],
            'shipping_address.country' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $customer = $this->resolveAuthenticatedUser($request);

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

        $order = DB::transaction(function () use ($customer, $payload) {
            $productIds = collect($payload['items'])->pluck('product_id')->all();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            $subtotal = 0;
            $orderItems = [];

            foreach ($payload['items'] as $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more products could not be found.'],
                    ]);
                }

                if ($product->inventory < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} does not have enough stock."],
                    ]);
                }

                $lineTotal = (float) $product->price * (int) $item['quantity'];
                $subtotal += $lineTotal;
                $orderItems[] = [
                    'product' => $product,
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

            $order = Order::create([
                'order_number' => 'SBA-'.random_int(1000, 9999),
                'user_id' => $customer?->id,
                'customer_name' => $payload['customer_name'],
                'email' => $payload['email'] ?? $customer?->email ?? $this->buildGuestEmail($payload['phone']),
                'phone' => $payload['phone'],
                'status' => 'processing',
                'payment_method' => $payload['payment_method'],
                'payment_status' => $payload['payment_method'] === 'cod' ? 'pending_collection' : 'authorized',
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shippingTotal,
                'grand_total' => $grandTotal,
                'shipping_address' => $shippingAddress,
                'tracking_number' => 'TRK-'.random_int(100000, 999999),
                'placed_at' => Carbon::now(),
            ]);

            foreach ($orderItems as $item) {
                /** @var Product $product */
                $product = $item['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'price' => $product->price,
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                ]);

                $product->decrement('inventory', $item['quantity']);
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

        if (! ($smsSettings['enabled'] ?? false) || ! $order->phone) {
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
