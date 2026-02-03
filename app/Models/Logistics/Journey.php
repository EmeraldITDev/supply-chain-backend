<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Journey extends Model
{
    use HasFactory;

    protected $table = 'logistics_journeys';

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_DEPARTED = 'departed';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'trip_id',
        'status',
        'departed_at',
        'arrived_at',
        'last_checkpoint_at',
        'last_checkpoint_location',
        'vendor_status',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $casts = [
        'departed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'last_checkpoint_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
