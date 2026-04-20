<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $fillable = [
        'quotation_id',
        'rfq_id',
        'vendor_id',
        'vendor_name',
        'quote_number',
        'total_amount',
        'currency',
        'price', // Legacy field
        'delivery_days',
        'delivery_date',
        'payment_terms',
        'validity_days',
        'warranty_period',
        'attachments',
        'notes',
        'status',
        'review_status',
        'rejection_reason',
        'revision_notes',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'delivery_date' => 'date',
        'approved_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'attachments' => 'array',
        'delivery_days' => 'integer',
        'validity_days' => 'integer',
        'review_status' => 'string',
    ];

    /**
     * Get the RFQ this quotation is for
     */
    public function rfq()
    {
        return $this->belongsTo(RFQ::class, 'rfq_id');
    }

    /**
     * Get the vendor who submitted this quotation
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Get the user who approved this quotation
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who reviewed this quotation
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get quotation items
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id');
    }

    /**
     * Calculate total amount from items
     */
    public function calculateTotalFromItems(): void
    {
        $this->total_amount = $this->items()->sum('total_price');
    }

    public function mrf()
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }
    /**
     * Generate Quotation ID
     */
    public static function generateQuotationId(): string
    {
        $year = date('Y');
        $lastQuotation = self::where('quotation_id', 'like', "QUO-{$year}-%")
            ->orderBy('quotation_id', 'desc')
            ->first();

        if ($lastQuotation) {
            $lastNumber = (int) substr($lastQuotation->quotation_id, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "QUO-{$year}-{$newNumber}";
    }
}
