<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class FleetNotificationDispatch extends Model
{
    protected $table = 'logistics_fleet_notification_dispatches';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'channel',
        'period_key',
    ];
}
