<?php

namespace App\Models\Logistics;

use App\Models\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected $table = 'logistics_trips';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_VENDOR_ASSIGNED = 'vendor_assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_PERSONNEL = 'personnel';
    public const TYPE_MATERIAL = 'material';
    public const TYPE_MIXED = 'mixed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'trip_code',
        'title',
        'description',
        'purpose',
        'trip_type',
        'priority',
        'status',
        'scheduled_departure_at',
        'scheduled_arrival_at',
        'actual_departure_at',
        'actual_arrival_at',
        'origin',
        'destination',
        'vendor_id',
        'created_by',
        'updated_by',
        'cancelled_by',
        'cancelled_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'scheduled_departure_at' => 'datetime',
        'scheduled_arrival_at' => 'datetime',
        'actual_departure_at' => 'datetime',
        'actual_arrival_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journeys(): HasMany
    {
        return $this->hasMany(Journey::class, 'trip_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class, 'trip_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'trip_id');
    }
}
