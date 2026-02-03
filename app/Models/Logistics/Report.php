<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    protected $table = 'logistics_reports';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'trip_id',
        'journey_id',
        'report_type',
        'status',
        'submitted_at',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'payload' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class, 'journey_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
