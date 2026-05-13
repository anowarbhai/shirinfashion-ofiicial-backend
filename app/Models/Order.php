<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'assigned_moderator_id',
        'customer_name',
        'email',
        'phone',
        'normalized_phone',
        'client_ip',
        'device_id',
        'cart_session_id',
        'cart_hash',
        'order_source',
        'order_source_detail',
        'referrer_url',
        'utm_source',
        'status',
        'assignment_status',
        'assignment_type',
        'assignment_status_type',
        'payment_method',
        'payment_status',
        'subtotal',
        'discount_total',
        'coupon_code',
        'shipping_total',
        'grand_total',
        'shipping_address',
        'normalized_address_hash',
        'fraud_check',
        'tracking_number',
        'placed_at',
        'last_activity_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'shipping_address' => 'array',
            'fraud_check' => 'array',
            'placed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function assignedModerator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_moderator_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    public function currentAssignment(): HasMany
    {
        return $this->hasMany(OrderAssignment::class)->whereNull('order_item_id')->latest();
    }
}
