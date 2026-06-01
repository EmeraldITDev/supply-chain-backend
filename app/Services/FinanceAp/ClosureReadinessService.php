<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\PaymentMilestone;
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
                'milestoneSummary' => [],
            ];
        }

        $schedule->loadMissing('milestones');
        $vendorId = $this->documentService->resolveVendorId($mrf);

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
            }
        }

        $financiallyComplete = $this->paymentScheduleService->allMilestonesFinanciallyComplete($schedule);
        $operationallyComplete = collect($milestoneSummary)->every(
            fn (array $row) => ($row['missingDocuments'] ?? []) === []
        );

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
            'milestoneSummary' => $milestoneSummary,
        ];
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
