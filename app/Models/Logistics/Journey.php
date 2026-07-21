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
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DEPARTED = 'departed';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'trip_id',
        'trip_request_id',
        'status',
        'departed_at',
        'arrived_at',
        'last_checkpoint_at',
        'last_checkpoint_location',
        'vendor_status',
        'driver_name',
        'driver_phone',
        'driver_email',
        'vehicle_id',
        'vehicle_plate_number',
        'vehicle_make',
        'vehicle_model',
        'departure_time',
        'expected_arrival_time',
        'actual_departure_time',
        'actual_arrival_time',
        'accommodation_name',
        'accommodation_address',
        'accommodation_contact',
        'accommodation_details',
        'accommodation_estimated_cost',
        'escort_description',
        'passengers',
        'purpose',
        'departure_location',
        'destination',
        'feedback',
        'jcc_generated',
        'jcc_document_id',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $casts = [
        'departed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'last_checkpoint_at' => 'datetime',
        'departure_time' => 'datetime',
        'expected_arrival_time' => 'datetime',
        'actual_departure_time' => 'datetime',
        'actual_arrival_time' => 'datetime',
        'metadata' => 'array',
        'passengers' => 'array',
        'jcc_generated' => 'boolean',
        'accommodation_estimated_cost' => 'float',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function tripRequest(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
