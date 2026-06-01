<?php

namespace App\Services\FinanceAp;

use App\Models\FinanceSyncEvent;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\PaymentMilestone;
use App\Models\ProcurementDocument;
use App\Services\Finance\FinanceRoutingService;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Collection;

class MrfProgressTrackerService
{
    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
        private ProcurementDocumentService $documentService,
        private DeliveryConfirmationService $deliveryConfirmationService,
        private FinanceRoutingService $financeRouting,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(MRF $mrf): array
    {
        $mrf->loadMissing(['requester', 'selectedVendor', 'rfqs', 'approvalHistory']);

        $documents = $this->documentService->listGroupedForMrf($mrf);
        $activeByType = $documents['activeByType'];
        $schedule = $this->paymentScheduleService->findForMrf($mrf);
        $schedulePayload = $schedule ? $this->paymentScheduleService->toApiArray($schedule) : null;

        $hideDeliveryPhase = $this->shouldHideDeliveryPhase($schedule);
        $usesFinanceAp = mrfUsesFinanceAp($mrf);
        $stageTimestamps = $this->buildStageTimestamps($mrf, $activeByType);

        $phases = $this->buildPhases($mrf, $activeByType, $schedule, $hideDeliveryPhase, $usesFinanceAp, $stageTimestamps);
        $steps = collect($phases)->flatMap(fn (array $phase) => $phase['steps'])->values()->all();

        $completedCount = collect($steps)->where('status', 'completed')->count();
        $totalCount = count($steps);

        $currentStep = collect($steps)->firstWhere('status', 'pending')['step']
            ?? collect($steps)->where('status', 'completed')->last()['step']
            ?? 1;

        return [
            'mrfId' => $mrf->mrf_id,
            'formattedId' => $mrf->formatted_id,
            'title' => $mrf->title,
            'currentWorkflowState' => $mrf->workflow_state,
            'usesFinanceAp' => $usesFinanceAp,
            'financeRoute' => $this->financeRouting->financeRoute($mrf),
            'currentStep' => $currentStep,
            'meta' => [
                'hideDeliveryPhase' => $hideDeliveryPhase,
                'isHundredPercentAdvanceOnly' => $hideDeliveryPhase,
                'totalSteps' => $totalCount,
                'completedSteps' => $completedCount,
                'progressPercent' => $totalCount > 0 ? (int) round(($completedCount / $totalCount) * 100) : 0,
            ],
            'stageTimestamps' => $stageTimestamps,
            'paymentSchedule' => $schedulePayload,
            'documentsByType' => $documents['documentsByType'],
            'activeByType' => $activeByType,
            'phases' => $phases,
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     * @return array<string, ?string>
     */
    private function buildStageTimestamps(MRF $mrf, array $activeByType): array
    {
        $vendorInvoice = $activeByType[ProcurementDocument::TYPE_VENDOR_INVOICE] ?? null;
        $grn = $activeByType[ProcurementDocument::TYPE_GRN] ?? null;

        $deliveryUploadedAt = $this->latestDocumentTimestamp($activeByType, [
            ProcurementDocument::TYPE_WAYBILL,
            ProcurementDocument::TYPE_JCC,
            ProcurementDocument::TYPE_DELIVERY_CONFIRMATION,
        ]);

        return [
            'mrf_created_at' => $mrf->created_at?->toIso8601String(),
            'initial_approval_at' => $this->historyTimestamp($mrf, ['approved'], ['supply_chain', 'executive_review', 'chairman_review'])
                ?? $mrf->director_approved_at?->toIso8601String()
                ?? $mrf->executive_approved_at?->toIso8601String(),
            'procurement_review_at' => $this->historyTimestamp($mrf, ['approved', 'vendor_selected'], ['procurement']),
            'rfq_issued_at' => $mrf->rfqs->sortBy('created_at')->first()?->created_at?->toIso8601String(),
            'quotes_received_at' => $this->firstQuotationReceivedAt($mrf),
            'vendor_selection_approved_at' => $this->historyTimestamp($mrf, ['vendor_approved', 'approved'], ['supply_chain'])
                ?? ($mrf->workflow_state && in_array($mrf->workflow_state, ['invoice_approved', 'po_generated', 'po_signed'], true)
                    ? $mrf->updated_at?->toIso8601String() : null),
            'vendor_invoice_submitted_at' => $vendorInvoice['uploadedAt'] ?? $vendorInvoice['uploaded_at'] ?? null,
            'po_generated_at' => $mrf->po_generated_at?->toIso8601String(),
            'po_signed_at' => $mrf->po_signed_at?->toIso8601String()
                ?? $this->historyTimestamp($mrf, ['signed_po'], ['supply_chain']),
            'grn_generated_at' => $grn['uploadedAt'] ?? $grn['uploaded_at'] ?? $mrf->grn_completed_at?->toIso8601String(),
            'delivery_docs_uploaded_at' => $deliveryUploadedAt,
            'finance_reviewed_at' => $this->resolveFinanceReviewedAt($mrf),
            'payment_completed_at' => $this->resolvePaymentCompletedAt($mrf),
            'closed_at' => $mrf->workflow_state === WorkflowStateService::STATE_CLOSED
                ? ($mrf->updated_at?->toIso8601String()) : null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     * @param  list<string>  $types
     */
    private function latestDocumentTimestamp(array $activeByType, array $types): ?string
    {
        $latest = null;

        foreach ($types as $type) {
            $doc = $activeByType[$type] ?? null;
            if (! $doc) {
                continue;
            }
            $at = $doc['uploadedAt'] ?? $doc['uploaded_at'] ?? null;
            if ($at && ($latest === null || $at > $latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    private function resolveFinanceReviewedAt(MRF $mrf): ?string
    {
        if (! mrfUsesFinanceAp($mrf)) {
            if (in_array($mrf->status, ['finance', 'chairman_payment', 'completed'], true)) {
                return $this->historyTimestamp($mrf, ['payment_processed'], ['finance'])
                    ?? $mrf->payment_processed_at?->toIso8601String();
            }

            return null;
        }

        $approvedAt = FinanceSyncEvent::query()
            ->where('mrf_id', $mrf->id)
            ->where('direction', FinanceSyncEvent::DIRECTION_INBOUND)
            ->where('event_type', 'approved')
            ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
            ->orderBy('processed_at')
            ->value('processed_at');

        if ($approvedAt) {
            return $this->normalizeTimestamp($approvedAt);
        }

        if ($mrf->finance_ap_status && ! in_array($mrf->finance_ap_status, ['pending_review', 'pending'], true)) {
            return $this->normalizeTimestamp(
                FinanceSyncEvent::query()
                    ->where('mrf_id', $mrf->id)
                    ->where('direction', FinanceSyncEvent::DIRECTION_INBOUND)
                    ->whereIn('event_type', ['milestone_payment_approved', 'payment_posted'])
                    ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
                    ->orderBy('processed_at')
                    ->value('processed_at')
            );
        }

        return null;
    }

    private function resolvePaymentCompletedAt(MRF $mrf): ?string
    {
        if (mrfUsesFinanceAp($mrf)) {
            if (in_array($mrf->workflow_state, [
                WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
                WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
                WorkflowStateService::STATE_CLOSED,
            ], true)) {
                return FinanceSyncEvent::query()
                    ->where('mrf_id', $mrf->id)
                    ->where('direction', FinanceSyncEvent::DIRECTION_INBOUND)
                    ->where('event_type', 'payment_posted')
                    ->where('status', FinanceSyncEvent::STATUS_SUCCESS)
                    ->orderByDesc('processed_at')
                    ->value('processed_at')
                    ?? $mrf->updated_at?->toIso8601String();
            }

            return null;
        }

        return $mrf->payment_status === 'approved'
            ? ($this->historyTimestamp($mrf, ['payment_approved'], ['chairman_payment']) ?? $mrf->updated_at?->toIso8601String())
            : null;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     * @return list<array<string, mixed>>
     */
    private function buildPhases(
        MRF $mrf,
        array $activeByType,
        $schedule,
        bool $hideDeliveryPhase,
        bool $usesFinanceAp,
        array $stageTimestamps,
    ): array {
        $isEmerald = strtolower(trim((string) $mrf->contract_type)) === 'emerald';
        $hasRfqs = $mrf->rfqs->isNotEmpty();
        $quotationCount = $mrf->quotations()->count();

        $phases = [
            $this->phase('approval', 'Approval', [
                $this->step(1, 'mrf_created', 'MRF Created', $this->stepCompleted('completed'), $stageTimestamps['mrf_created_at'] ?? null, 'Employee submitted the Material Request Form', [
                    'completedBy' => $mrf->requester ? ['id' => $mrf->requester->id, 'name' => $mrf->requester->name] : null,
                ]),
                $this->step(2, 'initial_approval', 'Initial Approval', $this->initialApprovalStatus($mrf, $isEmerald), $stageTimestamps['initial_approval_at'] ?? null,
                    $isEmerald ? 'Executive first approval (Emerald contract)' : 'Supply Chain Director first approval'),
                $this->step(3, 'procurement_review', 'Procurement Review', $this->procurementReviewStatus($mrf, $hasRfqs), $stageTimestamps['procurement_review_at'] ?? null,
                    'Procurement reviews and sources vendor quotations'),
            ]),
            $this->phase('sourcing', 'Sourcing', [
                $this->step(4, 'rfq_issued', 'RFQ Issued', $hasRfqs ? 'completed' : ($mrf->workflow_state === 'rfq_issued' ? 'pending' : 'not_started'),
                    $stageTimestamps['rfq_issued_at'] ?? null, 'Requests for Quotation sent to vendors', ['rfqCount' => $mrf->rfqs->count()]),
                $this->step(5, 'quotes_received', 'Quotes Received',
                    $quotationCount > 0 ? 'completed' : ($hasRfqs ? 'pending' : 'not_started'),
                    $stageTimestamps['quotes_received_at'] ?? null, 'Vendors submit quotations', ['quotationCount' => $quotationCount]),
                $this->step(6, 'vendor_selection_approved', 'Vendor Selection Approved',
                    $this->vendorSelectionStatus($mrf), $stageTimestamps['vendor_selection_approved_at'] ?? null,
                    'Supply Chain Director approves selected vendor / quotation'),
            ]),
            $this->phase('procurement', 'Procurement', [
                $this->step(7, 'vendor_final_invoice', 'Vendor Final Invoice',
                    $this->documentStepStatus($activeByType[ProcurementDocument::TYPE_VENDOR_INVOICE] ?? null, $usesFinanceAp),
                    $stageTimestamps['vendor_invoice_submitted_at'] ?? null,
                    'Vendor submits final invoice when the submission gate is open',
                    ['documentType' => ProcurementDocument::TYPE_VENDOR_INVOICE, 'documentSatisfied' => (bool) ($activeByType[ProcurementDocument::TYPE_VENDOR_INVOICE] ?? null)]),
                $this->step(8, 'po_generated', 'PO Generated',
                    $mrf->po_number ? 'completed' : ($mrf->workflow_state === 'po_generated' ? 'pending' : 'not_started'),
                    $stageTimestamps['po_generated_at'] ?? null, 'Purchase order generated from selected quotation',
                    ['poNumber' => $mrf->po_number]),
                $this->step(9, 'po_signed', 'PO Signed by SCD',
                    $this->poSignedStatus($mrf), $stageTimestamps['po_signed_at'] ?? null,
                    'Signed purchase order registered on SCM'),
            ]),
        ];

        if (! $hideDeliveryPhase) {
            $phases[] = $this->phase('delivery', 'Delivery', [
                $this->step(10, 'grn_received', 'GRN / Goods Received',
                    $this->grnStepStatus($mrf, $activeByType), $stageTimestamps['grn_generated_at'] ?? null,
                    'Goods received note generated or uploaded',
                    ['documentType' => ProcurementDocument::TYPE_GRN, 'documentSatisfied' => $this->hasGrnDocument($mrf, $activeByType)]),
                $this->step(11, 'delivery_docs_uploaded', 'Delivery Documents Uploaded',
                    $this->deliveryDocsStepStatus($mrf, $activeByType, $schedule),
                    $stageTimestamps['delivery_docs_uploaded_at'] ?? null,
                    'Waybill, JCC, or delivery confirmation documents as required',
                    ['documentSatisfied' => $this->deliveryDocsSatisfied($activeByType)]),
            ]);
        }

        $paymentSteps = $this->buildPaymentPhaseSteps($mrf, $schedule, $usesFinanceAp, $stageTimestamps, $hideDeliveryPhase);
        $phases[] = $this->phase('payment', 'Payment', $paymentSteps);

        foreach ($phases as &$phase) {
            $phase['completedSteps'] = collect($phase['steps'])->where('status', 'completed')->count();
            $phase['totalSteps'] = count($phase['steps']);
        }

        return $phases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPaymentPhaseSteps(MRF $mrf, $schedule, bool $usesFinanceAp, array $stageTimestamps, bool $hideDeliveryPhase): array
    {
        $baseStep = $hideDeliveryPhase ? 10 : 12;

        $financeReviewComplete = filled($stageTimestamps['finance_reviewed_at'])
            || (! $usesFinanceAp && in_array($mrf->status, ['chairman_payment', 'completed'], true));

        $financeReviewPending = ! $financeReviewComplete && (
            ($usesFinanceAp && in_array($mrf->workflow_state, [
                WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
                WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            ], true))
            || (! $usesFinanceAp && $mrf->status === 'finance')
        );

        $steps = [
            $this->step($baseStep, 'finance_review', 'Finance Review',
                $financeReviewComplete ? 'completed' : ($financeReviewPending ? 'pending' : 'not_started'),
                $stageTimestamps['finance_reviewed_at'] ?? null,
                $usesFinanceAp ? 'Finance AP reviews the procurement package' : 'Internal finance reviews for chairman payment',
                ['financeApCaseId' => $mrf->finance_ap_case_id, 'financeApStatus' => $mrf->finance_ap_status]),
        ];

        $stepNumber = $baseStep + 1;

        if ($usesFinanceAp && $schedule) {
            $schedule->loadMissing('milestones');
            foreach ($schedule->milestones as $milestone) {
                $paid = in_array($milestone->status, [PaymentMilestone::STATUS_PAID, PaymentMilestone::STATUS_COMPLETE], true);
                $steps[] = $this->step(
                    $stepNumber,
                    'milestone_'.$milestone->id,
                    $milestone->label ?: 'Milestone '.$milestone->milestone_number,
                    $paid ? 'completed' : 'not_started',
                    $milestone->paid_at?->toIso8601String(),
                    sprintf('Milestone %d — %s%%', $milestone->milestone_number, rtrim(rtrim(number_format((float) $milestone->percentage, 2, '.', ''), '0'), '.')),
                    [
                        'milestoneId' => $milestone->id,
                        'milestoneNumber' => $milestone->milestone_number,
                        'percentage' => (float) $milestone->percentage,
                        'amount' => $milestone->amount !== null ? (float) $milestone->amount : null,
                        'currency' => $mrf->currency ?? 'NGN',
                        'milestoneStatus' => $milestone->status,
                        'financeApReference' => $milestone->finance_ap_reference,
                    ]
                );
                $stepNumber++;
            }
        } elseif (! $usesFinanceAp) {
            $steps[] = $this->step($stepNumber, 'internal_payment', 'Chairman Payment Approval',
                $mrf->payment_status === 'approved' ? 'completed' : ($mrf->status === 'chairman_payment' ? 'pending' : 'not_started'),
                $stageTimestamps['payment_completed_at'] ?? null,
                'Internal payment flow through chairman approval');
            $stepNumber++;
        }

        $closed = $mrf->workflow_state === WorkflowStateService::STATE_CLOSED
            || $mrf->status === 'completed'
            || ($usesFinanceAp && $mrf->workflow_state === WorkflowStateService::STATE_OPERATIONALLY_COMPLETE);

        $steps[] = $this->step($stepNumber, 'closed', 'Fully Paid / Closed',
            $closed ? 'completed' : 'not_started',
            $stageTimestamps['closed_at'] ?? $stageTimestamps['payment_completed_at'] ?? null,
            $usesFinanceAp ? 'All milestones paid and case closed' : 'MRF completed');

        return $steps;
    }

    private function shouldHideDeliveryPhase($schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $schedule->loadMissing('milestones');

        if ($schedule->milestones->count() !== 1) {
            return false;
        }

        $milestone = $schedule->milestones->first();

        return (float) $milestone->percentage === 100.0
            && $milestone->trigger_condition === PaymentMilestone::TRIGGER_ON_ADVANCE;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function step(int $number, string $key, string $name, string $status, ?string $completedAt, string $description, array $extra = []): array
    {
        return array_merge([
            'step' => $number,
            'key' => $key,
            'name' => $name,
            'status' => $status,
            'completedAt' => $completedAt,
            'description' => $description,
        ], $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function phase(string $id, string $label, array $steps): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'steps' => $steps,
            'completedSteps' => 0,
            'totalSteps' => count($steps),
        ];
    }

    private function stepCompleted(string $status): string
    {
        return $status;
    }

    private function initialApprovalStatus(MRF $mrf, bool $isEmerald): string
    {
        if ($isEmerald) {
            if (in_array($mrf->workflow_state, ['executive_rejected'], true) || strtolower($mrf->status ?? '') === 'rejected') {
                return 'rejected';
            }

            return in_array($mrf->workflow_state, [
                'executive_approved', 'procurement_review', 'procurement_approved', 'rfq_issued',
                'quotations_received', 'quotations_evaluated', 'vendor_selected', 'invoice_approved',
                'po_generated', 'po_signed', 'closed',
            ], true) ? 'completed' : ($mrf->workflow_state === 'executive_review' ? 'pending' : 'not_started');
        }

        return $mrf->workflow_state === 'supply_chain_director_review' ? 'pending' :
            (in_array($mrf->workflow_state, [
                'supply_chain_director_approved', 'procurement_review', 'procurement_approved',
                'rfq_issued', 'quotations_received', 'quotations_evaluated', 'vendor_selected',
                'invoice_approved', 'po_generated', 'po_signed', 'closed',
            ], true) ? 'completed' : 'not_started');
    }

    private function procurementReviewStatus(MRF $mrf, bool $hasRfqs): string
    {
        $completed = ['quotations_evaluated', 'vendor_selected', 'invoice_approved', 'po_generated', 'po_signed', 'closed'];
        $active = ['supply_chain_director_approved', 'executive_approved', 'procurement_review', 'procurement_approved', 'rfq_issued', 'quotations_received'];

        if (in_array($mrf->workflow_state, $completed, true)) {
            return 'completed';
        }

        if (in_array($mrf->workflow_state, $active, true) || $hasRfqs) {
            return 'pending';
        }

        return 'not_started';
    }

    private function vendorSelectionStatus(MRF $mrf): string
    {
        return in_array($mrf->workflow_state, ['invoice_approved', 'po_generated', 'po_signed', 'closed'], true)
            ? 'completed'
            : ($mrf->workflow_state === 'vendor_selected' ? 'pending' : 'not_started');
    }

    private function poSignedStatus(MRF $mrf): string
    {
        $signedStates = [
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_CLOSED,
            'closed',
        ];

        return in_array($mrf->workflow_state, $signedStates, true) || filled($mrf->po_signed_at)
            ? 'completed'
            : ($mrf->workflow_state === 'po_generated' ? 'pending' : 'not_started');
    }

    /**
     * @param  array<string, mixed>|null  $activeDocument
     */
    private function documentStepStatus(?array $activeDocument, bool $usesFinanceAp): string
    {
        if ($activeDocument) {
            return 'completed';
        }

        return $usesFinanceAp ? 'not_started' : 'not_started';
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     */
    private function hasGrnDocument(MRF $mrf, array $activeByType): bool
    {
        if ($activeByType[ProcurementDocument::TYPE_GRN] ?? null) {
            return true;
        }

        return (bool) ($mrf->grn_completed && ! empty($mrf->grn_url));
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     */
    private function grnStepStatus(MRF $mrf, array $activeByType): string
    {
        if ($this->hasGrnDocument($mrf, $activeByType)) {
            return 'completed';
        }

        if ($mrf->workflow_state === WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING) {
            return 'pending';
        }

        return in_array($mrf->workflow_state, [
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_CLOSED,
        ], true) ? 'completed' : 'not_started';
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     */
    private function deliveryDocsSatisfied(array $activeByType): bool
    {
        foreach ([ProcurementDocument::TYPE_WAYBILL, ProcurementDocument::TYPE_JCC, ProcurementDocument::TYPE_DELIVERY_CONFIRMATION] as $type) {
            if ($activeByType[$type] ?? null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $activeByType
     */
    private function deliveryDocsStepStatus(MRF $mrf, array $activeByType, $schedule): string
    {
        if ($this->deliveryDocsSatisfied($activeByType)) {
            return 'completed';
        }

        $evaluation = $this->deliveryConfirmationService->evaluate($mrf);

        if ($evaluation['satisfied']) {
            return 'completed';
        }

        if ($mrf->workflow_state === WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING) {
            return 'pending';
        }

        return 'not_started';
    }

    /**
     * @param  list<string>  $actions
     * @param  list<string>  $stages
     */
    private function historyTimestamp(MRF $mrf, array $actions, array $stages): ?string
    {
        $record = $mrf->approvalHistory
            ->filter(fn (MRFApprovalHistory $h) => in_array($h->action, $actions, true) && in_array($h->stage, $stages, true))
            ->sortBy('created_at')
            ->first();

        return $record?->created_at?->toIso8601String();
    }

    private function firstQuotationReceivedAt(MRF $mrf): ?string
    {
        $first = $mrf->quotations()
            ->whereIn('status', ['submitted', 'approved', 'selected'])
            ->oldest()
            ->value('created_at');

        return $first ? \Carbon\Carbon::parse($first)->toIso8601String() : null;
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return \Carbon\Carbon::parse($value)->toIso8601String();
    }
}
