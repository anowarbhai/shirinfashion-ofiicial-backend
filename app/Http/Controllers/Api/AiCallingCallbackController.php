<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AiOrderCallingService;
use App\Services\CustomerNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCallingCallbackController extends Controller
{
    public function __construct(
        private readonly AiOrderCallingService $calling,
        private readonly CustomerNotificationService $notifications,
    ) {
    }

    public function orderConfirmation(Request $request, Order $order): JsonResponse
    {
        $token = (string) $request->query('token', $request->input('token', ''));

        if (! hash_equals($this->calling->callbackToken($order), $token)) {
            return response()->json(['message' => 'Invalid callback token.'], 403);
        }

        $oldStatus = (string) $order->status;
        $status = (string) ($request->input('status') ?: $request->input('key') ?: $request->input('digit') ?: '');
        $newStatus = $this->calling->applyCallback($order, $status, $request->all());
        $freshOrder = $order->fresh();

        if ($freshOrder && $freshOrder->user_id && $oldStatus !== $newStatus) {
            $this->notifications->notifyOrderStatusChanged($freshOrder, $oldStatus, $newStatus);
        }

        return response()->json([
            'message' => 'AI call callback processed.',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        ]);
    }
}
