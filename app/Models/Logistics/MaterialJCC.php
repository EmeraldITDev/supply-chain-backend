<?php

namespace App\Models\Logistics;

use App\Enums\MaterialJCCStatus;
use App\Enums\ConditionOnArrival;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialJCC extends Model
{
    use HasFactory;

    protected $table = 'logistics_material_jccs';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'material_movement_id',
        'reference_number',
        'vendor_id',
        'vendor_name',
        'po_number',
        'certification_text',
        'condition_on_arrival',
        'status',
        'issued_by',
        'issued_at',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'condition_on_arrival' => ConditionOnArrival::class,
        'status' => MaterialJCCStatus::class,
        'issued_at' => 'datetime',
        'approved_at' => 'datetime',
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
     * Get the material movement associated with this JCC.
     */
    public function materialMovement(): BelongsTo
    {
        return $this->belongsTo(MaterialMovement::class, 'material_movement_id');
    }

    /**
     * Get the vendor associated with this JCC.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Get the user who issued this JCC.
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Get the user who approved this JCC.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get all line items for this JCC.
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(MaterialJCCLineItem::class, 'jcc_id');
    }

    /**
     * Check if JCC is in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === MaterialJCCStatus::DRAFT;
    }

    /**
     * Check if JCC is submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->status === MaterialJCCStatus::SUBMITTED;
    }

    /**
     * Check if JCC is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === MaterialJCCStatus::APPROVED;
    }

    /**
     * Submit the JCC for approval.
     */
    public function submit(): void
    {
        $this->update([
            'status' => MaterialJCCStatus::SUBMITTED,
        ]);
    }

    /**
     * Approve the JCC and close the material movement.
     */
    public function approve(User $approver): void
    {
        \DB::transaction(function () use ($approver) {
            // Update JCC status
            $this->update([
                'status' => MaterialJCCStatus::APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            // Mark material movement as delivered
            if ($this->materialMovement && !$this->materialMovement->isDelivered()) {
                $this->materialMovement->markDelivered(now());
            }
        });
    }
}
