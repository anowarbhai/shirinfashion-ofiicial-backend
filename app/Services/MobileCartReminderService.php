<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\MobileCartSnapshot;
use App\Models\MobileDeviceToken;
use App\Models\Product;
use App\Models\ProductVolumeDiscount;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MobileCartReminderService
{
    public function __construct(private readonly MobilePushService $push)
    {
    }

    public function sync(?int $userId, ?string $deviceId, array $items): ?MobileCartSnapshot
    {
        $normalizedDeviceId = $this->normalizeDeviceId($deviceId);
        $prepared = $this->prepareItems($items);

        if (! $userId && ! $normalizedDeviceId) {
            return null;
        }

        if ($prepared['item_count'] <= 0) {
            $this->clear($userId, $normalizedDeviceId);

            return null;
        }

        $lookup = $this->lookup($userId, $normalizedDeviceId);

        return MobileCartSnapshot::query()->updateOrCreate($lookup, [
            'user_id' => $userId,
            'device_id' => $normalizedDeviceId,
            'cart_hash' => $prepared['cart_hash'],
            'items' => $prepared['items'],
            'item_count' => $prepared['item_count'],
            'subtotal' => $prepared['subtotal'],
            'synced_at' => now(),
            'last_reminded_at' => null,
            'reminder_count' => 0,
        ]);
    }

    public function clear(?int $userId, ?string $deviceId): int
    {
        $query = MobileCartSnapshot::query();
        $normalizedDeviceId = $this->normalizeDeviceId($deviceId);

        if ($userId && $normalizedDeviceId) {
            $query->where(function ($scope) use ($userId, $normalizedDeviceId): void {
                $scope
                    ->where('user_id', $userId)
                    ->orWhere('device_id', $normalizedDeviceId);
            });
        } elseif ($userId) {
            $query->where('user_id', $userId);
        } elseif ($normalizedDeviceId) {
            $query->where('device_id', $normalizedDeviceId);
        } else {
            return 0;
        }

        return $query->delete();
    }

    public function sendDueReminders(int $delayMinutes = 120, int $repeatHours = 24, int $maxReminders = 2): array
    {
        $cutoff = now()->subMinutes(max(1, $delayMinutes));
        $repeatCutoff = now()->subHours(max(1, $repeatHours));
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        MobileCartSnapshot::query()
            ->where('item_count', '>', 0)
            ->where('synced_at', '<=', $cutoff)
            ->where('reminder_count', '<', max(1, $maxReminders))
            ->where(function ($query) use ($repeatCutoff): void {
                $query
                    ->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', $repeatCutoff);
            })
            ->orderBy('synced_at')
            ->chunkById(100, function ($snapshots) use (&$processed, &$sent, &$failed, &$skipped): void {
                foreach ($snapshots as $snapshot) {
                    $processed++;
                    $result = $this->sendReminder($snapshot);

                    if ($result['sent'] > 0) {
                        $sent += $result['sent'];
                    } elseif ($result['failed'] > 0) {
                        $failed += $result['failed'];
                    } else {
                        $skipped++;
                    }
                }
            });

        return compact('processed', 'sent', 'failed', 'skipped');
    }

    private function sendReminder(MobileCartSnapshot $snapshot): array
    {
        $tokens = $this->tokensForSnapshot($snapshot);

        if ($tokens->isEmpty()) {
            $snapshot->delete();

            return ['sent' => 0, 'failed' => 0];
        }

        $title = 'Your cart is waiting';
        $body = $snapshot->item_count === 1
            ? 'You left 1 item in your Shirin Fashion cart.'
            : "You left {$snapshot->item_count} items in your Shirin Fashion cart.";
        $data = [
            'type' => 'abandoned_cart',
            'url' => '/cart',
            'item_count' => $snapshot->item_count,
            'subtotal' => (string) $snapshot->subtotal,
        ];

        $result = $this->push->sendToTokens($tokens->all(), $title, $body, $data);

        if (($result['sent'] ?? 0) > 0) {
            $snapshot->forceFill([
                'last_reminded_at' => now(),
                'reminder_count' => $snapshot->reminder_count + 1,
            ])->save();

            if ($snapshot->user_id) {
                CustomerNotification::query()->create([
                    'user_id' => $snapshot->user_id,
                    'type' => 'abandoned_cart',
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'sent_at' => now(),
                ]);
            }
        }

        return [
            'sent' => (int) ($result['sent'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
        ];
    }

    private function tokensForSnapshot(MobileCartSnapshot $snapshot): Collection
    {
        return MobileDeviceToken::query()
            ->where('enabled', true)
            ->where(function ($query) use ($snapshot): void {
                if ($snapshot->user_id) {
                    $query->orWhere('user_id', $snapshot->user_id);
                }

                if ($snapshot->device_id) {
                    $query->orWhere('device_id', $snapshot->device_id);
                }
            })
            ->pluck('token')
            ->filter()
            ->unique()
            ->values();
    }

    private function prepareItems(array $items): array
    {
        $input = collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'volume_discount_id' => isset($item['volume_discount_id'])
                    ? (int) $item['volume_discount_id']
                    : null,
            ])
            ->filter(fn (array $item): bool => $item['product_id'] > 0)
            ->values();

        if ($input->isEmpty()) {
            return ['items' => [], 'item_count' => 0, 'subtotal' => 0, 'cart_hash' => hash('sha256', 'empty')];
        }

        $products = Product::query()
            ->whereIn('id', $input->pluck('product_id')->all())
            ->get()
            ->keyBy('id');
        $tiers = ProductVolumeDiscount::query()
            ->whereIn('id', $input->pluck('volume_discount_id')->filter()->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $subtotal = 0.0;
        $prepared = [];

        foreach ($input as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                continue;
            }

            $tier = $item['volume_discount_id'] ? $tiers->get($item['volume_discount_id']) : null;
            $quantity = $item['quantity'];
            $lineTotal = $tier && (int) $tier->product_id === (int) $product->id
                ? $this->volumeDiscountLineTotal($tier, $quantity)
                : ((float) $product->price * $quantity);
            $subtotal += $lineTotal;
            $prepared[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'line_total' => round($lineTotal, 2),
                'volume_discount_id' => $tier?->id,
            ];
        }

        return [
            'items' => $prepared,
            'item_count' => collect($prepared)->sum('quantity'),
            'subtotal' => round($subtotal, 2),
            'cart_hash' => hash('sha256', json_encode($prepared) ?: Str::random(16)),
        ];
    }

    private function volumeDiscountLineTotal(ProductVolumeDiscount $tier, int $quantity): float
    {
        $baseQuantity = max(1, (int) $tier->quantity);
        $extraQuantity = max(0, $quantity - $baseQuantity);

        return (float) $tier->flat_price + ($extraQuantity * (float) ($tier->extra_unit_price ?? 0));
    }

    private function lookup(?int $userId, ?string $deviceId): array
    {
        if ($userId) {
            return ['user_id' => $userId];
        }

        return ['device_id' => $deviceId];
    }

    private function normalizeDeviceId(?string $deviceId): ?string
    {
        $value = trim((string) $deviceId);

        return $value === '' ? null : $value;
    }
}
