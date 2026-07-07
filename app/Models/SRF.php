<?php

namespace App\Models;

use App\Services\DashboardStatsCache;
use App\Support\ListCountCache;
use App\Support\TableColumnCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;

class SRF extends Model
{
    protected $table = 's_r_f_s';

    protected static function booted(): void
    {
        static::saved(function () {
            DashboardStatsCache::forgetAll();
            ListCountCache::bump('srf');
        });
        static::deleted(function () {
            DashboardStatsCache::forgetAll();
            ListCountCache::bump('srf');
        });
    }

    protected $fillable = [
        'srf_id',
        'formatted_id',
        'title',
        'service_type',
        'contract_type',
        'urgency',
        'description',
        'duration',
        'estimated_cost',
        'justification',
        'requester_id',
        'requester_name',
        'department',
        'date',
        'status',
        'current_stage',
        'approval_history',
        'rejection_reason',
        'remarks',
        'vehicle_id',
        'maintenance_id',
        'vehicle_snapshot',
        'maintenance_history',
        'rfq_prefill',
        'origin',
        'payment_milestones',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'date' => 'date',
        'approval_history' => 'array',
        'vehicle_snapshot' => 'array',
        'maintenance_history' => 'array',
        'rfq_prefill' => 'array',
        'payment_milestones' => 'array',
    ];

    /**
     * Optional link back to the fleet vehicle this SRF was initiated for.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Logistics\Vehicle::class, 'vehicle_id');
    }

    /**
     * Optional link to the specific maintenance record that triggered the SRF.
     */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Logistics\VehicleMaintenance::class, 'maintenance_id');
    }

    /**
     * Get the user who requested this SRF
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SRFItem::class, 'srf_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Slimmer columns for default SRF list tables (omits logistics FK ids).
     *
     * @var list<string>
     */
    public const LIST_TABLE_SELECT = [
        'id', 'srf_id', 'formatted_id', 'title', 'service_type', 'contract_type',
        'urgency', 'duration', 'estimated_cost', 'requester_id', 'requester_name',
        'department', 'date', 'created_at', 'updated_at', 'status', 'current_stage',
        'rejection_reason', 'remarks', 'origin',
    ];

    /**
     * Columns loaded for SRF list endpoints.
     *
     * @var list<string>
     */
    public const LIST_API_SELECT = [
        'id', 'srf_id', 'formatted_id', 'title', 'service_type', 'contract_type',
        'urgency', 'duration', 'estimated_cost', 'requester_id', 'requester_name',
        'department', 'date', 'created_at', 'updated_at', 'status', 'current_stage',
        'rejection_reason', 'remarks', 'origin', 'vehicle_id', 'maintenance_id',
    ];

    /**
     * @return list<string>
     */
    public static function resolveListApiSelect(): array
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        if (! TableColumnCache::hasTable('s_r_f_s')) {
            $resolved = self::LIST_API_SELECT;

            return $resolved;
        }

        $resolved = TableColumnCache::filterExisting('s_r_f_s', self::LIST_API_SELECT);

        $missing = array_values(array_diff(self::LIST_API_SELECT, $resolved));
        if ($missing !== []) {
            Log::warning('SRF list select omits missing columns', ['columns' => $missing]);
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    public static function resolveTableListSelect(): array
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        if (! TableColumnCache::hasTable('s_r_f_s')) {
            $resolved = self::LIST_TABLE_SELECT;

            return $resolved;
        }

        $resolved = TableColumnCache::filterExisting('s_r_f_s', self::LIST_TABLE_SELECT);

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    public function toListApiArray(): array
    {
        $requesterName = $this->requester_name
            ?: ($this->relationLoaded('requester') && $this->requester ? $this->requester->name : null);

        return [
            'id' => $this->srf_id,
            'formattedId' => $this->formatted_id,
            'formatted_id' => $this->formatted_id,
            'legacyId' => $this->srf_id,
            'legacy_id' => $this->srf_id,
            'title' => $this->title,
            'serviceType' => $this->service_type,
            'service_type' => $this->service_type,
            'contractType' => $this->contract_type,
            'contract_type' => $this->contract_type,
            'department' => $this->department,
            'urgency' => $this->urgency,
            'duration' => $this->duration,
            'estimatedCost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
            'estimated_cost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
            'requesterName' => $requesterName,
            'requester_name' => $requesterName,
            'requester' => [
                'id' => (int) $this->requester_id,
                'name' => $requesterName,
                'email' => $this->relationLoaded('requester') && $this->requester ? $this->requester->email : null,
            ],
            'requesterId' => (string) $this->requester_id,
            'requester_id' => (string) $this->requester_id,
            'date' => $this->date ? $this->date->format('Y-m-d') : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'status' => $this->status,
            'currentStage' => $this->current_stage,
            'current_stage' => $this->current_stage,
            'rejectionReason' => $this->rejection_reason,
            'rejection_reason' => $this->rejection_reason,
            'remarks' => $this->remarks,
            'origin' => $this->origin,
            'vehicleId' => $this->vehicle_id,
            'maintenanceId' => $this->maintenance_id,
            'lineItemCount' => $this->relationLoaded('items') ? $this->items->count() : null,
            'line_items_count' => $this->relationLoaded('items') ? $this->items->count() : null,
        ];
    }

    /**
     * Generate SRF ID
     */
    public static function generateSRFId(): string
    {
        $year = date('Y');
        $lastSRF = self::where('srf_id', 'like', "SRF-{$year}-%")
            ->orderBy('srf_id', 'desc')
            ->first();

        if ($lastSRF) {
            $lastNumber = (int) substr($lastSRF->srf_id, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "SRF-{$year}-{$newNumber}";
    }
}
