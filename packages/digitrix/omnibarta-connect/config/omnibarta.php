<?php

return [
    'webhook_url' => env('OMNIBARTA_WEBHOOK_URL'),
    'webhook_secret' => env('OMNIBARTA_WEBHOOK_SECRET'),
    'order_model' => env('OMNIBARTA_ORDER_MODEL', App\Models\Order::class),
    'order_status_column' => env('OMNIBARTA_ORDER_STATUS_COLUMN', 'status'),
    'status_map' => [
        'completed' => env('OMNIBARTA_COMPLETED_STATUS', 'processing'),
        'cancelled' => env('OMNIBARTA_CANCELLED_STATUS', 'cancelled'),
        'on-hold' => env('OMNIBARTA_ON_HOLD_STATUS', 'pending'),
    ],
];
