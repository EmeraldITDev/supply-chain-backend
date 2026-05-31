<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMilestone extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAYMENT_REQUESTED = 'payment_requested';

    public const STATUS_PAID = 'paid';

    public const STATUS_COMPLETE = 'complete';

    public const TRIGGER_ON_ADVANCE = 'on_advance';

    public const TRIGGER_UPON_DELIVERY = 'upon_delivery';

    public const TRIGGER_UPON_COMPLETION = 'upon_completion';

    protected $fillable = [
        'payment_schedule_id',
        'milestone_number',
        'label',
        'percentage',
        'amount',
        'trigger_condition',
        'required_documents',
        'status',
        'paid_amount',
        'paid_at',
        'finance_ap_reference',
        'predecessor_milestone_id',
    ];

    protected function casts(): array
    {
        return [
            'milestone_number' => 'integer',
            'percentage' => 'decimal:2',
            'amount' => 'decimal:2',
            'required_documents' => 'array',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_milestone_id');
    }
}
