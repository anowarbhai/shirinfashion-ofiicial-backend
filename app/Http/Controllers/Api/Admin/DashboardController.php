<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
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
            (string) $request->query('range', 'all_time'),
            $request->query('start_date'),
            $request->query('end_date'),
        );

        [$previousStartDate, $previousEndDate] = $this->previousRange($rangeKey, $startDate, $endDate);

        $ordersQuery = Order::query();
        $previousOrdersQuery = Order::query();
        $customersQuery = User::query()->where('role', 'customer');
        $previousCustomersQuery = User::query()->where('role', 'customer');
        $productsQuery = Product::query();
        $previousProductsQuery = Product::query();

        $this->excludeIncompleteOrders($ordersQuery);
        $this->excludeIncompleteOrders($previousOrdersQuery);
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
                'today_summary' => $this->buildTodaySummary(),
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
                'charts' => [
                    'revenue' => $this->buildRevenueChart(
                        $startDate,
                        $endDate,
                        $previousStartDate,
                        $previousEndDate,
                        $revenue,
                        $previousRevenue,
                    ),
                    'activity' => $this->buildActivityChart($startDate, $endDate, $request->user()?->id),
                    'order_sources' => $this->buildOrderSources($startDate, $endDate),
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
    ): array
    {
        $chartEnd = ($endDate ?? now($this->dashboardTimezone()))->copy()->endOfDay();
        $oldestOrderDate = ! $startDate
            ? Order::query()
                ->where('status', '!=', 'incomplete')
                ->selectRaw('MIN(COALESCE(placed_at, created_at)) as oldest_order_date')
                ->value('oldest_order_date')
            : null;
        $chartStart = ($startDate
            ?? ($oldestOrderDate ? Carbon::parse($oldestOrderDate, $this->databaseTimezone())->timezone($this->dashboardTimezone()) : $chartEnd->copy()->subDays(29)))
            ->copy()
            ->startOfDay();

        if ($chartStart->diffInDays($chartEnd) > 370) {
            $current = $this->aggregateRevenueByMonth($chartStart, $chartEnd);
            $previous = $previousStartDate && $previousEndDate
                ? $this->aggregateRevenueByMonth($previousStartDate, $previousEndDate)
                : [];

            return [
                'current' => $this->ensureRevenuePoints($current, $chartStart, $chartEnd, $rangeRevenue),
                'previous' => $previousStartDate && $previousEndDate
                    ? $this->ensureRevenuePoints($previous, $previousStartDate, $previousEndDate, $previousRangeRevenue)
                    : [],
            ];
        }

        $current = $this->aggregateRevenueByDay($chartStart, $chartEnd);
        $previous = $previousStartDate && $previousEndDate
            ? $this->aggregateRevenueByDay($previousStartDate, $previousEndDate)
            : [];

        return [
            'current' => $this->ensureRevenuePoints($current, $chartStart, $chartEnd, $rangeRevenue),
            'previous' => $previousStartDate && $previousEndDate
                ? $this->ensureRevenuePoints($previous, $previousStartDate, $previousEndDate, $previousRangeRevenue)
                : [],
        ];
    }

    /**
     * @return array<int,array{label:string,value:float}>
     */
    private function aggregateRevenueByDay(Carbon $startDate, Carbon $endDate): array
    {
        $bucketExpression = $this->localDateExpression();

        $rows = Order::query()
            ->selectRaw("{$bucketExpression} as bucket, SUM(grand_total) as total")
            ->where('status', '!=', 'incomplete')
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
    private function aggregateRevenueByMonth(Carbon $startDate, Carbon $endDate): array
    {
        $dateTimeExpression = $this->localDateTimeExpression();

        $rows = Order::query()
            ->selectRaw("DATE_FORMAT({$dateTimeExpression}, '%Y-%m') as bucket, SUM(grand_total) as total")
            ->where('status', '!=', 'incomplete')
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
     * @return array{sales:string,orders:string}
     */
    private function buildTodaySummary(): array
    {
        $today = now($this->dashboardTimezone())->startOfDay();
        $query = Order::query();
        $this->excludeIncompleteOrders($query);
        $this->applyOrderDateRange($query, $today->copy(), $today->copy()->endOfDay());

        return [
            'sales' => $this->formatCurrency((float) (clone $query)->sum('grand_total')),
            'orders' => number_format((clone $query)->count()),
        ];
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
    private function aggregateAdminActivityByMonth(Carbon $startDate, Carbon $endDate, int $actorId): array
    {
        $dateTimeExpression = $this->localDateTimeExpression('created_at');
        $rows = AdminAuditLog::query()
            ->selectRaw("DATE_FORMAT({$dateTimeExpression}, '%Y-%m') as bucket, COUNT(*) as total")
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
        $points = [];

        foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay()) as $date) {
            $points[] = [
                'label' => $date->format('M j'),
                'value' => 0.0,
            ];
        }

        return $points;
    }

    /**
     * @return array<int,array{label:string,value:int,percentage:float,color:string}>
     */
    private function buildOrderSources(?Carbon $startDate, ?Carbon $endDate): array
    {
        $query = Order::query();
        $this->excludeIncompleteOrders($query);
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
    private function ensureRevenuePoints(array $points, Carbon $startDate, Carbon $endDate, float $fallbackRevenue): array
    {
        $hasRevenue = collect($points)->contains(fn (array $point): bool => (float) $point['value'] > 0);

        if ($hasRevenue) {
            return $points;
        }

        $total = (float) Order::query()
            ->where('status', '!=', 'incomplete')
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

    private function localDateTimeExpression(string $column = 'COALESCE(placed_at, created_at)'): string
    {
        return sprintf(
            "CONVERT_TZ({$column}, '%s', '%s')",
            now($this->databaseTimezone())->format('P'),
            now($this->dashboardTimezone())->format('P'),
        );
    }
}
