<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSchedule extends Model
{
    protected $fillable = [
        'mrf_id',
        'template_name',
        'total_percentage_check',
        'created_by',
        'approved_at',
        'locked_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'total_percentage_check' => 'decimal:2',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function mrf(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(PaymentMilestone::class)->orderBy('milestone_number');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PaymentScheduleVersion::class)->orderByDesc('version');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
