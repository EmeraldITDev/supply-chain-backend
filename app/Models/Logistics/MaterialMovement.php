<?php

namespace App\Models\Logistics;

use App\Enums\MaterialStatus;
use App\Enums\MaterialCondition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MaterialMovement extends Model
{
    use HasFactory;

    protected $table = 'logistics_material_movements';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'material_name',
        'quantity',
        'category',
        'pickup_location',
        'destination',
        'vendor_id',
        'vendor_name',
        'vendor_phone',
        'vehicle_plate_number',
        'driver_name',
        'driver_phone',
        'expected_pickup_datetime',
        'expected_delivery_datetime',
        'actual_pickup_datetime',
        'actual_delivery_datetime',
        'condition_of_goods',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expected_pickup_datetime' => 'datetime',
        'expected_delivery_datetime' => 'datetime',
        'actual_pickup_datetime' => 'datetime',
        'actual_delivery_datetime' => 'datetime',
        'condition_of_goods' => MaterialCondition::class,
        'status' => MaterialStatus::class,
    ];

    /**
     * Boot function for model events.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Get the vendor that is transporting this material.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Get the user who created this material movement.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this material movement.
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the job completion certificate for this material movement.
     */
    public function jcc(): HasOne
    {
        return $this->hasOne(MaterialJCC::class, 'material_movement_id');
    }

    /**
     * Check if material is pending pickup.
     */
    public function isPending(): bool
    {
        return $this->status === MaterialStatus::PENDING;
    }

    /**
     * Check if material is in transit.
     */
    public function isInTransit(): bool
    {
        return $this->status === MaterialStatus::IN_TRANSIT;
    }

    /**
     * Check if material has been delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === MaterialStatus::DELIVERED;
    }

    /**
     * Check if material movement is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === MaterialStatus::CANCELLED;
    }

    /**
     * Mark material as in transit.
     */
    public function markInTransit(\DateTimeInterface $pickupTime): void
    {
        $this->update([
            'status' => MaterialStatus::IN_TRANSIT,
            'actual_pickup_datetime' => $pickupTime,
        ]);
    }

    /**
     * Mark material as delivered.
     */
    public function markDelivered(\DateTimeInterface $deliveryTime): void
    {
        $this->update([
            'status' => MaterialStatus::DELIVERED,
            'actual_delivery_datetime' => $deliveryTime,
        ]);
    }

    /**
     * Cancel the material movement.
     */
    public function cancel(): void
    {
        $this->update(['status' => MaterialStatus::CANCELLED]);
    }
}
