<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationEvent extends Model
{
    use HasFactory;

    protected $table = 'logistics_notification_events';

    protected $fillable = [
        'event_key',
        'type',
        'payload',
        'status',
        'attempts',
        'last_error',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_retry_at' => 'datetime',
    ];
}
