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

    public const BOOKING_SCOPE_WITHIN_STATE = 'within_state';
    public const BOOKING_SCOPE_OUTSIDE_STATE = 'outside_state';

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
        'po_number',
        'unsigned_po_url',
        'signed_po_url',
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
        'passenger_user_ids' => 'array',
        'external_passengers' => 'array',
    ];

    public const WORKFLOW_TRIP_REQUEST = 'trip_request';
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
}
