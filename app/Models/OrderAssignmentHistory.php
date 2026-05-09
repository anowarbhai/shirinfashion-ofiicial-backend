<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAssignmentHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'previous_moderator_id',
        'new_moderator_id',
        'changed_by',
        'order_status_type',
        'change_type',
        'note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function previousModerator(): BelongsTo
    {
        return $this->belongsTo(Moderator::class, 'previous_moderator_id');
    }

    public function newModerator(): BelongsTo
    {
        return $this->belongsTo(Moderator::class, 'new_moderator_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
