<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JCCLineItem extends Model
{
    use HasFactory;

    protected $table = 'job_completion_certificate_line_items';

    public const ITEM_TYPE_VEHICLE = 'vehicle';
    public const ITEM_TYPE_SERVICE = 'service';
    public const ITEM_TYPE_MATERIAL = 'material';
    public const ITEM_TYPE_OTHER = 'other';

    public const CONDITION_GOOD = 'good';
    public const CONDITION_FAIR = 'fair';
    public const CONDITION_DAMAGED = 'damaged';
    public const CONDITION_LOST = 'lost';

    protected $fillable = [
        'jcc_id',
        'line_number',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'amount',
        'item_type',
        'details',
        'condition',
        'remarks',
        'reference_number',
        'vendor_submission_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'details' => 'array',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function jcc(): BelongsTo
    {
        return $this->belongsTo(JobCompletionCertificate::class, 'jcc_id');
    }

    public function vendorSubmission(): BelongsTo
    {
        return $this->belongsTo(TripVendorSubmission::class, 'vendor_submission_id');
    }

    /**
     * Get human-readable condition label
     */
    public function getConditionLabel(): ?string
    {
        return match ($this->condition) {
            self::CONDITION_GOOD => 'Good',
            self::CONDITION_FAIR => 'Fair',
            self::CONDITION_DAMAGED => 'Damaged',
            self::CONDITION_LOST => 'Lost',
            default => null,
        };
    }

    /**
     * Get human-readable item type label
     */
    public function getItemTypeLabel(): string
    {
        return match ($this->item_type) {
            self::ITEM_TYPE_VEHICLE => 'Vehicle',
            self::ITEM_TYPE_SERVICE => 'Service',
            self::ITEM_TYPE_MATERIAL => 'Material',
            self::ITEM_TYPE_OTHER => 'Other',
        };
    }

    /**
     * Create line item from vendor submission
     */
    public static function fromVendorSubmission(
        JobCompletionCertificate $jcc,
        TripVendorSubmission $submission,
        int $lineNumber
    ): self {
        return self::create([
            'jcc_id' => $jcc->id,
            'line_number' => $lineNumber,
            'description' => "{$submission->vehicle_make} {$submission->vehicle_model}",
            'item_type' => self::ITEM_TYPE_VEHICLE,
            'details' => [
                'make' => $submission->vehicle_make,
                'model' => $submission->vehicle_model,
                'plate_number' => $submission->plate_number,
                'driver_name' => $submission->driver_name,
                'driver_phone' => $submission->driver_phone,
            ],
            'reference_number' => $submission->plate_number,
            'vendor_submission_id' => $submission->id,
            'condition' => self::CONDITION_GOOD,
        ]);
    }
}
