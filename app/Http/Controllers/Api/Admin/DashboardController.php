<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $this->applyRange($ordersQuery, 'placed_at', $startDate, $endDate);
        $this->applyRange($previousOrdersQuery, 'placed_at', $previousStartDate, $previousEndDate);
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
        $today = now()->timezone(config('app.timezone'))->startOfDay();

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
            ? Carbon::parse($startDate, config('app.timezone'))->startOfDay()
            : null;
        $end = is_string($endDate) && $endDate !== ''
            ? Carbon::parse($endDate, config('app.timezone'))->endOfDay()
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
            $query->where($column, '>=', $startDate);
        }

        if ($endDate) {
            $query->where($column, '<=', $endDate);
        }
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
}
