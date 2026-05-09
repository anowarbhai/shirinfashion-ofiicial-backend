<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'volume_discount_id',
        'product_name',
        'sku',
        'product_image',
        'price',
        'quantity',
        'line_total',
        'is_free_gift',
    ];

    protected $appends = [
        'image',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'is_free_gift' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => MediaUrl::toPublic($this->product_image)
        );
    }

    public function volumeDiscount(): BelongsTo
    {
        return $this->belongsTo(ProductVolumeDiscount::class);
    }

    public function assignments()
    {
        return $this->hasMany(OrderAssignment::class);
    }
}
