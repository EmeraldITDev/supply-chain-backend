<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RFQ extends Model
{
    protected $table = 'r_f_q_s';

    protected $fillable = [
        'rfq_id',
        'mrf_id',
        'mrf_title',
        'title',
        'category',
        'description',
        'quantity',
        'estimated_cost',
        'deadline',
        'payment_terms',
        'notes',
        'supporting_documents',
        'status',
        'workflow_state',
        'created_by',
        'selected_vendor_id',
        'selected_quotation_id',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'deadline' => 'date',
        'supporting_documents' => 'array',
    ];

    /**
     * Get the MRF this RFQ is based on
     */
    public function mrf(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }

    /**
     * Get the user who created this RFQ
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get vendors associated with this RFQ (with engagement tracking)
     */
    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'rfq_vendors', 'rfq_id', 'vendor_id')
            ->withPivot(['sent_at', 'viewed_at', 'responded', 'responded_at'])
            ->withTimestamps();
    }

    /**
     * Get RFQ items
     */
    public function items(): HasMany
    {
        return $this->hasMany(RFQItem::class, 'rfq_id');
    }

    /**
     * Get quotations for this RFQ
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'rfq_id');
    }
    
    /**
     * Get selected vendor for this RFQ
     */
    public function selectedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'selected_vendor_id');
    }
    
    /**
     * Get selected quotation for this RFQ
     */
    public function selectedQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'selected_quotation_id');
    }

    /**
     * Generate RFQ ID
     */
    public static function generateRFQId(): string
    {
        $year = date('Y');
        $lastRFQ = self::where('rfq_id', 'like', "RFQ-{$year}-%")
            ->orderBy('rfq_id', 'desc')
            ->first();

        if ($lastRFQ) {
            $lastNumber = (int) substr($lastRFQ->rfq_id, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "RFQ-{$year}-{$newNumber}";
    }

    /**
     * Generate meaningful RFQ title based on MRF details
     * Format: "{Product/Service} – {Contract Type} RFQ"
     * Example: "Laptop Supply – Heritage RFQ"
     */
    public function getDisplayTitle(): string
    {
        // Get product/service name from title, category, or description
        $productName = $this->title ?? $this->category ?? 'Request';
        
        // If title is generic, try to extract from description or use category
        if (in_array(strtolower($productName), ['rfq', 'request', 'quotation request', '']))
        {
            $productName = $this->category ?? 'Supply';
        }
        
        // Get contract type from MRF
        $contractType = null;
        if ($this->mrf && $this->mrf->contract_type) {
            $contractType = ucfirst($this->mrf->contract_type);
        }
        
        // Build title
        if ($contractType) {
            return "{$productName} – {$contractType} RFQ";
        }
        
        return "{$productName} RFQ";
    }
}
