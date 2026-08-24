<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\MetaConversionsApiService;
use Illuminate\Console\Command;

class RetryMetaPurchaseEvents extends Command
{
    protected $signature = 'meta:retry-purchases';

    protected $description = 'Retry recent Meta Purchase events that were not delivered successfully';

    public function handle(MetaConversionsApiService $metaConversionsApi): int
    {
        $processed = 0;

        Order::query()
            ->whereNull('meta_purchase_sent_at')
            ->whereNotNull('meta_user_agent')
            ->whereNotNull('placed_at')
            ->where('placed_at', '>=', now()->subHours(47))
            ->whereNotIn('status', ['pending', 'incomplete', 'cancelled', 'refunded'])
            ->where('meta_purchase_attempts', '<', 8)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($metaConversionsApi, &$processed): void {
                foreach ($orders as $order) {
                    if (! $this->isDue($order)) {
                        continue;
                    }

                    $metaConversionsApi->sendPurchase($order);
                    $processed++;
                }
            });

        $this->info("Processed {$processed} eligible Meta Purchase event(s).");

        return self::SUCCESS;
    }

    private function isDue(Order $order): bool
    {
        if (! $order->meta_purchase_last_attempt_at) {
            return true;
        }

        $delaysInMinutes = [0, 5, 15, 60, 180, 360, 720, 1440];
        $attempts = min((int) $order->meta_purchase_attempts, count($delaysInMinutes) - 1);

        return $order->meta_purchase_last_attempt_at->lte(now()->subMinutes($delaysInMinutes[$attempts]));
    }
}
