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

    protected $table = 'logistics_vehicles';

    protected $fillable = [
        'vehicle_code',
        'plate_number',
        'type',
        'capacity',
        'status',
        'vendor_id',
        'gps_device_id',
        'last_service_at',
        'metadata',
    ];

    protected $casts = [
        'capacity' => 'decimal:2',
        'last_service_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
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
