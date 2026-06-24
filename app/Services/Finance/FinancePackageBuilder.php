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

        if ($schedule) {
            $this->paymentScheduleService->recalculateAmounts($schedule, $poTotal);
            $schedule->load('milestones');
        }

        return [
            'packageVersion' => $this->nextPackageVersion($mrf),
            'deltaReason' => $deltaReason,
            'header' => $this->buildHeader($mrf, $vendor, $poTotal),
            'paymentSchedule' => $schedule ? $this->buildPaymentScheduleSection($schedule) : null,
            'lineItems' => $this->buildLineItems($mrf),
            'approvalsSummary' => $this->buildApprovalsSummary($mrf),
            'documentManifest' => $this->buildDocumentManifest($mrf),
            'context' => [
                'requestedMilestoneId' => $requestedMilestoneId,
                'workflowState' => $mrf->workflow_state,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeader(MRF $mrf, ?Vendor $vendor, float $poTotal): array
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
            'requester' => [
                'name' => $mrf->requester_name ?? $mrf->requester?->name,
                'department' => $mrf->department,
            ],
            'vendor' => $vendor ? $this->vendorSnapshotBuilder->toArray($vendor) : null,
        ];
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
        $vendorId = $this->documentService->resolveVendorId($mrf);

        return ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('is_active', true)
            ->when($vendorId, fn ($q) => $q->where(function ($query) use ($vendorId) {
                $query->whereNull('vendor_id')->orWhere('vendor_id', $vendorId);
            }))
            ->orderBy('type')
            ->get()
            ->map(fn (ProcurementDocument $doc) => [
                'documentId' => (string) $doc->id,
                'type' => $doc->type,
                'fileName' => $doc->file_name,
                'fileUrl' => $doc->file_url,
                'version' => (int) $doc->version,
                'uploadedAt' => $doc->uploaded_at?->toIso8601String(),
                'sha256' => $this->documentChecksum($doc),
            ])
            ->values()
            ->all();
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
