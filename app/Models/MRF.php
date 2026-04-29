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
        'contract_type',
        'urgency',
        'description',
        'quantity',
        'estimated_cost',
        'currency',
        'justification',
        'requester_id',
        'requester_name',
        'department',
        'date',
        'status',
        'current_stage',
        'workflow_state',
        'approval_history',
        'rejection_reason',
        'rejection_comments',
        'rejected_by',
        'rejected_at',
        'is_resubmission',
        'previous_submission_id',
        'contract_type',
        'remarks',
        // PFI (Proforma Invoice)
        'pfi_url',
        'pfi_share_url',
        // Supporting Attachment
        'attachment_url',
        'attachment_share_url',
        'attachment_name',
        // Executive approval
        'executive_approved',
        'executive_approved_by',
        'executive_approved_at',
        'director_approved_at',
        'procurement_review_started_at',
        'last_action_by_role',

        'director_approved_by',
        'director_remarks',

        'executive_remarks',
        // Chairman approval
        'chairman_approved',
        'chairman_approved_by',
        'chairman_approved_at',
        'chairman_remarks',
        // PO information
        'po_number',
        'unsigned_po_url',
        'unsigned_po_share_url',
        'signed_po_url',
        'signed_po_share_url',
        'po_version',
        'po_generated_at',
        'po_signed_at',
        // PO Details
        'ship_to_address',
        'tax_rate',
        'tax_amount',
        'po_special_terms',
        'invoice_submission_email',
        'invoice_submission_cc',
        // Payment
        'payment_status',
        'payment_processed_at',
        'payment_approved_at',
        'payment_approved_by',
        // GRN (Goods Received Note)
        'grn_requested',
        'grn_requested_at',
        'grn_requested_by',
        'grn_completed',
        'grn_completed_at',
        'grn_completed_by',
        'grn_url',
        'grn_share_url',
        // Vendor selection and invoice
        'selected_vendor_id',
        'invoice_url',
        'invoice_share_url',
        'invoice_approved_by',
        'invoice_approved_at',
        'invoice_remarks',
        'expected_delivery_date',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'date' => 'date',
        'is_resubmission' => 'boolean',
        'approval_history' => 'array',
        'executive_approved' => 'boolean',
        'executive_approved_at' => 'datetime',
        'chairman_approved' => 'boolean',
        'chairman_approved_at' => 'datetime',
        'procurement_review_started_at' => 'datetime',
        'director_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'po_generated_at' => 'datetime',
        'po_signed_at' => 'datetime',
        // Note: tax_rate and tax_amount casts commented out until migration runs
        // Uncomment after running: php artisan migrate (migration: 2026_01_24_180004_add_po_details_to_m_r_f_s_table)
        // 'tax_rate' => 'decimal:2',
        // 'tax_amount' => 'decimal:2',
        'payment_processed_at' => 'datetime',
        'payment_approved_at' => 'datetime',
        'grn_requested' => 'boolean',
        'grn_requested_at' => 'datetime',
        'grn_completed' => 'boolean',
        'grn_completed_at' => 'datetime',
        'invoice_approved_at' => 'datetime',
        'expected_delivery_date' => 'date',
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
    public function rfqs()
    {
        return $this->hasMany(RFQ::class, 'mrf_id');
    }

    public function quotations()
    {
        return $this->hasManyThrough(
            \App\Models\Quotation::class,
            \App\Models\RFQ::class,
            'mrf_id',
            'rfq_id',
            'id',
            'id'
        );
    }
    /**
     * Get MRF items
     */
    public function items(): HasMany
    {
        return $this->hasMany(MRFItem::class, 'mrf_id');
    }

    /**
     * Get approval history
     */
    public function approvalHistory(): HasMany
    {
        return $this->hasMany(MRFApprovalHistory::class, 'mrf_id')->orderBy('created_at', 'asc');
    }

    /**
     * Get executive who approved this MRF
     */
    public function executiveApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executive_approved_by');
    }

    /**
     * Get chairman who approved this MRF
     */
    public function chairmanApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairman_approved_by');
    }

    /**
     * Get user who rejected this MRF
     */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get previous submission if this is a resubmission
     */
    public function previousSubmission(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'previous_submission_id');
    }

    /**
     * Get payment approver
     */
    public function paymentApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_approved_by');
    }

    /**
     * Get selected vendor
     */
    public function selectedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'selected_vendor_id');
    }

    /**
     * Get selected quotation through RFQ
     */
    public function selectedQuotation()
    {
        // Find quotation through RFQ's selected_quotation_id
        $rfq = $this->rfqs()->first();
        if ($rfq && $rfq->selected_quotation_id) {
            return Quotation::find($rfq->selected_quotation_id);
        }
        // Fallback: find approved quotation by selected vendor
        if ($this->selected_vendor_id) {
            return Quotation::where('vendor_id', $this->selected_vendor_id)
                ->whereHas('rfq', function($query) {
                    $query->where('mrf_id', $this->id);
                })
                ->where('status', 'Approved')
                ->orderBy('created_at', 'desc')
                ->first();
        }
        return null;
    }

    /**
     * Get user who approved the invoice
     */
    public function invoiceApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoice_approved_by');
    }

    /**
     * Generate MRF ID with contract type prefix
     * Format: MRF-{CONTRACT_TYPE}-{YEAR}-{NUMBER}
     * Example: MRF-EMERALD-2026-001, MRF-OANDO-2026-001
     */
    public static function generateMRFId(?string $contractType = null): string
    {
        $year = date('Y');
        $contractPrefix = $contractType ? strtoupper($contractType) : 'EMERALD'; // Default to EMERALD if not provided

        // Build pattern to find last MRF for this contract type and year
        $pattern = "MRF-{$contractPrefix}-{$year}-%";
        $lastMRF = self::where('mrf_id', 'like', $pattern)
            ->orderBy('mrf_id', 'desc')
            ->first();

        if ($lastMRF) {
            // Extract number from MRF ID (last 3 digits)
            $lastNumber = (int) substr($lastMRF->mrf_id, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "MRF-{$contractPrefix}-{$year}-{$newNumber}";
    }

    /**
     * Check whether this MRF uses Emerald contract workflow.
     */
    public function isEmeraldContract(): bool
    {
        return strtolower(trim((string) $this->contract_type)) === 'emerald';
    }
}
