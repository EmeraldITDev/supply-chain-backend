<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccommodationBooking extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'accommodation_bookings';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'trip_id',
        'passenger_names',
        'destination_state',
        'destination_city',
        'number_of_nights',
        'hotel_name',
        'check_in_date',
        'check_out_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'passenger_names' => 'array',
        'check_in_date' => 'date',
        'check_out_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = \Illuminate\Support\Str::uuid();
            }
        });

        static::updating(function ($model) {
            // Auto-calculate check_out_date if not set
            if ($model->check_in_date && $model->number_of_nights) {
                $model->check_out_date = $model->check_in_date->addDays($model->number_of_nights);
            }
        });

        static::created(function ($model) {
            // Auto-calculate check_out_date
            if ($model->check_in_date && $model->number_of_nights) {
                $model->check_out_date = $model->check_in_date->addDays($model->number_of_nights);
                $model->saveQuietly();
            }
        });
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Add a passenger to the booking
     */
    public function addPassenger(string $name): void
    {
        $passengers = $this->passenger_names ?? [];
        if (!in_array($name, $passengers)) {
            $passengers[] = $name;
            $this->update(['passenger_names' => $passengers]);
        }
    }

    /**
     * Remove a passenger from the booking
     */
    public function removePassenger(string $name): void
    {
        $passengers = $this->passenger_names ?? [];
        $passengers = array_filter($passengers, fn($p) => $p !== $name);
        $this->update(['passenger_names' => array_values($passengers)]);
    }

    /**
     * Get passenger count
     */
    public function getPassengerCount(): int
    {
        return count($this->passenger_names ?? []);
    }

    /**
     * Calculate check_out_date
     */
    public function getCheckOutDateAttribute()
    {
        if ($this->check_in_date && $this->number_of_nights) {
            return $this->check_in_date->addDays($this->number_of_nights);
        }
        return $this->attributes['check_out_date'] ?? null;
    }
}
