<?php

namespace App\Models\Logistics;

use App\Models\Vendor;
use App\Models\User;
use App\Services\DashboardStatsCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function () {
            DashboardStatsCache::forgetAll();
        });
        static::deleted(function () {
            DashboardStatsCache::forgetAll();
        });
    }

    protected $table = 'logistics_trips';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_VENDOR_ASSIGNED = 'vendor_assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CONVERTED_TO_JOURNEY = 'converted_to_journey';

    public const TYPE_PERSONNEL = 'personnel';
    public const TYPE_MATERIAL = 'material';
    public const TYPE_MIXED = 'mixed';

    public const BOOKING_SCOPE_OUT_OF_STATE_LOCAL = 'out_of_state_local';
    public const BOOKING_SCOPE_INTERNATIONAL = 'international';
    public const BOOKING_SCOPE_WITHIN_STATE = 'within_state';

    /** @deprecated Use BOOKING_SCOPE_OUT_OF_STATE_LOCAL */
    public const BOOKING_SCOPE_OUTSIDE_STATE = 'outside_state';

    public const INTERNATIONAL_TRANSPORT_FLIGHT = 'flight';

    public const INTERNATIONAL_TRANSPORT_ROAD = 'road';

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
        'booking_scope',
        'international_transport_mode',
        'priority',
        'status',
        'scheduled_departure_at',
        'scheduled_arrival_at',
        'actual_departure_at',
        'actual_arrival_at',
        'origin',
        'destination',
        'vendor_id',
        'vehicle_id',
        'multi_vendor',
        'selected_vendor_id',
        'approval_status',
        'workflow_stage',
        'passenger_user_ids',
        'external_passengers',
        'driver_user_id',
        'external_driver',
        'po_number',
        'unsigned_po_url',
        'signed_po_url',
        'created_by',
        'updated_by',
        'cancelled_by',
        'cancelled_at',
        'notes',
        'accommodation_required',
        'accommodation_name',
        'accommodation_address',
        'accommodation_contact',
        'accommodation_details',
        'accommodation_estimated_cost',
        'escort_required',
        'escort_description',
        'estimated_cost',
        'comments',
        'metadata',
    ];

    protected $casts = [
        'scheduled_departure_at' => 'datetime',
        'scheduled_arrival_at' => 'datetime',
        'actual_departure_at' => 'datetime',
        'actual_arrival_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
        'passenger_user_ids' => 'array',
        'external_passengers' => 'array',
        'external_driver' => 'array',
        'accommodation_required' => 'boolean',
        'escort_required' => 'boolean',
        'accommodation_estimated_cost' => 'float',
        'estimated_cost' => 'float',
    ];

    /**
     * Driver fields for API round-trip (internal user or external contractor).
     *
     * @return array<string, mixed>
     */
    public function driverApiFields(): array
    {
        $ext = is_array($this->external_driver) ? $this->external_driver : null;
        $hasExternal = $ext && (trim((string) ($ext['name'] ?? '')) !== '' || trim((string) ($ext['phone'] ?? '')) !== '');

        if ($hasExternal) {
            return [
                'external_driver' => $ext,
                'externalDriver' => $ext,
                'driver_user_id' => null,
                'driverUserId' => null,
                'driverName' => $ext['name'] ?? null,
                'driverPhone' => $ext['phone'] ?? null,
                'driverLicenseNumber' => $ext['license_number'] ?? null,
                'driver_license_number' => $ext['license_number'] ?? null,
            ];
        }

        $this->loadMissing('driver');

        return [
            'external_driver' => null,
            'externalDriver' => null,
            'driver_user_id' => $this->driver_user_id,
            'driverUserId' => $this->driver_user_id,
            'driverName' => $this->driver?->name,
            'driverPhone' => $this->driver?->phone,
            'driverLicenseNumber' => null,
            'driver_license_number' => null,
        ];
    }

    public const WORKFLOW_TRIP_REQUEST = 'trip_request';
    public const WORKFLOW_CHANGES_REQUESTED = 'changes_requested';
    public const WORKFLOW_DIRECTOR_REVIEW = 'director_review';
    public const WORKFLOW_DIRECTOR_APPROVED = 'director_approved';
    public const WORKFLOW_LOGISTICS_REVIEW = 'logistics_review';
    public const WORKFLOW_PROCUREMENT_REVIEW = 'procurement_review';
    public const WORKFLOW_SCD_APPROVAL = 'scd_approval';
    public const WORKFLOW_PO_PENDING_SIGN = 'po_pending_sign';
    public const WORKFLOW_PO_SIGNED = 'po_signed';
    public const WORKFLOW_COMPLETED = 'completed';

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function selectedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'selected_vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
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

    public function vendorSubmissions(): HasMany
    {
        return $this->hasMany(TripVendorSubmission::class, 'trip_id');
    }

    public function accommodations(): HasMany
    {
        return $this->hasMany(AccommodationBooking::class, 'trip_id');
    }

    public function jobCompletionCertificate()
    {
        return $this->hasOne(JobCompletionCertificate::class, 'trip_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TripComment::class, 'trip_id');
    }

    public function logisticsTripIdFromMetadata(): ?int
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $id = $metadata['logistics_trip_id'] ?? $metadata['logisticsTripId'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Trip requests created via the TRQ-* workflow (excludes logistics TRIP-* records).
     */
    public function scopeTripRequests($query)
    {
        return $query->where('trip_code', 'like', 'TRQ-%');
    }
}
