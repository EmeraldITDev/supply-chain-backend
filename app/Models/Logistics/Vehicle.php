<?php

namespace App\Models\Logistics;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_UNDER_MAINTENANCE = 'UNDER_MAINTENANCE';

    public const INACTIVE_REASON_DOCUMENT_EXPIRED = 'DOCUMENT_EXPIRED';

    public const INACTIVE_REASON_MAINTENANCE_OVERDUE = 'MAINTENANCE_OVERDUE';

    protected $table = 'logistics_vehicles';

    protected $fillable = [
        'vehicle_code',
        'name',
        'plate_number',
        'type',
        'make',
        'make_model',
        'year',
        'color',
        'fuel_type',
        'capacity',
        'passenger_capacity',
        'status',
        'status_inactive_reason',
        'vendor_id',
        'gps_device_id',
        'last_service_at',
        'metadata',
    ];

    protected $casts = [
        'capacity' => 'decimal:2',
        'passenger_capacity' => 'integer',
        'last_service_at' => 'datetime',
        'metadata' => 'array',
        'year' => 'integer',
    ];

    protected $appends = ['ownership_type', 'cargo_capacity'];

    /**
     * Frontend expects `cargo_capacity`; DB column is `capacity`.
     */
    public function getCargoCapacityAttribute(): mixed
    {
        return $this->capacity;
    }

    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle): void {
            if (empty($vehicle->status)) {
                $vehicle->status = self::STATUS_ACTIVE;
            } else {
                $vehicle->status = strtoupper((string) $vehicle->status);
            }
        });

        static::updating(function (Vehicle $vehicle): void {
            if ($vehicle->isDirty('status') && $vehicle->status !== null) {
                $vehicle->status = strtoupper((string) $vehicle->status);
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Get the ownership type (Owned or Vendor)
     */
    public function getOwnershipTypeAttribute(): string
    {
        return $this->vendor_id ? 'Vendor' : 'Owned';
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class, 'vehicle_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
