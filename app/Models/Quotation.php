<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quotation extends Model
{
    protected $fillable = [
        'quotation_id',
        'rfq_id',
        'vendor_id',
        'vendor_name',
        'price',
        'delivery_date',
        'notes',
        'status',
        'rejection_reason',
        'approval_remarks',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'delivery_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Get the RFQ this quotation is for
     */
    public function rfq(): BelongsTo
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
