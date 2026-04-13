<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'description',
        'minimum_order_amount',
        'maximum_order_amount',
        'usage_limit',
        'used_count',
        'starts_at',
        'ends_at',
        'free_shipping',
        'individual_use',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'maximum_order_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'free_shipping' => 'boolean',
            'individual_use' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
