<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'title',
        'attribute_values',
        'price',
        'compare_price',
        'image_url',
        'color_code',
        'inventory',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'inventory' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => MediaUrl::toPublic($value),
        );
    }
}
