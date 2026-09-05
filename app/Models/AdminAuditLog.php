<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_name',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'subject_name',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
        'device',
        'location',
        'created_at',
    ];

    protected $appends = [
        'device_display',
        'location_display',
    ];

    public function getDeviceDisplayAttribute(): ?string
    {
        return $this->device ?? ($this->metadata['device'] ?? null);
    }

    public function getLocationDisplayAttribute(): ?string
    {
        return $this->location ?? ($this->metadata['location'] ?? null);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
