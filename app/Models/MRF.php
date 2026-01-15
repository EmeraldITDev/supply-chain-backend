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
        'currency',
        'justification',
        'requester_id',
        'requester_name',
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
        'remarks',
        // PFI (Proforma Invoice)
        'pfi_url',
        'pfi_share_url',
        // Executive approval
        'executive_approved',
        'executive_approved_by',
        'executive_approved_at',
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
        // Payment
        'payment_status',
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
        'rejected_at' => 'datetime',
        'po_generated_at' => 'datetime',
        'po_signed_at' => 'datetime',
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
    public function rfqs(): HasMany
    {
        return $this->hasMany(RFQ::class, 'mrf_id');
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
     * Get user who approved the invoice
     */
    public function invoiceApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoice_approved_by');
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
