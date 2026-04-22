<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_token',
        'purpose',
        'user_id',
        'phone',
        'code_hash',
        'meta',
        'attempts',
        'expires_at',
        'verified_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
