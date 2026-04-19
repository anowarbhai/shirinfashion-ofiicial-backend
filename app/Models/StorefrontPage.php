<?php

namespace App\Models;

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

    protected $casts = [
        'builder_json' => 'array',
    ];
}
