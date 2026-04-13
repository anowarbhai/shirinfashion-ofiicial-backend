<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $table = 'product_attributes';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(AttributeTerm::class, 'attribute_id');
    }
}
