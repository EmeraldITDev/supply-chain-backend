<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class VehicleMaintenance extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_OVERDUE = 'OVERDUE';

    protected $table = 'logistics_vehicle_maintenances';

    protected $fillable = [
        'vehicle_id',
        'maintenance_type',
        'interval_months',
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
        'interval_months' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Documents attached to this maintenance record (invoices, before/after
     * photos, certificates of work, etc.). Uses the shared polymorphic
     * `documents` table.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
