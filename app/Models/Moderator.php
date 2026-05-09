<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Moderator extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'digital_marketer_id',
        'status',
        'assignment_order',
    ];

    protected function casts(): array
    {
        return [
            'assignment_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function digitalMarketer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'digital_marketer_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    public function productAssignments(): HasMany
    {
        return $this->hasMany(ProductModeratorAssignment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->whereHas('user', fn ($userQuery) => $userQuery->where('status', 'active'));
    }
}
