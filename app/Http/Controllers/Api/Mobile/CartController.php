<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\MobileCartReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly MobileCartReminderService $cartReminder)
    {
    }

    public function sync(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['nullable', 'string', 'max:128'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.volume_discount_id' => ['nullable', 'integer'],
        ]);

        $snapshot = $this->cartReminder->sync(
            $request->user()?->id,
            $payload['device_id'] ?? null,
            $payload['items'],
        );

        return response()->json([
            'message' => $snapshot ? 'Cart reminder synced.' : 'Cart reminder cleared.',
            'data' => $snapshot,
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        $deleted = $this->cartReminder->clear($request->user()?->id, $payload['device_id'] ?? null);

        return response()->json([
            'message' => 'Cart reminder cleared.',
            'data' => ['deleted' => $deleted],
        ]);
    }
}
