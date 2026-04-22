<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_name',
        'alt_text',
        'url',
        'disk',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => MediaUrl::toPublic($value),
            set: fn (?string $value) => MediaUrl::normalizeStored($value),
        );
    }
}
