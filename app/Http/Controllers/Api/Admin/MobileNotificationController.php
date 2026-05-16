<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\MobileDeviceToken;
use App\Models\MobileNotificationCampaign;
use App\Models\User;
use App\Services\MobilePushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campaigns = MobileNotificationCampaign::query()
            ->with(['category:id,name,slug', 'product:id,name,slug'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->latest()
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = MobileNotificationCampaign::query()->create($this->validated($request));

        return response()->json([
            'message' => 'Mobile app notification created successfully.',
            'data' => $campaign->load(['category:id,name,slug', 'product:id,name,slug']),
        ], 201);
    }

    public function show(MobileNotificationCampaign $mobileNotification): JsonResponse
    {
        return response()->json([
            'data' => $mobileNotification->load(['category:id,name,slug', 'product:id,name,slug']),
        ]);
    }

    public function update(Request $request, MobileNotificationCampaign $mobileNotification): JsonResponse
    {
        $mobileNotification->update($this->validated($request));

        return response()->json([
            'message' => 'Mobile app notification updated successfully.',
            'data' => $mobileNotification->fresh()->load(['category:id,name,slug', 'product:id,name,slug']),
        ]);
    }

    public function destroy(MobileNotificationCampaign $mobileNotification): JsonResponse
    {
        $mobileNotification->delete();

        return response()->json([
            'message' => 'Mobile app notification deleted successfully.',
        ]);
    }

    public function sendCampaign(MobileNotificationCampaign $mobileNotification, MobilePushService $push): JsonResponse
    {
        $result = $this->deliver([
            'title' => $mobileNotification->title,
            'body' => $mobileNotification->body,
            'target' => $mobileNotification->target,
            'type' => $mobileNotification->type,
            'url' => $mobileNotification->url,
            'product_id' => $mobileNotification->product_id,
            'category_id' => $mobileNotification->category_id,
            'coupon_code' => $mobileNotification->coupon_code,
        ], $push);

        $pushResult = $result['push'];
        $mobileNotification->update([
            'status' => ($pushResult['sent'] ?? 0) > 0 ? 'sent' : 'draft',
            'sent_count' => (int) ($pushResult['sent'] ?? 0),
            'failed_count' => (int) ($pushResult['failed'] ?? 0),
            'notifications_created' => $result['notifications_created'],
            'last_push_response' => $pushResult,
            'last_sent_at' => now(),
        ]);

        return response()->json([
            'message' => $pushResult['skipped'] ?? false
                ? $pushResult['message']
                : 'Mobile app notification sent.',
            'data' => [
                'notification' => $mobileNotification->fresh()->load(['category:id,name,slug', 'product:id,name,slug']),
                'target' => $mobileNotification->target,
                'notifications_created' => $result['notifications_created'],
                'push' => $pushResult,
            ],
        ]);
    }

    public function send(Request $request, MobilePushService $push): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'target' => ['nullable', 'string', 'in:all,guests,customers'],
            'type' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2048'],
            'product_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $this->deliver($payload, $push);

        return response()->json([
            'message' => $result['push']['skipped'] ?? false
                ? $result['push']['message']
                : 'Mobile notification sent.',
            'data' => $result,
        ]);
    }

    private function deliver(array $payload, MobilePushService $push): array
    {
        $target = $payload['target'] ?? 'all';
        $type = $payload['type'] ?? 'campaign';
        $data = [
            'type' => $type,
            'url' => $payload['url'] ?? null,
            'product_id' => $payload['product_id'] ?? null,
            'category_id' => $payload['category_id'] ?? null,
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

        return [
            'target' => $target,
            'notifications_created' => $notificationRows,
            'push' => $pushResult,
        ];
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'type' => ['required', 'string', 'max:64', Rule::in(['offer', 'general', 'order', 'update', 'campaign'])],
            'target' => ['required', 'string', Rule::in(['all', 'guests', 'customers'])],
            'status' => ['nullable', 'string', Rule::in(['draft', 'active', 'paused', 'sent'])],
            'url' => ['nullable', 'string', 'max:2048'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'coupon_code' => ['nullable', 'string', 'max:120'],
        ]);

        $validated['status'] = $validated['status'] ?? 'draft';

        return $validated;
    }
}
