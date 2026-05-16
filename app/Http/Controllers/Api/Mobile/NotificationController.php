<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\MobileNotificationCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function publicIndex(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $notifications = MobileNotificationCampaign::query()
            ->with(['category:id,name,slug', 'product:id,name,slug'])
            ->whereIn('target', ['all', 'guests'])
            ->where('status', 'sent')
            ->latest('last_sent_at')
            ->latest()
            ->paginate($perPage);

        $items = collect($notifications->items())
            ->map(fn (MobileNotificationCampaign $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'data' => [
                    'url' => $notification->url,
                    'coupon_code' => $notification->coupon_code,
                    'category_id' => $notification->category_id,
                    'category' => $notification->category?->only(['id', 'name', 'slug']),
                    'product_id' => $notification->product_id,
                    'product_slug' => $notification->product?->slug,
                    'product_title' => $notification->product?->name,
                    'product_image' => $notification->product?->gallery[0] ?? null,
                    'product' => $notification->product ? [
                        'id' => $notification->product->id,
                        'name' => $notification->product->name,
                        'slug' => $notification->product->slug,
                        'thumbnail' => $notification->product->gallery[0] ?? null,
                    ] : null,
                ],
                'read_at' => null,
                'sent_at' => $notification->last_sent_at,
                'created_at' => $notification->created_at,
            ])
            ->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread_count' => $notifications->total(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $notifications = CustomerNotification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread_count' => $this->unreadQuery($request)->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'count' => $this->unreadQuery($request)->count(),
            ],
        ]);
    }

    public function markRead(Request $request, CustomerNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $notification->fresh(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->unreadQuery($request)->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    private function unreadQuery(Request $request)
    {
        return CustomerNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at');
    }
}
