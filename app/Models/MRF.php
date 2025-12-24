<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MRF extends Model
{
    protected $table = 'm_r_f_s';

    protected $fillable = [
        'mrf_id',
        'title',
        'category',
        'urgency',
        'description',
        'quantity',
        'estimated_cost',
        'justification',
        'requester_id',
        'requester_name',
        'date',
        'status',
        'current_stage',
        'approval_history',
        'rejection_reason',
        'is_resubmission',
        'remarks',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'date' => 'date',
        'is_resubmission' => 'boolean',
        'approval_history' => 'array',
    ];

    /**
     * Get the user who requested this MRF
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Get RFQs created from this MRF
     */
    public function rfqs(): HasMany
    {
        return $this->hasMany(RFQ::class, 'mrf_id');
    }

    /**
     * Generate MRF ID
     */
    public static function generateMRFId(): string
    {
        $year = date('Y');
        $lastMRF = self::where('mrf_id', 'like', "MRF-{$year}-%")
            ->orderBy('mrf_id', 'desc')
            ->first();

        if ($lastMRF) {
            $lastNumber = (int) substr($lastMRF->mrf_id, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "MRF-{$year}-{$newNumber}";
    }
}
