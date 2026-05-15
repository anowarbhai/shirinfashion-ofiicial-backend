<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\User;

class CustomerNotificationService
{
    public function sendToUser(
        User|int $user,
        string $title,
        string $body,
        string $type = 'general',
        array $data = [],
    ): ?CustomerNotification {
        $userId = $user instanceof User ? $user->id : $user;

        if ($userId <= 0) {
            return null;
        }

        return CustomerNotification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'sent_at' => now(),
        ]);
    }

    public function notifyOrderStatusChanged(Order $order, string $oldStatus, string $newStatus): ?CustomerNotification
    {
        if (! $order->user_id || $oldStatus === $newStatus) {
            return null;
        }

        $orderNumber = $order->order_number ?: '#'.$order->id;
        $label = $this->statusLabel($newStatus);

        return $this->sendToUser(
            (int) $order->user_id,
            'Order status updated',
            "Your order {$orderNumber} is now {$label}.",
            'order_status',
            [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        );
    }

    private function statusLabel(string $status): string
    {
        return str($status)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }
}
