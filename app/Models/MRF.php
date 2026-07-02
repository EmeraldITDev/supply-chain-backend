<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use App\Support\PurchaseOrderCurrency;

class MRF extends Model
{
    protected $table = 'm_r_f_s';

    protected static function booted(): void
    {
        static::creating(function (MRF $mrf) {
            if (empty($mrf->scm_transaction_id)) {
                $mrf->scm_transaction_id = (string) Str::uuid();
            }
        });

        static::updating(function (MRF $mrf) {
            if ($mrf->isDirty('scm_transaction_id')) {
                $mrf->scm_transaction_id = $mrf->getOriginal('scm_transaction_id');
            }
        });
    }

    /**
     * Cross-system identifier fields for API responses.
     *
     * @return array{scmTransactionId: ?string, scm_transaction_id: ?string}
     */
    public function scmTransactionApiFields(): array
    {
        return array_merge([
            'scmTransactionId' => $this->scm_transaction_id,
            'scm_transaction_id' => $this->scm_transaction_id,
        ], $this->poOriginApiFields(), $this->poDraftApiFields());
    }

    public function isPoDraft(): bool
    {
        return $this->po_draft_saved_at !== null
            && trim((string) ($this->unsigned_po_url ?? '')) === '';
    }

    /**
     * PO list rows: finalised POs (po_number) and in-progress drafts (po_draft_saved_at, no PDF yet).
     */
    public function scopeForPoList($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($inner) {
                $inner->whereNotNull('po_number')->where('po_number', '!=', '');
            })->orWhere(function ($inner) {
                $inner->whereNotNull('po_draft_saved_at')
                    ->where(function ($unsigned) {
                        $unsigned->whereNull('unsigned_po_url')
                            ->orWhere('unsigned_po_url', '=', '');
                    });
            });
        });
    }

    /**
     * PO tab status buckets (draft / pending / signed / rejected / completed).
     */
    public function scopeWithPoLifecycleStatus($query, string $status)
    {
        $bucket = strtolower(trim($status));
        if ($bucket === '' || $bucket === 'all') {
            return $query;
        }

        return match ($bucket) {
            'draft' => $query->whereNotNull('po_draft_saved_at')
                ->where(function ($unsigned) {
                    $unsigned->whereNull('unsigned_po_url')
                        ->orWhere('unsigned_po_url', '=', '');
                }),
            'signed' => $query->whereNotNull('signed_po_url')->where('signed_po_url', '!=', ''),
            'rejected' => $query->where(function ($q) {
                $q->whereRaw('LOWER(COALESCE(status, "")) LIKE ?', ['%reject%'])
                    ->orWhereRaw('LOWER(COALESCE(workflow_state, "")) LIKE ?', ['%reject%'])
                    ->orWhereRaw('LOWER(COALESCE(current_stage, "")) LIKE ?', ['%reject%'])
                    ->orWhereRaw('LOWER(COALESCE(rejection_reason, "")) != ?', ['']);
            }),
            'completed' => $query->where(function ($q) {
                $q->where('grn_completed', true)
                    ->orWhereRaw('LOWER(COALESCE(status, "")) LIKE ?', ['%complete%'])
                    ->orWhereRaw('LOWER(COALESCE(workflow_state, "")) LIKE ?', ['%complete%']);
            }),
            'pending' => $query->whereNotNull('unsigned_po_url')
                ->where('unsigned_po_url', '!=', '')
                ->where(function ($signed) {
                    $signed->whereNull('signed_po_url')->orWhere('signed_po_url', '=', '');
                }),
            default => $query,
        };
    }

    /**
     * Draft PO badge fields for list/detail responses.
     *
     * @return array{is_po_draft: bool, isPoDraft: bool, po_draft_saved_at: ?string, poDraftSavedAt: ?string}
     */
    public function poDraftApiFields(): array
    {
        $isDraft = $this->isPoDraft();
        $savedAt = $this->po_draft_saved_at?->toIso8601String();

        return [
            'is_po_draft' => $isDraft,
            'isPoDraft' => $isDraft,
            'po_draft_saved_at' => $savedAt,
            'poDraftSavedAt' => $savedAt,
        ];
    }

    /**
     * Flags for PO-generated / manual-PO MRFs used by frontend list filters.
     *
     * @return array{source: string, is_po_linked: bool, isPoLinked: bool, linked_po_id: ?string, linkedPoId: ?string}
     */
    public function poOriginApiFields(): array
    {
        $source = $this->source ?? 'standard';
        if (! in_array($source, ['standard', 'po_generated'], true)) {
            $source = 'standard';
        }

        $isPoLinked = (bool) ($this->is_po_linked ?? false);
        $linkedPoId = filled($this->linked_po_id) ? (string) $this->linked_po_id : null;

        return [
            'source' => $source,
            'is_po_linked' => $isPoLinked,
            'isPoLinked' => $isPoLinked,
            'linked_po_id' => $linkedPoId,
            'linkedPoId' => $linkedPoId,
        ];
    }

    /**
     * ISO 4217 currency for PO line amounts (NGN default).
     *
     * @return array{currency: string}
     */
    public function currencyApiFields(): array
    {
        return [
            'currency' => PurchaseOrderCurrency::normalize($this->currency),
        ];
    }

    /**
     * Detect manual-PO quick-start payloads when explicit flags are omitted.
     */
    public static function inferPoGeneratedFromJustification(?string $justification): bool
    {
        $text = strtolower(trim((string) $justification));

        return $text !== ''
            && (
                str_contains($text, 'manual po created without rfq')
                || str_contains($text, 'vendor and pricing captured directly on the purchase order')
            );
    }

    protected $fillable = [
        'mrf_id',
        'formatted_id',
        'title',
        'category',
        'contract_type',
        'routed_reason',
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
        'first_approval_by_role',
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
        'po_draft_saved_at',
        // PO Details
        'ship_to_address',
        'tax_rate',
        'tax_amount',
        'po_special_terms',
        'invoice_submission_email',
        'invoice_submission_cc',
        'custom_terms',
        'po_terms_mode',
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
        'procurement_manager_id',
        'invoice_url',
        'invoice_share_url',
        'invoice_approved_by',
        'invoice_approved_at',
        'invoice_remarks',
        'expected_delivery_date',
        'scm_transaction_id',
        'source',
        'is_po_linked',
        'linked_po_id',
        'finance_ap_case_id',
        'finance_ap_status',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'date' => 'date',
        'is_resubmission' => 'boolean',
        'is_po_linked' => 'boolean',
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
        'po_draft_saved_at' => 'datetime',
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
     * User linked from director_approved_by when that column stores a user id.
     * If the column stores a plain name string, this relation resolves to null
     * and API consumers fall back to the raw string.
     */
    public function directorApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'director_approved_by');
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

    public function procurementManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'procurement_manager_id');
    }

    public function priceComparisons(): HasMany
    {
        return $this->hasMany(PriceComparison::class, 'purchase_order_id');
    }

    public function procurementDocuments(): HasMany
    {
        return $this->hasMany(ProcurementDocument::class, 'mrf_id');
    }

    public function paymentSchedule(): HasOne
    {
        return $this->hasOne(PaymentSchedule::class, 'mrf_id');
    }

    /**
     * If procurement never used the price-comparison API, build rows from
     * RFQ quotations (one per vendor, latest quote) so vendor selection can proceed.
     */
    public function syncPriceComparisonsFromQuotations(): int
    {
        if ($this->priceComparisons()->exists()) {
            return $this->priceComparisons()->count();
        }

        $quotations = $this->quotations()
            ->with(['vendor', 'items'])
            ->orderByDesc('created_at')
            ->get()
            ->unique('vendor_id')
            ->values();

        $created = 0;
        foreach ($quotations as $quotation) {
            if (empty($quotation->vendor_id)) {
                continue;
            }

            $total = (float) ($quotation->total_amount ?? $quotation->price ?? 0);
            if ($total <= 0 && $quotation->relationLoaded('items')) {
                $total = (float) $quotation->items->sum('total_price');
            } elseif ($total <= 0) {
                $total = (float) $quotation->items()->sum('total_price');
            }
            if ($total <= 0) {
                continue;
            }

            $label = trim((string) ($this->title ?: 'RFQ quotation'));
            $vendorName = trim((string) ($quotation->vendor?->name ?? ''));

            PriceComparison::create([
                'purchase_order_id' => $this->id,
                'vendor_id' => (int) $quotation->vendor_id,
                'item_description' => $vendorName !== ''
                    ? "{$label} ({$vendorName})"
                    : $label,
                'unit_price' => $total,
                'quantity' => 1,
                'total_price' => $total,
                'is_selected' => false,
                'selection_reason' => null,
            ]);
            $created++;
        }

        return $created;
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
     * Columns loaded for paginated list endpoints (avoids large text / JSON blobs).
     *
     * @var list<string>
     */
    public const LIST_API_SELECT = [
        'id', 'mrf_id', 'formatted_id', 'scm_transaction_id', 'title', 'category', 'contract_type',
        'urgency', 'quantity', 'estimated_cost', 'currency', 'requester_id', 'requester_name', 'department',
        'date', 'created_at', 'updated_at', 'status', 'current_stage', 'workflow_state', 'first_approval_by_role', 'rejection_reason',
        'is_resubmission', 'pfi_url', 'pfi_share_url', 'attachment_url', 'attachment_share_url',
        'attachment_name', 'grn_requested', 'grn_requested_at', 'grn_completed', 'grn_completed_at',
        'grn_url', 'grn_share_url', 'executive_approved', 'executive_approved_at', 'executive_remarks',
        'director_approved_by', 'scd_approved_by', 'supply_chain_approved_by',
        'scd_approved_at', 'director_approved_at', 'supply_chain_approved_at',
        'scd_remarks', 'director_remarks', 'supply_chain_remarks', 'remarks',
        'chairman_approved', 'chairman_approved_at', 'chairman_remarks',
        'po_number', 'unsigned_po_url', 'unsigned_po_share_url', 'signed_po_url', 'signed_po_share_url',
        'po_generated_at', 'po_terms_mode', 'source', 'is_po_linked', 'linked_po_id', 'po_draft_saved_at',
    ];

    /**
     * LIST_API_SELECT filtered to columns that exist in the current database schema.
     * Prevents list endpoints from 500ing (or returning fake empty lists) when
     * production code is ahead of pending migrations.
     *
     * @return list<string>
     */
    public static function resolveListApiSelect(): array
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        if (! Schema::hasTable('m_r_f_s')) {
            $resolved = self::LIST_API_SELECT;

            return $resolved;
        }

        $resolved = array_values(array_filter(
            self::LIST_API_SELECT,
            static fn (string $column): bool => Schema::hasColumn('m_r_f_s', $column),
        ));

        $missing = array_values(array_diff(self::LIST_API_SELECT, $resolved));
        if ($missing !== []) {
            Log::warning('MRF list select omits missing columns', ['columns' => $missing]);
        }

        return $resolved;
    }

    /**
     * Slim API payload for GET /api/mrfs list rows (detail endpoint has full fields).
     *
     * @return array<string, mixed>
     */
    public function toListApiArray(): array
    {
        return array_merge(
            $this->scmTransactionApiFields(),
            [
                'id' => $this->mrf_id,
                'formattedId' => $this->formatted_id,
                'formatted_id' => $this->formatted_id,
                'legacyId' => $this->mrf_id,
                'legacy_id' => $this->mrf_id,
                'title' => $this->title,
                'category' => $this->category,
                'contractType' => $this->contract_type,
                'urgency' => $this->urgency,
                'quantity' => $this->quantity,
                'estimatedCost' => $this->estimated_cost !== null ? (float) $this->estimated_cost : null,
                ...$this->currencyApiFields(),
                'requester' => $this->requester_name,
                'requesterId' => (string) $this->requester_id,
                'department' => $this->department,
                'date' => $this->date ? $this->date->format('Y-m-d') : null,
                'createdAt' => $this->created_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updatedAt' => $this->updated_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
                'status' => $this->status,
                'currentStage' => $this->current_stage,
                'workflowState' => $this->workflow_state,
                ...app(\App\Services\MrfParallelFirstApprovalService::class)->apiFields($this),
                'approvalHistory' => [],
                'rejectionReason' => $this->rejection_reason,
                'isResubmission' => $this->is_resubmission,
                'pfiUrl' => $this->pfi_url,
                'pfiShareUrl' => $this->pfi_share_url,
                'attachmentUrl' => $this->attachment_url,
                'attachmentShareUrl' => $this->attachment_share_url,
                'attachment_url' => $this->attachment_url,
                'attachment_share_url' => $this->attachment_share_url,
                'attachmentName' => $this->attachment_name,
                'attachment_name' => $this->attachment_name,
                'grnRequested' => $this->grn_requested,
                'grnRequestedAt' => $this->grn_requested_at?->toIso8601String(),
                'grnCompleted' => $this->grn_completed,
                'grnCompletedAt' => $this->grn_completed_at?->toIso8601String(),
                'grnUrl' => $this->grn_url,
                'grnShareUrl' => $this->grn_share_url,
                'executive_approved' => $this->executive_approved ?? false,
                'executive_approved_at' => $this->executive_approved_at?->toIso8601String(),
                'executive_remarks' => $this->executive_remarks,
                'scd_approved_by' => $this->director_approved_by ?? $this->scd_approved_by ?? $this->supply_chain_approved_by ?? null,
                'scd_approved_at' => ($this->scd_approved_at ?? $this->director_approved_at ?? $this->supply_chain_approved_at)?->toIso8601String(),
                'scd_remarks' => $this->scd_remarks ?? $this->director_remarks ?? $this->supply_chain_remarks ?? $this->remarks ?? null,
                'chairman_approved' => $this->chairman_approved ?? false,
                'chairman_approved_at' => $this->chairman_approved_at?->toIso8601String(),
                'chairman_remarks' => $this->chairman_remarks,
                'po_number' => $this->po_number,
                'poNumber' => $this->po_number,
                'unsigned_po_url' => $this->unsigned_po_url,
                'unsignedPOUrl' => $this->unsigned_po_url,
                'unsigned_po_share_url' => $this->unsigned_po_share_url,
                'unsignedPOShareUrl' => $this->unsigned_po_share_url,
                'signed_po_url' => $this->signed_po_url,
                'signedPOUrl' => $this->signed_po_url,
                'po_generated_at' => $this->po_generated_at?->toIso8601String(),
                'poGeneratedAt' => $this->po_generated_at?->toIso8601String(),
                'po_terms_mode' => $this->po_terms_mode,
                'poTermsMode' => $this->po_terms_mode,
                'priceComparisons' => [],
            ],
            $this->poOriginApiFields(),
            $this->poDraftApiFields(),
        );
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

    /**
     * Time-limited URL that streams the unsigned PO PDF rendered from current data (not the S3 snapshot).
     * Use this for unsignedPoUrl in API responses so the layout always matches the latest template.
     */
    public function freshUnsignedPoStreamUrl(): ?string
    {
        if (empty($this->po_number) || empty($this->unsigned_po_url)) {
            return null;
        }

        try {
            return URL::temporarySignedRoute(
                'mrfs.po-signed-download',
                now()->addMinutes((int) env('PO_SIGNED_URL_TTL', 120)),
                ['id' => $this->mrf_id]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to build signed PO stream URL', [
                'mrf_id' => $this->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
