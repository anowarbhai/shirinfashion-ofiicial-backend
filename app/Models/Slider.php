<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Slider extends Model
{
    use HasFactory;

    protected $fillable = [
        'eyebrow',
        'title',
        'subtitle',
        'image_url',
        'floating_image_url',
        'badge_text',
        'primary_button_label',
        'primary_button_url',
        'secondary_button_label',
        'secondary_button_url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => MediaUrl::toPublic($value),
            set: fn (?string $value) => MediaUrl::normalizeStored($value),
        );
    }

    protected function floatingImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => MediaUrl::toPublic($value),
            set: fn (?string $value) => MediaUrl::normalizeStored($value),
        );
    }
}
