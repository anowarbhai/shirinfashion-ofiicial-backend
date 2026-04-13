<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'kpis' => [
                    'revenue' => (float) Order::sum('grand_total'),
                    'orders' => Order::count(),
                    'customers' => User::where('role', 'customer')->count(),
                    'products' => Product::count(),
                ],
                'recent_orders' => Order::query()->latest()->take(5)->get(),
                'low_stock' => Product::query()->where('inventory', '<=', 25)->get(),
                'pending_reviews' => Review::query()->where('status', 'pending')->count(),
                'active_coupons' => Coupon::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
