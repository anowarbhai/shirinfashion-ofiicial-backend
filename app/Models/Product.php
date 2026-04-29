<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'sku',
        'brand',
        'short_description',
        'description',
        'price',
        'compare_price',
        'rating',
        'review_count',
        'inventory',
        'badge',
        'skin_types',
        'gallery',
        'highlights',
        'ingredients',
        'meta_title',
        'meta_description',
        'is_active',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'rating' => 'decimal:1',
            'skin_types' => 'array',
            'highlights' => 'array',
            'ingredients' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function attributeTerms(): BelongsToMany
    {
        return $this->belongsToMany(AttributeTerm::class, 'attribute_term_product');
    }

    public function volumeDiscounts(): HasMany
    {
        return $this->hasMany(ProductVolumeDiscount::class);
    }

    public function activeVolumeDiscounts(): HasMany
    {
        return $this->volumeDiscounts()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('quantity');
    }

    protected function gallery(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                $items = is_array($value)
                    ? $value
                    : (json_decode((string) $value, true) ?: []);

                return collect($items)
                    ->map(fn ($item) => MediaUrl::toPublic(is_string($item) ? $item : null))
                    ->filter()
                    ->values()
                    ->all();
            },
            set: function (mixed $value): string {
                $items = is_array($value) ? $value : [];

                return json_encode(
                    collect($items)
                        ->map(fn ($item) => MediaUrl::normalizeStored(is_string($item) ? $item : null))
                        ->filter()
                        ->values()
                        ->all(),
                    JSON_UNESCAPED_SLASHES
                ) ?: '[]';
            },
        );
    }
}
