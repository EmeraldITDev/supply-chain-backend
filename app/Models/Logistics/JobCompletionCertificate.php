<?php

namespace App\Models\Logistics;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class JobCompletionCertificate extends Model
{
    use HasFactory;

    protected $table = 'job_completion_certificates';

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'trip_id',
        'vendor_id',
        'issued_by',
        'issued_at',
        'remarks',
        'delivery_confirmed',
        'condition_of_goods',
        'attachments',
        'status',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'reference_number',
        'po_number',
        'certification_text',
        'service_period_start',
        'service_period_end',
        'metadata',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'approved_at' => 'datetime',
        'delivery_confirmed' => 'boolean',
        'attachments' => 'array',
        'metadata' => 'array',
        'service_period_start' => 'date',
        'service_period_end' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get all attachments/documents for this JCC (polymorphic relationship)
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get all line items for this JCC
     */
    public function lineItems()
    {
        return $this->hasMany(JCCLineItem::class, 'jcc_id');
    }

    /**
     * Check if JCC is in draft status
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if JCC is submitted
     */
    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Check if JCC is approved
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Submit the JCC
     */
    public function submit(): void
    {
        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'issued_at' => now(),
        ]);

        // Update trip status to 'pending_jcc_approval'
        if ($this->trip) {
            $this->trip->update(['status' => Trip::STATUS_IN_PROGRESS]);
        }
    }

    /**
     * Approve the JCC and close the trip
     */
    public function approve(int $approvedBy, string $remarks = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'approval_remarks' => $remarks,
        ]);

        // Update trip status to 'closed'
        if ($this->trip) {
            $this->trip->update(['status' => Trip::STATUS_CLOSED]);
        }
    }

    /**
     * Add an attachment
     */
    public function addAttachment(string $filePath, string $fileName = null): void
    {
        $attachments = $this->attachments ?? [];
        $attachments[] = [
            'file_path' => $filePath,
            'file_name' => $fileName,
            'uploaded_at' => now(),
        ];
        $this->update(['attachments' => $attachments]);
    }
}
