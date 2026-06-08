<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\ProcurementDocument;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;

class ClosureReadinessService
{
    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
        private ProcurementDocumentService $documentService,
    ) {
    }

    /**
     * @return array{
     *     financially_complete: bool,
     *     operationally_complete: bool,
     *     can_close: bool,
     *     blockers: list<string>,
     *     missing_documents: list<string>,
     *     milestoneSummary: list<array<string, mixed>>
     * }
     */
    public function evaluate(MRF $mrf): array
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return [
                'financially_complete' => $this->legacyFinanciallyComplete($mrf),
                'operationally_complete' => $this->legacyOperationallyComplete($mrf),
                'can_close' => $this->legacyCanClose($mrf),
                'blockers' => $this->legacyCanClose($mrf) ? [] : ['Legacy MRF closure rules apply.'],
                'missing_documents' => [],
                'milestoneSummary' => [],
            ];
        }

        $schedule = $this->paymentScheduleService->findForMrf($mrf);
        $blockers = [];
        $milestoneSummary = [];

        if (! $schedule) {
            return [
                'financially_complete' => false,
                'operationally_complete' => false,
                'can_close' => false,
                'blockers' => ['Payment schedule is required before this MRF can close.'],
                'missing_documents' => [],
                'milestoneSummary' => [],
            ];
        }

        $schedule->loadMissing('milestones');
        $vendorId = $this->documentService->resolveVendorId($mrf);
        $missingDocuments = [];

        foreach ($schedule->milestones as $milestone) {
            $requiredDocs = $this->paymentScheduleService->requiredDocumentsForMilestone($milestone);
            $missingDocs = $this->documentService->missingDocumentTypes($mrf, $requiredDocs, $vendorId);
            $financiallyDone = in_array($milestone->status, [
                PaymentMilestone::STATUS_PAID,
                PaymentMilestone::STATUS_COMPLETE,
            ], true);

            $milestoneSummary[] = [
                'milestoneNumber' => $milestone->milestone_number,
                'label' => $milestone->label,
                'status' => $milestone->status,
                'financiallyComplete' => $financiallyDone,
                'requiredDocuments' => $requiredDocs,
                'missingDocuments' => $missingDocs,
            ];

            if (! $financiallyDone) {
                $blockers[] = "Milestone {$milestone->milestone_number} ({$milestone->label}) is not fully paid.";
            }

            foreach ($missingDocs as $missingDoc) {
                $blockers[] = "Missing {$missingDoc} document for milestone {$milestone->milestone_number} ({$milestone->label}).";
                $missingDocuments[] = $missingDoc;
            }
        }

        $financiallyComplete = $this->paymentScheduleService->allMilestonesFinanciallyComplete($schedule);
        $operationallyComplete = collect($milestoneSummary)->every(
            fn (array $row) => ($row['missingDocuments'] ?? []) === []
        );

        if ($financiallyComplete && $this->paymentScheduleService->isAdvanceOnlySchedule($schedule)) {
            $completionDocs = $this->advanceOnlyClosureDocumentTypes($schedule);
            $missingCompletionDocs = $this->documentService->missingDocumentTypes($mrf, $completionDocs, $vendorId);

            foreach ($missingCompletionDocs as $missingDoc) {
                $blockers[] = "Missing {$missingDoc} document required before PO closure.";
                $missingDocuments[] = $missingDoc;
            }

            if ($missingCompletionDocs !== []) {
                $operationallyComplete = false;
            }
        }

        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        $stateReady = in_array($state, [
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
        ], true) || ($financiallyComplete && $operationallyComplete);

        if (! $stateReady && $financiallyComplete && $operationallyComplete) {
            $stateReady = true;
        }

        if (! $financiallyComplete) {
            $blockers[] = 'Not all payment milestones are marked paid or complete.';
        }

        if (! $operationallyComplete) {
            $blockers[] = 'Required milestone documents are still missing.';
        }

        $canClose = $financiallyComplete && $operationallyComplete;

        return [
            'financially_complete' => $financiallyComplete,
            'operationally_complete' => $operationallyComplete,
            'can_close' => $canClose,
            'blockers' => array_values(array_unique($blockers)),
            'missing_documents' => array_values(array_unique($missingDocuments)),
            'milestoneSummary' => $milestoneSummary,
        ];
    }

    /**
     * Completion documents required to close 100% advance POs even after payment.
     *
     * @return list<string>
     */
    private function advanceOnlyClosureDocumentTypes($schedule): array
    {
        if (! $schedule) {
            return [];
        }

        $schedule->loadMissing('milestones');

        $docs = [
            ProcurementDocument::TYPE_VENDOR_INVOICE,
            ProcurementDocument::TYPE_GRN,
        ];

        foreach ($schedule->milestones as $milestone) {
            $required = $this->paymentScheduleService->requiredDocumentsForMilestone($milestone);

            if ($milestone->trigger_condition === PaymentMilestone::TRIGGER_UPON_COMPLETION
                || in_array(ProcurementDocument::TYPE_JCC, $required, true)) {
                $docs[] = ProcurementDocument::TYPE_JCC;
                break;
            }
        }

        return array_values(array_unique($docs));
    }

    private function legacyFinanciallyComplete(MRF $mrf): bool
    {
        return in_array($mrf->workflow_state, [
            WorkflowStateService::STATE_PAYMENT_PROCESSED,
            WorkflowStateService::STATE_GRN_COMPLETED,
            WorkflowStateService::STATE_CLOSED,
        ], true);
    }

    private function legacyOperationallyComplete(MRF $mrf): bool
    {
        if ($mrf->workflow_state === WorkflowStateService::STATE_CLOSED) {
            return true;
        }

        return (bool) ($mrf->grn_completed || ! empty($mrf->grn_url));
    }

    private function legacyCanClose(MRF $mrf): bool
    {
        return $mrf->workflow_state === WorkflowStateService::STATE_CLOSED
            || ($this->legacyFinanciallyComplete($mrf) && $this->legacyOperationallyComplete($mrf));
    }
}
