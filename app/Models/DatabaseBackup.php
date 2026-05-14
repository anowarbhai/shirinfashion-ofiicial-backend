<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'disk',
        'path',
        'size',
        'status',
        'type',
        'download_token',
        'download_token_expires_at',
        'error_message',
        'created_by',
        'restored_by',
        'completed_at',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'download_token_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
