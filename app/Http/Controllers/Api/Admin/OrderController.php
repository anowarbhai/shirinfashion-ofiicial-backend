<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Order::with('items')->latest()->paginate(20),
        ]);
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
}
