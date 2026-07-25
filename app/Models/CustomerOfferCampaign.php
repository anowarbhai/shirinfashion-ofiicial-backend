<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOfferCampaign extends Model
{
    protected $fillable = [
        'created_by',
        'channel',
        'audience',
        'only_marketing_opt_in',
        'subject',
        'email_template',
        'message',
        'email_message',
        'email_html',
        'sms_message',
        'status',
        'matched_customers',
        'processed_customers',
        'email_sent',
        'email_failed',
        'sms_sent',
        'sms_failed',
        'skipped',
        'last_error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'only_marketing_opt_in' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
