<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class StorefrontPage extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'status',
        'template',
        'excerpt',
        'seo_title',
        'seo_description',
        'builder_json',
    ];

    protected function builderJson(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                $decoded = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

                return HtmlSanitizer::sanitizeBuilderJson($decoded);
            },
            set: fn (mixed $value): string => json_encode(
                HtmlSanitizer::sanitizeBuilderJson(is_array($value) ? $value : []),
                JSON_UNESCAPED_SLASHES,
            ) ?: '[]',
        );
    }
}
