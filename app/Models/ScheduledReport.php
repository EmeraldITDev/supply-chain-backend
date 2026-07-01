<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledReport extends Model
{
    protected $table = 'scm_scheduled_reports';

    protected $fillable = [
        'name',
        'report_type',
        'format',
        'frequency',
        'filters',
        'recipient_user_ids',
        'is_active',
        'next_run_at',
        'last_run_at',
        'created_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'recipient_user_ids' => 'array',
        'is_active' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
