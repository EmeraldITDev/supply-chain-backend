<?php

namespace App\Services\Finance;

use App\Models\FinanceSyncEvent;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\PaymentMilestone;
use App\Models\PaymentSchedule;
use App\Models\ProcurementDocument;
use App\Models\Vendor;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use Illuminate\Support\Facades\Storage;
use App\Support\PoTaxCalculator;

class FinancePackageBuilder
{
    private const ROLE_LABELS = [
        'supply_chain' => 'Supply Chain Director',
        'supply_chain_director' => 'Supply Chain Director',
        'procurement' => 'Procurement Manager',
        'procurement_manager' => 'Procurement Manager',
        'executive' => 'Executive',
        'executive_review' => 'Executive',
        'chairman' => 'Chairman',
        'chairman_review' => 'Chairman',
        'chairman_payment' => 'Chairman',
        'finance' => 'Finance',
        'employee' => 'Employee',
        'admin' => 'Administrator',
    ];

    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
        private ProcurementDocumentService $documentService,
        private FinanceApVendorSnapshotBuilder $vendorSnapshotBuilder,
        private FinanceApPaymentTypeMapper $paymentTypeMapper,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(MRF $mrf, ?int $requestedMilestoneId = null, ?string $deltaReason = null): array
    {
        $mrf->loadMissing([
            'requester:id,name,email,department',
            'selectedVendor',
            'items',
            'paymentSchedule.milestones',
        ]);

        $schedule = $mrf->paymentSchedule;
        $vendor = $this->vendorSnapshotBuilder->resolveForMrf($mrf);
        $poTotal = $this->resolvePoTotal($mrf, $schedule);
        $documentManifest = $this->buildDocumentManifest($mrf);
        $paymentTerms = $this->resolvePaymentTerms($mrf, $schedule);
        $paymentType = $this->paymentTypeMapper->map($paymentTerms, $schedule, $documentManifest);

        if ($schedule) {
            $this->paymentScheduleService->recalculateAmounts($schedule, $poTotal);
            $schedule->load('milestones');
        }

        return [
            'packageVersion' => $this->nextPackageVersion($mrf),
            'deltaReason' => $deltaReason,
            'header' => $this->buildHeader($mrf, $vendor, $poTotal, $paymentTerms, $paymentType),
            'paymentSchedule' => $schedule ? $this->buildPaymentScheduleSection($schedule) : null,
            'lineItems' => $this->buildLineItems($mrf),
            'approvalsSummary' => $this->buildApprovalsSummary($mrf),
            'documentManifest' => $documentManifest,
            'context' => [
                'requestedMilestoneId' => $requestedMilestoneId,
                'workflowState' => $mrf->workflow_state,
                'paymentType' => $paymentType,
                'payment_type' => $paymentType,
                'paymentTerms' => $paymentTerms,
                'payment_terms' => $paymentTerms,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeader(MRF $mrf, ?Vendor $vendor, float $poTotal, ?string $paymentTerms, string $paymentType): array
    {
        return [
            'scmTransactionId' => $mrf->scm_transaction_id,
            'mrfId' => $mrf->mrf_id,
            'formattedId' => $mrf->formatted_id,
            'scmPoNumber' => $mrf->po_number,
            'title' => $mrf->title,
            'contractType' => $mrf->contract_type,
            'department' => $mrf->department,
            'currency' => $mrf->currency ?? 'NGN',
            'poTotal' => $poTotal,
            'estimatedCost' => (float) ($mrf->estimated_cost ?? 0),
            'paymentTerms' => $paymentTerms,
            'payment_terms' => $paymentTerms,
            'paymentType' => $paymentType,
            'payment_type' => $paymentType,
            'requester' => [
                'name' => $mrf->requester_name ?? $mrf->requester?->name,
                'department' => $mrf->department,
            ],
            'vendor' => $vendor ? $this->vendorSnapshotBuilder->toArray($vendor) : null,
        ];
    }

    private function resolvePaymentTerms(MRF $mrf, ?PaymentSchedule $schedule): ?string
    {
        $quotation = $mrf->selectedQuotation();

        if ($quotation?->payment_terms) {
            return (string) $quotation->payment_terms;
        }

        if ($schedule?->template_name) {
            $template = config('payment_term_templates.'.$schedule->template_name);

            if (is_array($template) && ! empty($template['name'])) {
                return (string) $template['name'];
            }

            return (string) $schedule->template_name;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPaymentScheduleSection(PaymentSchedule $schedule): array
    {
        return [
            'paymentSchedule' => $this->paymentScheduleService->toApiArray($schedule),
            'milestones' => $schedule->milestones->map(fn (PaymentMilestone $m) => [
                'scmMilestoneId' => (string) $m->id,
                'milestoneNumber' => $m->milestone_number,
                'label' => $m->label,
                'percentage' => (float) $m->percentage,
                'amount' => $m->amount !== null ? (float) $m->amount : null,
                'triggerCondition' => $m->trigger_condition,
                'requiredDocuments' => $m->required_documents ?? [],
                'status' => $m->status,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(MRF $mrf): array
    {
        return $mrf->items->map(fn ($item) => [
            'description' => $item->item_name ?? $item->description,
            'quantity' => (float) ($item->quantity ?? 0),
            'unit' => $item->unit,
            'unitPrice' => (float) ($item->unit_price ?? 0),
            'total' => (float) ($item->quantity ?? 0) * (float) ($item->unit_price ?? 0),
        ])->values()->all();
    }

    /**
     * Role labels only — no individual names or emails.
     *
     * @return list<array<string, mixed>>
     */
    private function buildApprovalsSummary(MRF $mrf): array
    {
        return MRFApprovalHistory::query()
            ->where('mrf_id', $mrf->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (MRFApprovalHistory $row) => [
                'stage' => $row->stage,
                'action' => $row->action,
                'status' => 'completed',
                'roleLabel' => $this->roleLabel($row->stage, $row->performer_role),
                'timestamp' => $row->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function roleLabel(string $stage, ?string $performerRole): string
    {
        if ($performerRole && isset(self::ROLE_LABELS[$performerRole])) {
            return self::ROLE_LABELS[$performerRole];
        }

        if (isset(self::ROLE_LABELS[$stage])) {
            return self::ROLE_LABELS[$stage];
        }

        return ucwords(str_replace('_', ' ', $performerRole ?: $stage));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDocumentManifest(MRF $mrf): array
    {
        $documents = $this->activeDocuments($mrf);
        $availability = $this->documentAvailability($documents);
        $primaryPoId = $this->primaryPurchaseOrderId($documents, $availability);

        return $documents
            ->map(function (ProcurementDocument $doc) use ($availability, $primaryPoId) {
                $isAvailable = $availability[$doc->id];

                return [
                    'documentId' => (string) $doc->id,
                    'type' => $this->mapFinanceApDocumentType($doc->type),
                    'scmDocumentType' => $doc->type,
                    'fileName' => $doc->file_name,
                    'fileUrl' => $isAvailable
                        ? $this->documentService->freshAuthorizedUrlForDocument($doc)
                        : null,
                    'version' => (int) $doc->version,
                    'uploadedAt' => $doc->uploaded_at?->toIso8601String(),
                    'isLatest' => true,
                    'available' => $isAvailable,
                    'isPrimary' => $primaryPoId === $doc->id,
                    'poNumber' => $mrf->po_number,
                    'remarks' => $doc->metadata['remarks'] ?? null,
                    ...$this->invoiceClassification($doc),
                    'sha256' => $this->documentChecksum($doc),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The MRF's documents as Finance AP lists them for a PO, without download links:
     * Finance AP fetches a file through the document refresh endpoint when it is used.
     *
     * @return list<array<string, mixed>>
     */
    public function buildDocumentList(MRF $mrf): array
    {
        $documents = $this->activeDocuments($mrf);
        $availability = $this->documentAvailability($documents);
        $primaryPoId = $this->primaryPurchaseOrderId($documents, $availability);

        return $documents
            ->map(fn (ProcurementDocument $doc) => [
                'documentId' => (string) $doc->id,
                'type' => $this->mapFinanceApDocumentType($doc->type),
                'scmDocumentType' => $doc->type,
                'fileName' => $doc->file_name,
                'version' => (int) $doc->version,
                'uploadedAt' => $doc->uploaded_at?->toIso8601String(),
                'isLatest' => true,
                'available' => $availability[$doc->id],
                'isPrimary' => $primaryPoId === $doc->id,
                'poNumber' => $mrf->po_number,
                'remarks' => $doc->metadata['remarks'] ?? null,
                ...$this->invoiceClassification($doc),
            ])
            ->values()
            ->all();
    }

    /**
     * Active documents for the MRF and its selected vendor.
     *
     * @return \Illuminate\Support\Collection<int, ProcurementDocument>
     */
    private function activeDocuments(MRF $mrf)
    {
        $vendorId = $this->documentService->resolveVendorId($mrf);

        $documents = ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('is_active', true)
            ->where(function ($query) use ($vendorId) {
                $query->whereNull('vendor_id');
                if ($vendorId) {
                    $query->orWhere('vendor_id', $vendorId);
                }
            })
            ->orderBy('type')
            ->orderBy('version')
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get();

        return $documents
            ->groupBy('type')
            ->map(fn ($versions) => $versions->last())
            ->values()
            ->sortBy(fn (ProcurementDocument $doc) => match ($doc->type) {
                ProcurementDocument::TYPE_SIGNED_PO => 0,
                ProcurementDocument::TYPE_PO_PDF => 1,
                default => 2,
            })
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProcurementDocument>  $documents
     * @return array<int, bool>
     */
    private function documentAvailability($documents): array
    {
        return $documents
            ->mapWithKeys(fn (ProcurementDocument $doc) => [
                $doc->id => $this->documentService->isAvailableForFinanceAp($doc),
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProcurementDocument>  $documents
     * @param  array<int, bool>  $availability
     */
    private function primaryPurchaseOrderId($documents, array $availability): ?int
    {
        $primary = $documents->first(fn (ProcurementDocument $doc) =>
            in_array($doc->type, [
                ProcurementDocument::TYPE_SIGNED_PO,
                ProcurementDocument::TYPE_PO_PDF,
            ], true)
            && ($availability[$doc->id] ?? false)
        );

        return $primary?->id;
    }

    /**
     * @return array{invoiceStatus?: string, isSubmitted?: bool, isVerified?: bool}
     */
    private function invoiceClassification(ProcurementDocument $document): array
    {
        return match ($document->type) {
            ProcurementDocument::TYPE_VENDOR_INVOICE => [
                'invoiceStatus' => 'submitted',
                'isSubmitted' => true,
                'isVerified' => false,
            ],
            ProcurementDocument::TYPE_SUPPORTING_VENDOR_INVOICE => [
                'invoiceStatus' => 'supporting',
                'isSubmitted' => false,
                'isVerified' => false,
            ],
            default => [],
        };
    }

    public function mapFinanceApDocumentType(string $scmType): string
    {
        return match ($scmType) {
            ProcurementDocument::TYPE_SIGNED_PO,
            ProcurementDocument::TYPE_PO_PDF => 'purchase_order',
            ProcurementDocument::TYPE_VENDOR_INVOICE => 'invoice',
            ProcurementDocument::TYPE_SUPPORTING_VENDOR_INVOICE => 'supporting_invoice',
            ProcurementDocument::TYPE_PFI => 'pfi',
            ProcurementDocument::TYPE_GRN => 'grn',
            ProcurementDocument::TYPE_WAYBILL => 'waybill',
            ProcurementDocument::TYPE_DELIVERY_CONFIRMATION => 'delivery_note',
            default => $scmType,
        };
    }

    private function documentChecksum(ProcurementDocument $document): ?string
    {
        $path = $document->file_path;

        if (! $path || str_starts_with($path, 'http')) {
            return null;
        }

        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));

        try {
            if (Storage::disk($disk)->exists($path)) {
                return hash('sha256', Storage::disk($disk)->get($path));
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function resolvePoTotal(MRF $mrf, ?PaymentSchedule $schedule): float
    {
        // Per-line VAT POs store the VAT-inclusive total in po_value. Legacy POs keep
        // the original (pre-tax quotation) resolution below.
        if (PoTaxCalculator::isPerLine($mrf->tax_mode) && (float) ($mrf->po_value ?? 0) > 0) {
            return (float) $mrf->po_value;
        }

        $quotation = $mrf->selectedQuotation();

        if ($quotation && (float) $quotation->total_amount > 0) {
            return (float) $quotation->total_amount;
        }

        if ($schedule && $schedule->milestones->isNotEmpty()) {
            return (float) $schedule->milestones->sum(fn (PaymentMilestone $m) => (float) ($m->amount ?? 0));
        }

        return (float) ($mrf->estimated_cost ?? 0);
    }

    private function nextPackageVersion(MRF $mrf): int
    {
        return (int) FinanceSyncEvent::query()
            ->where('mrf_id', $mrf->id)
            ->where('event_type', 'package_push')
            ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
            ->count() + 1;
    }
}
