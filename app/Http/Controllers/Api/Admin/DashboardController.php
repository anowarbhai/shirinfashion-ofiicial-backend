<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\MobileDeviceToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        [$rangeKey, $label, $startDate, $endDate] = $this->resolveRange(
            (string) $request->query('range', 'today'),
            $request->query('start_date'),
            $request->query('end_date'),
        );

        [$previousStartDate, $previousEndDate] = $this->previousRange($rangeKey, $startDate, $endDate);

        $user = $request->user();
        $ordersQuery = Order::query();
        $previousOrdersQuery = Order::query();
        $customersQuery = User::query()->where('role', 'customer');
        $previousCustomersQuery = User::query()->where('role', 'customer');
        $productsQuery = Product::query();
        $previousProductsQuery = Product::query();

        $this->excludeIncompleteOrders($ordersQuery);
        $this->excludeIncompleteOrders($previousOrdersQuery);
        $this->applyOrderVisibility($ordersQuery, $user);
        $this->applyOrderVisibility($previousOrdersQuery, $user);
        $this->applyOrderDateRange($ordersQuery, $startDate, $endDate);
        $this->applyOrderDateRange($previousOrdersQuery, $previousStartDate, $previousEndDate);
        $this->applyRange($customersQuery, 'created_at', $startDate, $endDate);
        $this->applyRange($previousCustomersQuery, 'created_at', $previousStartDate, $previousEndDate);
        $this->applyRange($productsQuery, 'created_at', $startDate, $endDate);
        $this->applyRange($previousProductsQuery, 'created_at', $previousStartDate, $previousEndDate);

        $revenue = (float) (clone $ordersQuery)->sum('grand_total');
        $previousRevenue = (float) (clone $previousOrdersQuery)->sum('grand_total');
        $ordersCount = (clone $ordersQuery)->count();
        $previousOrdersCount = (clone $previousOrdersQuery)->count();
        $customersCount = (clone $customersQuery)->count();
        $previousCustomersCount = (clone $previousCustomersQuery)->count();
        $productsCount = (clone $productsQuery)->count();
        $previousProductsCount = (clone $previousProductsQuery)->count();

        return response()->json([
            'data' => [
                'filter' => [
                    'key' => $rangeKey,
                    'label' => $label,
                    'start_date' => $startDate?->toDateString(),
                    'end_date' => $endDate?->toDateString(),
                ],
                'kpis' => [
                    [
                        'label' => 'Revenue',
                        'value' => $this->formatCurrency($revenue),
                        'delta' => $this->formatDelta($revenue, $previousRevenue),
                    ],
                    [
                        'label' => 'Orders',
                        'value' => number_format($ordersCount),
                        'delta' => $this->formatDelta($ordersCount, $previousOrdersCount),
                    ],
                    [
                        'label' => 'Customers',
                        'value' => number_format($customersCount),
                        'delta' => $this->formatDelta($customersCount, $previousCustomersCount),
                    ],
                    [
                        'label' => 'Products',
                        'value' => number_format($productsCount),
                        'delta' => $this->formatDelta($productsCount, $previousProductsCount),
                    ],
                ],
                'today_summary' => $this->buildRangeSummary($user, $startDate, $endDate),
                'recent_orders' => (clone $ordersQuery)
                    ->latest('placed_at')
                    ->take(5)
                    ->get()
                    ->map(fn (Order $order) => [
                        'id' => $order->order_number,
                        'customer' => $order->customer_name,
                        'total' => $this->formatCurrency((float) $order->grand_total),
                        'status' => str_replace('_', ' ', ucfirst($order->status)),
                    ])
                    ->values(),
                'inventory_alerts' => Product::query()
                    ->where('manage_stock', true)
                    ->where('inventory', '<=', 25)
                    ->orderBy('inventory')
                    ->take(5)
                    ->get()
                    ->map(fn (Product $product) => [
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'stock' => (int) $product->inventory,
                        'severity' => $product->inventory <= 5 ? 'Critical' : ($product->inventory <= 12 ? 'Low' : 'Monitor'),
                    ])
                    ->values(),
                'pending_reviews' => Review::query()->where('status', 'pending')->count(),
                'active_coupons' => Coupon::query()->where('is_active', true)->count(),
                'mobile_app' => $this->buildMobileAppSummary(),
                'charts' => [
                    'revenue' => $this->buildRevenueChart(
                        $startDate,
                        $endDate,
                        $previousStartDate,
                        $previousEndDate,
                        $revenue,
                        $previousRevenue,
                        $user,
                    ),
                    'orders' => $this->buildOrdersChart(
                        $startDate,
                        $endDate,
                        $previousStartDate,
                        $previousEndDate,
                        $user,
                    ),
                    'activity' => $this->buildActivityChart($startDate, $endDate, $request->user()?->id),
                    'order_sources' => $this->buildOrderSources($startDate, $endDate, $user),
                ],
                'quick_actions' => [
                    'Review pending orders',
                    'Check low stock products',
                    'Moderate customer reviews',
                    'Create a new coupon',
                ],
            ],
        ]);
    }

    /**
     * @return array{0:string,1:string,2:?Carbon,3:?Carbon}
     */
    private function resolveRange(string $rangeKey, mixed $startDate, mixed $endDate): array
    {
        $today = now($this->dashboardTimezone())->startOfDay();

        return match ($rangeKey) {
            'today' => ['today', 'Today', $today->copy(), $today->copy()->endOfDay()],
            'yesterday' => [
                'yesterday',
                'Yesterday',
                $today->copy()->subDay(),
                $today->copy()->subDay()->endOfDay(),
            ],
            'this_month' => ['this_month', 'This Month', $today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'last_month' => [
                'last_month',
                'Last Month',
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => ['this_year', 'This Year', $today->copy()->startOfYear(), $today->copy()->endOfYear()],
            'last_year' => [
                'last_year',
                'Last Year',
                $today->copy()->subYear()->startOfYear(),
                $today->copy()->subYear()->endOfYear(),
            ],
            'custom' => $this->resolveCustomRange($startDate, $endDate),
            default => ['all_time', 'All Time', null, null],
        };
    }

    /**
     * @return array{0:string,1:string,2:?Carbon,3:?Carbon}
     */
    private function resolveCustomRange(mixed $startDate, mixed $endDate): array
    {
        $start = is_string($startDate) && $startDate !== ''
            ? Carbon::parse($startDate, $this->dashboardTimezone())->startOfDay()
            : null;
        $end = is_string($endDate) && $endDate !== ''
            ? Carbon::parse($endDate, $this->dashboardTimezone())->endOfDay()
            : null;

        if (! $start || ! $end) {
            return ['all_time', 'All Time', null, null];
        }

        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return ['custom', sprintf('%s - %s', $start->format('M j, Y'), $end->format('M j, Y')), $start, $end];
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function previousRange(string $rangeKey, ?Carbon $startDate, ?Carbon $endDate): array
    {
        if (! $startDate || ! $endDate || $rangeKey === 'all_time') {
            return [null, null];
        }

        $days = max(1, $startDate->diffInDays($endDate) + 1);
        $previousEnd = $startDate->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [$previousStart, $previousEnd];
    }

    private function applyRange(Builder $query, string $column, ?Carbon $startDate, ?Carbon $endDate): void
    {
        if ($startDate) {
            $query->where($column, '>=', $this->toDatabaseTimezone($startDate));
        }

        if ($endDate) {
            $query->where($column, '<=', $this->toDatabaseTimezone($endDate));
        }
    }

    private function applyOrderDateRange(Builder $query, ?Carbon $startDate, ?Carbon $endDate): void
    {
        if (! $startDate && ! $endDate) {
            return;
        }

        $dateColumn = DB::raw('COALESCE(placed_at, created_at)');

        if ($startDate) {
            $query->where($dateColumn, '>=', $this->toDatabaseTimezone($startDate));
        }

        if ($endDate) {
            $query->where($dateColumn, '<=', $this->toDatabaseTimezone($endDate));
        }
    }

    private function excludeIncompleteOrders(Builder $query): void
    {
        $query->where('status', '!=', 'incomplete');
    }

    private function applyOrderVisibility(Builder $query, ?User $user): void
    {
        if (! $user || $user->hasAdminPermission('system.everything')) {
            return;
        }

        $moderator = $user->moderatorProfile()->first();

        if ($moderator) {
            $query->where(function (Builder $orderQuery) use ($user, $moderator): void {
                $orderQuery
                    ->where('assigned_moderator_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $assignmentQuery) => $assignmentQuery
                        ->whereNull('order_item_id')
                        ->where('moderator_id', $moderator->id));
            });

            return;
        }

        if ($user->hasAdminPermission('moderator.view_all_moderator_orders') || $user->hasAdminPermission('orders.view')) {
            return;
        }

        if ($user->hasAdminPermission('moderator.manage_moderators')) {
            $managedIds = $user->managedModerators()->pluck('id');

            if ($managedIds->isNotEmpty()) {
                $query->whereHas('assignments', fn (Builder $assignmentQuery) => $assignmentQuery->whereIn('moderator_id', $managedIds));
                return;
            }
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @return array{current:array<int,array{label:string,value:float}>,previous:array<int,array{label:string,value:float}>}
     */
    private function buildRevenueChart(
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?Carbon $previousStartDate,
        ?Carbon $previousEndDate,
        float $rangeRevenue,
        float $previousRangeRevenue,
        ?User $user,
    ): array
    {
        $chartEnd = ($endDate ?? now($this->dashboardTimezone()))->copy()->endOfDay();
        $oldestOrderDate = null;

        if (! $startDate) {
            $oldestOrderQuery = Order::query();
            $this->excludeIncompleteOrders($oldestOrderQuery);
            $this->applyOrderVisibility($oldestOrderQuery, $user);
            $oldestOrderDate = $oldestOrderQuery
                ->selectRaw('MIN(COALESCE(placed_at, created_at)) as oldest_order_date')
                ->value('oldest_order_date');
        }
        $chartStart = ($startDate
            ?? ($oldestOrderDate ? Carbon::parse($oldestOrderDate, $this->databaseTimezone())->timezone($this->dashboardTimezone()) : $chartEnd->copy()->subDays(29)))
            ->copy()
            ->startOfDay();

        if ($chartStart->diffInDays($chartEnd) > 370) {
            $current = $this->aggregateRevenueByMonth($chartStart, $chartEnd, $user);
            $previous = $previousStartDate && $previousEndDate
                ? $this->aggregateRevenueByMonth($previousStartDate, $previousEndDate, $user)
                : [];

            return [
                'current' => $this->ensureRevenuePoints($current, $chartStart, $chartEnd, $rangeRevenue, $user),
                'previous' => $previousStartDate && $previousEndDate
                    ? $this->ensureRevenuePoints($previous, $previousStartDate, $previousEndDate, $previousRangeRevenue, $user)
                    : [],
            ];
        }

        if ($this->isSingleDayRange($chartStart, $chartEnd)) {
            $current = $this->aggregateRevenueByHour($chartStart, $chartEnd, $user);
            $previous = $previousStartDate && $previousEndDate
                ? $this->aggregateRevenueByHour($previousStartDate, $previousEndDate, $user)
                : [];

            return [
                'current' => $this->ensureRevenuePoints($current, $chartStart, $chartEnd, $rangeRevenue, $user),
                'previous' => $previousStartDate && $previousEndDate
                    ? $this->ensureRevenuePoints($previous, $previousStartDate, $previousEndDate, $previousRangeRevenue, $user)
                    : [],
            ];
        }

        $current = $this->aggregateRevenueByDay($chartStart, $chartEnd, $user);
        $previous = $previousStartDate && $previousEndDate
            ? $this->aggregateRevenueByDay($previousStartDate, $previousEndDate, $user)
            : [];

        return [
            'current' => $this->ensureRevenuePoints($current, $chartStart, $chartEnd, $rangeRevenue, $user),
            'previous' => $previousStartDate && $previousEndDate
                ? $this->ensureRevenuePoints($previous, $previousStartDate, $previousEndDate, $previousRangeRevenue, $user)
                : [],
        ];
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateRevenueByDay(Carbon $startDate, Carbon $endDate, ?User $user): array
    {
        $bucketExpression = $this->localDateExpression();

        $query = Order::query()
            ->selectRaw("{$bucketExpression} as bucket, SUM(grand_total) as total");
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $rows = $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];

        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay()) as $date) {
            $key = $date->format('Y-m-d');
            $points[] = [
                'label' => $date->format('M j'),
                'value' => round((float) ($rows[$key] ?? 0), 2),
            ];
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateRevenueByHour(Carbon $startDate, Carbon $endDate, ?User $user): array
    {
        $bucketExpression = $this->localHourExpression();

        $query = Order::query()
            ->selectRaw("{$bucketExpression} as bucket, SUM(grand_total) as total");
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $rows = $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = $startDate->copy()->startOfDay();
        $last = $endDate->copy()->startOfDay()->addHours(23);

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m-d H:00:00');
            $points[] = [
                'label' => $cursor->format('g A'),
                'value' => round((float) ($rows[$key] ?? 0), 2),
            ];
            $cursor->addHour();
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateRevenueByMonth(Carbon $startDate, Carbon $endDate, ?User $user): array
    {
        $monthExpression = $this->localMonthExpression();

        $query = Order::query()
            ->selectRaw("{$monthExpression} as bucket, SUM(grand_total) as total");
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $rows = $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = $startDate->copy()->startOfMonth();
        $last = $endDate->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');
            $points[] = [
                'label' => $cursor->format('M Y'),
                'value' => round((float) ($rows[$key] ?? 0), 2),
            ];
            $cursor->addMonth();
        }

        return $points;
    }

    /**
     * @return array{current:array<int,array{label:string,value:float}>,previous:array<int,array{label:string,value:float}>}
     */
    private function buildOrdersChart(
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?Carbon $previousStartDate,
        ?Carbon $previousEndDate,
        ?User $user,
    ): array
    {
        $chartEnd = ($endDate ?? now($this->dashboardTimezone()))->copy()->endOfDay();
        $oldestOrderDate = null;

        if (! $startDate) {
            $oldestOrderQuery = Order::query();
            $this->excludeIncompleteOrders($oldestOrderQuery);
            $this->applyOrderVisibility($oldestOrderQuery, $user);
            $oldestOrderDate = $oldestOrderQuery
                ->selectRaw('MIN(COALESCE(placed_at, created_at)) as oldest_order_date')
                ->value('oldest_order_date');
        }

        $chartStart = ($startDate
            ?? ($oldestOrderDate ? Carbon::parse($oldestOrderDate, $this->databaseTimezone())->timezone($this->dashboardTimezone()) : $chartEnd->copy()->subDays(29)))
            ->copy()
            ->startOfDay();
        $aggregate = match (true) {
            $this->isSingleDayRange($chartStart, $chartEnd) => fn (Carbon $start, Carbon $end): array => $this->aggregateOrdersByDay($start, $end, $user),
            $chartStart->diffInDays($chartEnd) > 370 => fn (Carbon $start, Carbon $end): array => $this->aggregateOrdersByMonth($start, $end, $user),
            default => fn (Carbon $start, Carbon $end): array => $this->aggregateOrdersByDay($start, $end, $user),
        };

        return [
            'current' => $aggregate($chartStart, $chartEnd),
            'previous' => $previousStartDate && $previousEndDate
                ? $aggregate($previousStartDate, $previousEndDate)
                : [],
        ];
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateOrdersByDay(Carbon $startDate, Carbon $endDate, ?User $user): array
    {
        $bucketExpression = $this->localDateExpression();
        $query = Order::query()->selectRaw("{$bucketExpression} as bucket, COUNT(*) as total");
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $rows = $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');
        $points = [];

        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay()) as $date) {
            $points[] = [
                'label' => $date->format('M j'),
                'value' => (float) ($rows[$date->format('Y-m-d')] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateOrdersByHour(Carbon $startDate, Carbon $endDate, ?User $user): array
    {
        $bucketExpression = $this->localHourExpression();
        $query = Order::query()->selectRaw("{$bucketExpression} as bucket, COUNT(*) as total");
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $rows = $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = $startDate->copy()->startOfDay();
        $last = $endDate->copy()->startOfDay()->addHours(23);

        while ($cursor->lte($last)) {
            $points[] = [
                'label' => $cursor->format('g A'),
                'value' => (float) ($rows[$cursor->format('Y-m-d H:00:00')] ?? 0),
            ];
            $cursor->addHour();
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateOrdersByMonth(Carbon $startDate, Carbon $endDate, ?User $user): array
    {
        $monthExpression = $this->localMonthExpression();
        $query = Order::query()->selectRaw("{$monthExpression} as bucket, COUNT(*) as total");
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $rows = $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');
        $points = [];
        $cursor = $startDate->copy()->startOfMonth();
        $last = $endDate->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $points[] = [
                'label' => $cursor->format('M Y'),
                'value' => (float) ($rows[$cursor->format('Y-m')] ?? 0),
            ];
            $cursor->addMonth();
        }

        return $points;
    }

    /**
     * @return array{sales:string,orders:string}
     */
    private function buildRangeSummary(?User $user, ?Carbon $startDate, ?Carbon $endDate): array
    {
        $query = Order::query();
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);
        $this->applyOrderDateRange($query, $startDate, $endDate);

        return [
            'sales' => $this->formatCurrency((float) (clone $query)->sum('grand_total')),
            'orders' => number_format((clone $query)->count()),
        ];
    }

    /**
     * @return array{installs:int,enabled_devices:int,active_today:int,active_7_days:int,active_30_days:int,latest_version:string,versions:array<int,array{version:string,count:int}>}
     */
    private function buildMobileAppSummary(): array
    {
        $mobilePushSettings = app(\App\Services\AdminSettingsService::class)->getGroup('mobile_push');
        $enabledQuery = MobileDeviceToken::query()->where('enabled', true);

        $versions = (clone $enabledQuery)
            ->selectRaw("COALESCE(NULLIF(app_version, ''), 'Unknown') as version, COUNT(*) as total")
            ->groupBy('version')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'version' => (string) $row->version,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($mobilePushSettings['dashboard_widget_enabled'] ?? true),
            'installs' => $this->countDistinctMobileDevices(MobileDeviceToken::query()),
            'enabled_devices' => $this->countDistinctMobileDevices(
                MobileDeviceToken::query()->where('enabled', true),
            ),
            'active_today' => $this->countDistinctMobileDevices(
                MobileDeviceToken::query()->where('enabled', true)->where('last_seen_at', '>=', now()->subDay()),
            ),
            'active_7_days' => $this->countDistinctMobileDevices(
                MobileDeviceToken::query()->where('enabled', true)->where('last_seen_at', '>=', now()->subDays(7)),
            ),
            'active_30_days' => $this->countDistinctMobileDevices(
                MobileDeviceToken::query()->where('enabled', true)->where('last_seen_at', '>=', now()->subDays(30)),
            ),
            'latest_version' => $versions[0]['version'] ?? 'Unknown',
            'versions' => $versions,
        ];
    }

    private function countDistinctMobileDevices(Builder $query): int
    {
        return (int) $query->selectRaw("COUNT(DISTINCT COALESCE(NULLIF(device_id, ''), token)) as aggregate")->value('aggregate');
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function buildActivityChart(?Carbon $startDate, ?Carbon $endDate, ?int $actorId): array
    {
        $chartEnd = ($endDate ?? now($this->dashboardTimezone()))->copy()->endOfDay();
        $chartStart = ($startDate ?? $chartEnd->copy()->subDays(6))->copy()->startOfDay();

        if (! $actorId) {
            return $this->emptyActivityPoints($chartStart, $chartEnd);
        }

        if ($chartStart->diffInDays($chartEnd) > 370) {
            return $this->aggregateAdminActivityByMonth($chartStart, $chartEnd, $actorId);
        }

        if ($this->isSingleDayRange($chartStart, $chartEnd)) {
            return $this->aggregateAdminActivityByHour($chartStart, $chartEnd, $actorId);
        }

        return $this->aggregateAdminActivityByDay($chartStart, $chartEnd, $actorId);
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateAdminActivityByDay(Carbon $startDate, Carbon $endDate, int $actorId): array
    {
        $bucketExpression = $this->localDateExpression('created_at');
        $rows = AdminAuditLog::query()
            ->selectRaw("{$bucketExpression} as bucket, COUNT(*) as total")
            ->where('actor_id', $actorId)
            ->whereBetween('created_at', [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];

        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay()) as $date) {
            $key = $date->format('Y-m-d');
            $points[] = [
                'label' => $date->format('M j'),
                'value' => (float) ($rows[$key] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateAdminActivityByHour(Carbon $startDate, Carbon $endDate, int $actorId): array
    {
        $bucketExpression = $this->localHourExpression('created_at');
        $rows = AdminAuditLog::query()
            ->selectRaw("{$bucketExpression} as bucket, COUNT(*) as total")
            ->where('actor_id', $actorId)
            ->whereBetween('created_at', [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = $startDate->copy()->startOfDay();
        $last = $endDate->copy()->startOfDay()->addHours(23);

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m-d H:00:00');
            $points[] = [
                'label' => $cursor->format('g A'),
                'value' => (float) ($rows[$key] ?? 0),
            ];
            $cursor->addHour();
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateAdminActivityByMonth(Carbon $startDate, Carbon $endDate, int $actorId): array
    {
        $monthExpression = $this->localMonthExpression('created_at');
        $rows = AdminAuditLog::query()
            ->selectRaw("{$monthExpression} as bucket, COUNT(*) as total")
            ->where('actor_id', $actorId)
            ->whereBetween('created_at', [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = $startDate->copy()->startOfMonth();
        $last = $endDate->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');
            $points[] = [
                'label' => $cursor->format('M Y'),
                'value' => (float) ($rows[$key] ?? 0),
            ];
            $cursor->addMonth();
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function emptyActivityPoints(Carbon $startDate, Carbon $endDate): array
    {
        if ($this->isSingleDayRange($startDate, $endDate)) {
            $points = [];
            $cursor = $startDate->copy()->startOfDay();
            $last = $endDate->copy()->startOfDay()->addHours(23);

            while ($cursor->lte($last)) {
                $points[] = [
                    'label' => $cursor->format('g A'),
                    'value' => 0.0,
                ];
                $cursor->addHour();
            }

            return $points;
        }

        $points = [];

        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay()) as $date) {
            $points[] = [
                'label' => $date->format('M j'),
                'value' => 0.0,
            ];
        }

        return $points;
    }

    private function isSingleDayRange(Carbon $startDate, Carbon $endDate): bool
    {
        return $startDate->copy()->timezone($this->dashboardTimezone())->isSameDay(
            $endDate->copy()->timezone($this->dashboardTimezone()),
        );
    }

    /**
     * @return array<int,array{label:string,value:int,percentage:float,color:string}>
     */
    private function buildOrderSources(?Carbon $startDate, ?Carbon $endDate, ?User $user): array
    {
        $query = Order::query();
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);
        $this->applyOrderDateRange($query, $startDate, $endDate);

        $total = (clone $query)->count();

        if ($total === 0) {
            return [];
        }

        $colors = [
            'Facebook' => '#1877f2',
            'Google' => '#ea4335',
            'Instagram' => '#e1306c',
            'WhatsApp' => '#22c55e',
            'YouTube' => '#ff0000',
            'TikTok' => '#111827',
            'Direct' => '#4f46e5',
        ];

        return (clone $query)
            ->selectRaw("COALESCE(NULLIF(order_source, ''), 'Direct') as source_label, COUNT(*) as total")
            ->groupBy('source_label')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row, int $index): array => [
                'label' => (string) $row->source_label,
                'value' => (int) $row->total,
                'percentage' => round(((int) $row->total / $total) * 100, 1),
                'color' => $colors[(string) $row->source_label] ?? ['#f97316', '#14b8a6', '#8b5cf6', '#0f766e'][$index % 4],
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int,array{label:string,value:float}> $points
     * @return array<int,array{label:string,value:float}>
     */
    private function ensureRevenuePoints(array $points, Carbon $startDate, Carbon $endDate, float $fallbackRevenue, ?User $user): array
    {
        $hasRevenue = collect($points)->contains(fn (array $point): bool => (float) $point['value'] > 0);

        if ($hasRevenue) {
            return $points;
        }

        $query = Order::query();
        $this->excludeIncompleteOrders($query);
        $this->applyOrderVisibility($query, $user);

        $total = (float) $query
            ->whereBetween(DB::raw('COALESCE(placed_at, created_at)'), [
                $this->toDatabaseTimezone($startDate),
                $this->toDatabaseTimezone($endDate),
            ])
            ->sum('grand_total');

        if ($total <= 0 && $fallbackRevenue > 0) {
            $total = $fallbackRevenue;
        }

        if ($total <= 0) {
            return $points;
        }

        return [
            [
                'label' => $startDate->format('M j'),
                'value' => 0,
            ],
            [
                'label' => $endDate->format('M j'),
                'value' => round($total, 2),
            ],
        ];
    }

    private function formatCurrency(float $value): string
    {
        return 'BDT '.number_format($value, 2);
    }

    private function formatDelta(float|int $current, float|int $previous): string
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? '0.0%' : '+100.0%';
        }

        $delta = (($current - $previous) / $previous) * 100;

        return sprintf('%+.1f%%', $delta);
    }

    private function dashboardTimezone(): string
    {
        return (string) config('app.dashboard_timezone', 'Asia/Dhaka');
    }

    private function databaseTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    private function toDatabaseTimezone(Carbon $date): Carbon
    {
        return $date->copy()->timezone($this->databaseTimezone());
    }

    private function localDateExpression(string $column = 'COALESCE(placed_at, created_at)'): string
    {
        return 'DATE('.$this->localDateTimeExpression($column).')';
    }

    private function localMonthExpression(string $column = 'COALESCE(placed_at, created_at)'): string
    {
        $dateTimeExpression = $this->localDateTimeExpression($column);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return "strftime('%Y-%m', {$dateTimeExpression})";
        }

        return "DATE_FORMAT({$dateTimeExpression}, '%Y-%m')";
    }

    private function localHourExpression(string $column = 'COALESCE(placed_at, created_at)'): string
    {
        $dateTimeExpression = $this->localDateTimeExpression($column);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return "strftime('%Y-%m-%d %H:00:00', {$dateTimeExpression})";
        }

        return "DATE_FORMAT({$dateTimeExpression}, '%Y-%m-%d %H:00:00')";
    }

    private function localDateTimeExpression(string $column = 'COALESCE(placed_at, created_at)'): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $column;
        }

        return sprintf(
            "CONVERT_TZ({$column}, '%s', '%s')",
            now($this->databaseTimezone())->format('P'),
            now($this->dashboardTimezone())->format('P'),
        );
    }
}
