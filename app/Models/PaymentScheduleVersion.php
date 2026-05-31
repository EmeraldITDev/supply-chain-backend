<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentScheduleVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_schedule_id',
        'version',
        'changed_by',
        'snapshot_before',
        'snapshot_after',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot_before' => 'array',
            'snapshot_after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
