<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentCounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_status_type',
        'scope_type',
        'scope_id',
        'last_moderator_id',
    ];

    public function lastModerator(): BelongsTo
    {
        return $this->belongsTo(Moderator::class, 'last_moderator_id');
    }
}
