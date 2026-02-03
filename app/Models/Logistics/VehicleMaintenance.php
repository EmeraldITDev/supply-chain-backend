<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenance extends Model
{
    use HasFactory;

    protected $table = 'logistics_vehicle_maintenances';

    protected $fillable = [
        'vehicle_id',
        'maintenance_type',
        'description',
        'performed_at',
        'next_due_at',
        'cost',
        'performed_by',
        'status',
        'metadata',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
        'next_due_at' => 'datetime',
        'cost' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
