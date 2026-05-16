<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\MobileDeviceToken;
use App\Models\User;
use App\Services\MobilePushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    public function send(Request $request, MobilePushService $push): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'target' => ['nullable', 'string', 'in:all,guests,customers'],
            'type' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2048'],
            'product_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:120'],
        ]);

        $target = $payload['target'] ?? 'all';
        $type = $payload['type'] ?? 'campaign';
        $data = [
            'type' => $type,
            'url' => $payload['url'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'coupon_code' => $payload['coupon_code'] ?? null,
        ];

        $notificationRows = 0;
        if ($target !== 'guests') {
            User::query()
                ->whereIn('id', MobileDeviceToken::query()
                    ->whereNotNull('user_id')
                    ->where('enabled', true)
                    ->select('user_id'))
                ->select('id')
                ->chunkById(200, function ($users) use ($payload, $type, $data, &$notificationRows): void {
                    foreach ($users as $user) {
                        CustomerNotification::query()->create([
                            'user_id' => $user->id,
                            'type' => $type,
                            'title' => $payload['title'],
                            'body' => $payload['body'],
                            'data' => $data,
                            'sent_at' => now(),
                        ]);
                        $notificationRows++;
                    }
                });
        }

        $pushResult = $push->sendCampaign(
            $payload['title'],
            $payload['body'],
            $target,
            $data,
        );

        return response()->json([
            'message' => $pushResult['skipped'] ?? false
                ? $pushResult['message']
                : 'Mobile notification sent.',
            'data' => [
                'target' => $target,
                'notifications_created' => $notificationRows,
                'push' => $pushResult,
            ],
        ]);
    }
}
