<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Moderator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVolumeDiscount;
use App\Services\AdminSettingsService;
use App\Services\FraudCheckerService;
use App\Services\OrderAssignmentService;
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
        protected OrderAssignmentService $orderAssignmentService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with(['items', 'assignments.moderator.user', 'assignedModerator'])
            ->latest();

        $this->applyAssignmentVisibility($query, $request);

        if ($request->filled('moderator_id')) {
            $query->whereHas('assignments', fn ($assignmentQuery) => $assignmentQuery
                ->whereNull('order_item_id')
                ->where('moderator_id', (int) $request->query('moderator_id')));
        }

        if ($request->filled('assignment_status_type')) {
            $query->where('assignment_status_type', $request->query('assignment_status_type'));
        }

        if ($request->filled('assignment_status')) {
            $query->where('assignment_status', $request->query('assignment_status'));
        }

        return response()->json([
            'data' => $query->paginate(20),
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

            $this->orderAssignmentService->assignOrder($order);

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $order,
        ], 201);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->ensureCanAccessOrder($request, $order);

        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:255'],
            'payment_status' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ]);

        $beforeStatusType = $order->status === 'incomplete' ? 'incomplete' : 'processing';
        $order->update($payload);
        $afterStatusType = $order->fresh()->status === 'incomplete' ? 'incomplete' : 'processing';

        if ($beforeStatusType !== $afterStatusType) {
            $this->orderAssignmentService->keepExistingModeratorForStatus($order->fresh(), $afterStatusType)
                ?? $this->orderAssignmentService->assignOrderByStatus($order->fresh(), $afterStatusType);
        }

        return response()->json([
            'message' => 'Order updated successfully.',
            'data' => $order->fresh(['items', 'assignments.moderator.user', 'assignedModerator']),
        ]);
    }

    public function reassign(Request $request, Order $order): JsonResponse
    {
        $payload = $request->validate([
            'moderator_id' => ['required', 'integer', 'exists:moderators,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->ensureCanAccessOrder($request, $order);
        $this->ensureCanReassignToModerator($request, (int) $payload['moderator_id']);

        $assignment = $this->orderAssignmentService->reassignOrder(
            $order->id,
            (int) $payload['moderator_id'],
            $request->user()?->id,
            $payload['note'] ?? null,
        );

        return response()->json([
            'message' => 'Order reassigned successfully.',
            'data' => $assignment,
            'order' => $order->fresh(['items', 'assignments.moderator.user', 'assignedModerator']),
        ]);
    }

    public function bulkReassign(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'moderator_id' => ['required', 'integer', 'exists:moderators,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $allowedQuery = Order::query()->whereIn('id', $payload['order_ids']);
        $this->applyAssignmentVisibility($allowedQuery, $request);
        abort_if($allowedQuery->count() !== count(array_unique($payload['order_ids'])), 403, 'You do not have permission to reassign one or more selected orders.');

        $this->ensureCanReassignToModerator($request, (int) $payload['moderator_id'], true);

        $assignments = $this->orderAssignmentService->bulkReassignOrders(
            $payload['order_ids'],
            (int) $payload['moderator_id'],
            $request->user()?->id,
            $payload['note'] ?? null,
        );

        return response()->json([
            'message' => count($assignments).' orders reassigned successfully.',
            'data' => $assignments,
        ]);
    }

    public function checkFraud(Request $request, Order $order): JsonResponse
    {
        $this->ensureCanAccessOrder($request, $order);

        if (! trim((string) $order->phone)) {
            return response()->json([
                'message' => 'Order phone number is missing.',
            ], 422);
        }

        $result = $this->resolveFraudCheck((string) $order->phone);

        if (! $result) {
            return response()->json([
                'message' => 'Fraud checker is disabled or API key is missing.',
            ], 422);
        }

        $order->update(['fraud_check' => $result]);

        return response()->json([
            'message' => 'Fraud checker result saved successfully.',
            'data' => $result,
            'order' => $order->fresh(['items', 'assignments.moderator.user', 'assignedModerator']),
        ]);
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->ensureCanAccessOrder(request(), $order);

        DB::transaction(function () use ($order): void {
            $order->items()->delete();
            $order->delete();
        });

        return response()->json([
            'message' => 'Order deleted successfully.',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:orders,id'],
        ]);

        $ids = collect($payload['ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $allowedQuery = Order::query()->whereIn('id', $ids);
        $this->applyAssignmentVisibility($allowedQuery, $request);
        $allowedIds = $allowedQuery->pluck('id');

        abort_if($allowedIds->count() !== $ids->count(), 403, 'You do not have permission to delete one or more selected orders.');

        DB::transaction(function () use ($ids): void {
            Order::query()
                ->whereIn('id', $ids)
                ->with('items')
                ->get()
                ->each(function (Order $order): void {
                    $order->items()->delete();
                    $order->delete();
                });
        });

        return response()->json([
            'message' => $ids->count() === 1
                ? 'Order deleted successfully.'
                : "{$ids->count()} orders deleted successfully.",
            'deleted_ids' => $ids,
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

    protected function buildGuestEmail(string $phone): string
    {
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone) ?: 'guest';

        return sprintf('%s-%s@guest.admin-order', $normalizedPhone, strtolower((string) str()->random(6)));
    }

    protected function applyAssignmentVisibility($query, Request $request): void
    {
        $user = $request->user();

        if (! $user || $user->hasAdminPermission('system.everything')) {
            return;
        }

        $moderator = $user->moderatorProfile()->first();

        if ($moderator) {
            $query->where(function ($orderQuery) use ($user, $moderator): void {
                $orderQuery
                    ->where('assigned_moderator_id', $user->id)
                    ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery
                        ->whereNull('order_item_id')
                        ->where('moderator_id', $moderator->id));
            });

            return;
        }

        if ($user->hasAdminPermission('moderator.view_all_moderator_orders')) {
            return;
        }

        if ($user->hasAdminPermission('moderator.manage_moderators')) {
            $managedIds = $user->managedModerators()->pluck('id');
            $query->whereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->whereIn('moderator_id', $managedIds));
            return;
        }

        if (! $user->hasAdminPermission('orders.view')) {
            $query->whereRaw('1 = 0');
        }
    }

    protected function ensureCanReassignToModerator(Request $request, int $moderatorId, bool $bulk = false): void
    {
        $user = $request->user();
        $permission = $bulk ? 'moderator.bulk_reassign_orders' : 'moderator.reassign_orders';

        abort_unless((bool) $user?->hasAdminPermission($permission), 403, 'You do not have permission to reassign orders.');

        if ($user->hasAdminPermission('system.everything') || $user->hasAdminPermission('moderator.view_all_moderator_orders')) {
            return;
        }

        $isManaged = Moderator::query()
            ->whereKey($moderatorId)
            ->where('digital_marketer_id', $user->id)
            ->exists();

        abort_unless($isManaged, 403, 'You can only assign orders to moderators under your team.');
    }

    protected function ensureCanAccessOrder(Request $request, Order $order): void
    {
        $query = Order::query()->whereKey($order->id);
        $this->applyAssignmentVisibility($query, $request);

        abort_unless($query->exists(), 403, 'You do not have permission to access this order.');
    }
}
