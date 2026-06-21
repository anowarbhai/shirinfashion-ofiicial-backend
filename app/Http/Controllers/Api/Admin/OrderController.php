<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Moderator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVolumeDiscount;
use App\Services\AdminAuditLogger;
use App\Services\AdminSettingsService;
use App\Services\CustomerNotificationService;
use App\Services\CouponEligibilityService;
use App\Services\FraudCheckerService;
use App\Services\OrderAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        protected AdminSettingsService $settings,
        protected FraudCheckerService $fraudCheckerService,
        protected OrderAssignmentService $orderAssignmentService,
        protected CustomerNotificationService $customerNotificationService,
        protected CouponEligibilityService $couponEligibility,
        protected AdminAuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with(['items', 'assignments.moderator.user', 'assignedModerator'])
            ->orderByDesc(DB::raw('COALESCE(placed_at, completed_at, last_activity_at, created_at)'))
            ->orderByDesc('id');

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

        if ($request->filled('campaign_tracking') && $request->query('campaign_tracking') !== 'all') {
            $this->applyCampaignTrackingFilter($query, (string) $request->query('campaign_tracking'));
        }

        if ($request->filled('order_source') && $request->query('order_source') !== 'all') {
            $source = trim((string) $request->query('order_source'));

            if ($source === 'Direct') {
                $query->where(function ($sourceQuery): void {
                    $sourceQuery
                        ->whereNull('order_source')
                        ->orWhere('order_source', '')
                        ->orWhere('order_source', 'Direct');
                });
            } elseif ($source !== '') {
                $query->where('order_source', $source);
            }
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('payment_status') && $request->query('payment_status') !== 'all') {
            $query->where('payment_status', $request->query('payment_status'));
        }

        if ($request->filled('time') && $request->query('time') !== 'all') {
            $this->applyTimeFilter($query, (string) $request->query('time'));
        }

        if ($request->filled('amount') && $request->query('amount') !== 'all') {
            match ($request->query('amount')) {
                '0-50' => $query->whereBetween('grand_total', [0, 50]),
                '50-100' => $query->where('grand_total', '>', 50)->where('grand_total', '<=', 100),
                '100-500' => $query->where('grand_total', '>', 100)->where('grand_total', '<=', 500),
                '500+' => $query->where('grand_total', '>', 500),
                default => null,
            };
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));

            if ($search !== '') {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('payment_status', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($itemQuery) use ($search) {
                            $itemQuery
                                ->where('product_name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        });
                });
            }
        }

        $summaryQuery = (clone $query)->reorder();
        $incompleteQueueSql = "(
            orders.assignment_status_type = 'incomplete'
            OR (
                orders.completed_at IS NULL
                AND (
                    EXISTS (
                        SELECT 1
                        FROM order_assignments oa
                        WHERE oa.order_id = orders.id
                            AND oa.order_item_id IS NULL
                            AND oa.order_status_type = 'incomplete'
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM order_assignment_histories oah
                        WHERE oah.order_id = orders.id
                            AND oah.order_item_id IS NULL
                            AND oah.order_status_type = 'incomplete'
                    )
                )
            )
        )";
        $completedStatusSql = "status IN ('confirmed', 'shipped', 'delivered')";
        $cancelledStatusSql = "status = 'cancelled'";
        $summaryOrders = $summaryQuery
            ->toBase()
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_revenue')
            ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_orders")
            ->selectRaw("SUM(CASE WHEN status = 'incomplete' THEN 1 ELSE 0 END) as incomplete_orders")
            ->selectRaw("SUM(CASE WHEN {$completedStatusSql} THEN 1 ELSE 0 END) as confirmed_delivery_orders")
            ->selectRaw("SUM(CASE WHEN {$completedStatusSql} AND NOT {$incompleteQueueSql} THEN 1 ELSE 0 END) as completed_from_processing")
            ->selectRaw("SUM(CASE WHEN {$completedStatusSql} AND {$incompleteQueueSql} THEN 1 ELSE 0 END) as completed_from_incomplete")
            ->selectRaw("SUM(CASE WHEN {$cancelledStatusSql} THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw("SUM(CASE WHEN {$cancelledStatusSql} AND NOT {$incompleteQueueSql} THEN 1 ELSE 0 END) as cancelled_from_processing")
            ->selectRaw("SUM(CASE WHEN {$cancelledStatusSql} AND {$incompleteQueueSql} THEN 1 ELSE 0 END) as cancelled_from_incomplete")
            ->first();
        $totalOrders = (int) ($summaryOrders->total_orders ?? 0);
        $processingOrders = (int) ($summaryOrders->processing_orders ?? 0);
        $incompleteOrders = (int) ($summaryOrders->incomplete_orders ?? 0);
        $confirmedDeliveryOrders = (int) ($summaryOrders->confirmed_delivery_orders ?? 0);
        $cancelledOrders = (int) ($summaryOrders->cancelled_orders ?? 0);
        $completedFromProcessing = (int) ($summaryOrders->completed_from_processing ?? 0);
        $completedFromIncomplete = (int) ($summaryOrders->completed_from_incomplete ?? 0);
        $cancelledFromProcessing = (int) ($summaryOrders->cancelled_from_processing ?? 0);
        $cancelledFromIncomplete = (int) ($summaryOrders->cancelled_from_incomplete ?? 0);

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        return response()->json([
            'data' => $query->paginate($perPage),
            'summary' => [
                'total' => $totalOrders,
                'revenue' => (float) ($summaryOrders->total_revenue ?? 0),
                'processing' => $processingOrders,
                'incomplete' => $incompleteOrders,
                'confirmedDelivery' => $confirmedDeliveryOrders,
                'completedFromProcessing' => $completedFromProcessing,
                'completedFromIncomplete' => $completedFromIncomplete,
                'cancelled' => $cancelledOrders,
                'cancelledFromProcessing' => $cancelledFromProcessing,
                'cancelledFromIncomplete' => $cancelledFromIncomplete,
                'processingRate' => $this->percentage($processingOrders, $totalOrders),
                'incompleteRate' => $this->percentage($incompleteOrders, $totalOrders),
                'processingIncompleteRate' => $this->percentage($processingOrders + $incompleteOrders, $totalOrders),
                'completedFromProcessingRate' => $this->percentage($completedFromProcessing, $totalOrders),
                'completedFromIncompleteRate' => $this->percentage($completedFromIncomplete, $totalOrders),
                'confirmedDeliveryRate' => $this->percentage($confirmedDeliveryOrders, $totalOrders),
                'cancelledFromProcessingRate' => $this->percentage($cancelledFromProcessing, $totalOrders),
                'cancelledFromIncompleteRate' => $this->percentage($cancelledFromIncomplete, $totalOrders),
                'cancelledRate' => $this->percentage($cancelledOrders, $totalOrders),
            ],
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

                    if (! $this->tierSupportsQuantity($tier, (int) $item['quantity'])) {
                        throw ValidationException::withMessages([
                            'items' => [$this->tierQuantityMessage($tier)],
                        ]);
                    }
                }

                if ($product->manage_stock && $product->inventory < $item['quantity']) {
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

            $coupon = $this->resolveCoupon($payload['coupon_code'] ?? null, $subtotal);

            if ($coupon) {
                $this->couponEligibility->assertEligible($coupon, [
                    'source' => 'admin',
                    'phone' => $payload['phone'] ?? null,
                    'email' => $payload['email'] ?? null,
                ]);
                $this->ensureCouponPerUserLimit($coupon, $payload);
            }

            $couponDiscount = $coupon ? $this->calculateCouponDiscount($coupon, $subtotal) : 0;
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
                'normalized_phone' => $this->normalizePhoneForMatch($payload['phone']),
                'status' => $payload['status'] ?? 'processing',
                'payment_method' => $payload['payment_method'],
                'payment_status' => $payload['payment_status']
                    ?? ($payload['payment_method'] === 'cod' ? 'pending_collection' : 'authorized'),
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'coupon_code' => $coupon?->code,
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

                if ($product->manage_stock) {
                    $product->decrement('inventory', $item['quantity']);
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

                    if ($gift->manage_stock && $gift->inventory > 0) {
                        $gift->decrement('inventory');
                    }
                }
            }

            if ($coupon) {
                $coupon->increment('used_count');
            }

            $this->orderAssignmentService->assignOrder($order);

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => $order,
        ], 201);
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

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->ensureCanAccessOrder($request, $order);

        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:255'],
            'payment_status' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ]);

        $oldStatus = (string) $order->status;
        $beforeStatusType = $order->assignment_status_type
            ?: ($order->status === 'incomplete' ? 'incomplete' : 'processing');
        $order->update($payload);
        $freshOrder = $order->fresh();
        $newStatus = (string) $freshOrder->status;
        $afterStatusType = $this->assignmentStatusTypeForStatusChange(
            $newStatus,
            $beforeStatusType,
        );

        if ($beforeStatusType !== $afterStatusType) {
            $this->orderAssignmentService->keepExistingModeratorForStatus($freshOrder, $afterStatusType)
                ?? $this->orderAssignmentService->assignOrderByStatus($freshOrder, $afterStatusType);
        }

        if (array_key_exists('status', $payload)) {
            $this->customerNotificationService->notifyOrderStatusChanged($freshOrder, $oldStatus, $newStatus);
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
        $previousModeratorId = $order->assignments()
            ->whereNull('order_item_id')
            ->where('status', 'assigned')
            ->latest('id')
            ->value('moderator_id');

        $assignment = $this->orderAssignmentService->reassignOrder(
            $order->id,
            (int) $payload['moderator_id'],
            $request->user()?->id,
            $payload['note'] ?? null,
        );

        $this->auditLogger->log(
            $request,
            'order.reassigned',
            "Reassigned order {$order->order_number}.",
            $order,
            [
                'previous_moderator_id' => $previousModeratorId ? (int) $previousModeratorId : null,
                'new_moderator_id' => (int) $payload['moderator_id'],
                'note' => $payload['note'] ?? null,
            ],
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
        $orders = Order::query()->whereIn('id', $payload['order_ids'])->get()->keyBy('id');
        $previousModeratorIds = $orders->mapWithKeys(fn (Order $order): array => [
            $order->id => $order->assignments()
                ->whereNull('order_item_id')
                ->where('status', 'assigned')
                ->latest('id')
                ->value('moderator_id'),
        ]);

        $assignments = $this->orderAssignmentService->bulkReassignOrders(
            $payload['order_ids'],
            (int) $payload['moderator_id'],
            $request->user()?->id,
            $payload['note'] ?? null,
        );

        foreach ($assignments as $assignment) {
            $order = $orders->get($assignment->order_id);

            if (! $order) {
                continue;
            }

            $previousModeratorId = $previousModeratorIds->get($order->id);
            $this->auditLogger->log(
                $request,
                'order.reassigned',
                "Reassigned order {$order->order_number} as part of a bulk action.",
                $order,
                [
                    'bulk' => true,
                    'previous_moderator_id' => $previousModeratorId ? (int) $previousModeratorId : null,
                    'new_moderator_id' => (int) $payload['moderator_id'],
                    'note' => $payload['note'] ?? null,
                ],
            );
        }

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

    protected function resolveCoupon(?string $couponCode, float $subtotal): ?Coupon
    {
        if (! $couponCode) {
            return null;
        }

        $coupon = Coupon::where('code', strtoupper($couponCode))
            ->where('is_active', true)
            ->first();

        if (! $coupon || $subtotal < (float) $coupon->minimum_order_amount) {
            return null;
        }

        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return null;
        }

        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            return null;
        }

        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
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

    protected function ensureCouponPerUserLimit(Coupon $coupon, array $payload): void
    {
        $limit = (int) ($coupon->per_user_limit ?? 0);

        if ($limit < 1) {
            return;
        }

        $normalizedPhone = $this->normalizePhoneForMatch((string) ($payload['phone'] ?? ''));
        $normalizedEmail = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($normalizedPhone === '' && $normalizedEmail === '') {
            return;
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

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'coupon_code' => ["This coupon can only be used {$limit} time(s) per customer."],
            ]);
        }
    }

    protected function normalizePhoneForMatch(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }

        return $digits;
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

    protected function percentage(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }

    protected function assignmentStatusTypeForStatusChange(string $status, string $currentStatusType): string
    {
        if ($status === 'incomplete') {
            return 'incomplete';
        }

        if ($status === 'processing') {
            return 'processing';
        }

        if (in_array($status, ['confirmed', 'shipped', 'delivered', 'cancelled'], true)) {
            return $currentStatusType === 'incomplete' ? 'incomplete' : 'processing';
        }

        return 'processing';
    }

    protected function applyTimeFilter($query, string $time): void
    {
        $now = Carbon::now($this->orderTimezone());

        [$start, $end] = match ($time) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            default => [null, null],
        };

        if (! $start || ! $end) {
            return;
        }

        $query->whereBetween(DB::raw('COALESCE(placed_at, completed_at, last_activity_at, created_at)'), [
            $this->toDatabaseTimezone($start),
            $this->toDatabaseTimezone($end),
        ]);
    }

    protected function applyCampaignTrackingFilter($query, string $campaignTracking): void
    {
        if ($campaignTracking === 'none') {
            $query->whereDoesntHave('items.product', function ($productQuery): void {
                $productQuery->where(function ($trackingQuery): void {
                    $trackingQuery
                        ->whereJsonLength('campaign_facebook_pixel_ids', '>', 0)
                        ->orWhereJsonLength('campaign_google_tag_ids', '>', 0);
                });
            });

            return;
        }

        if (str_starts_with($campaignTracking, 'facebook:')) {
            $pixelId = trim(substr($campaignTracking, strlen('facebook:')));

            if ($pixelId !== '') {
                $query->whereHas('items.product', fn ($productQuery) => $productQuery
                    ->whereJsonContains('campaign_facebook_pixel_ids', $pixelId));
            }

            return;
        }

        if (str_starts_with($campaignTracking, 'google:')) {
            $tagId = trim(substr($campaignTracking, strlen('google:')));

            if ($tagId !== '') {
                $query->whereHas('items.product', fn ($productQuery) => $productQuery
                    ->whereJsonContains('campaign_google_tag_ids', $tagId));
            }
        }
    }

    protected function orderTimezone(): string
    {
        return (string) config('app.dashboard_timezone', 'Asia/Dhaka');
    }

    protected function databaseTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    protected function toDatabaseTimezone(Carbon $date): Carbon
    {
        return $date->copy()->timezone($this->databaseTimezone());
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
