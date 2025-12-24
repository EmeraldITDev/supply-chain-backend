<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SRF extends Model
{
    protected $table = 's_r_f_s';

    protected $fillable = [
        'srf_id',
        'title',
        'service_type',
        'urgency',
        'description',
        'duration',
        'estimated_cost',
        'justification',
        'requester_id',
        'requester_name',
        'date',
        'status',
        'current_stage',
        'approval_history',
        'rejection_reason',
        'remarks',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'date' => 'date',
        'approval_history' => 'array',
    ];

    /**
     * Get the user who requested this SRF
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
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
