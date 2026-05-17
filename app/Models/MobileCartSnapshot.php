<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileCartSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'cart_hash',
        'items',
        'item_count',
        'subtotal',
        'synced_at',
        'last_reminded_at',
        'reminder_count',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'item_count' => 'integer',
            'subtotal' => 'decimal:2',
            'synced_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
