<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileNotificationCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'title',
        'body',
        'type',
        'target',
        'status',
        'url',
        'product_id',
        'coupon_code',
        'sent_count',
        'failed_count',
        'notifications_created',
        'last_push_response',
        'last_sent_at',
    ];

    protected $casts = [
        'last_push_response' => 'array',
        'last_sent_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
