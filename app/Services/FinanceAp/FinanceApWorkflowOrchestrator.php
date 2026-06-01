<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\User;
use App\Services\PaymentScheduleService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Facades\Log;

class FinanceApWorkflowOrchestrator
{
    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
        private DeliveryConfirmationService $deliveryConfirmationService,
        private ClosureReadinessService $closureReadinessService,
        private WorkflowStateService $workflowStateService,
    ) {
    }

    public function afterVendorQuoteScdApproved(MRF $mrf, User $user): void
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return;
        }

        Log::info('Finance AP vendor quote approved by SCD', [
            'mrf_id' => $mrf->mrf_id,
            'workflow_state' => $mrf->workflow_state,
            'advance_gate' => app(VendorInvoiceGateService::class)->canSubmitInvoice($mrf),
        ]);
    }

    public function afterPoSigned(MRF $mrf, User $user): void
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return;
        }

        $mrf->refresh();

        if (($mrf->workflow_state ?? null) !== WorkflowStateService::STATE_PO_SIGNED) {
            Log::warning('Skipping post-PO routing because MRF is not in po_signed state', [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
            ]);

            return;
        }

        $schedule = $this->paymentScheduleService->findForMrf($mrf);
        $nextState = $this->paymentScheduleService->requiresDeliveryConfirmationStage($schedule)
            ? WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING
            : WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING;

        $this->workflowStateService->transition($mrf, $nextState, $user);

        Log::info('Finance AP post-PO routing applied', [
            'mrf_id' => $mrf->mrf_id,
            'next_state' => $nextState,
        ]);
    }

    public function afterOperationalDocumentChanged(MRF $mrf, User $user): void
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return;
        }

        $this->deliveryConfirmationService->tryAdvance($mrf, $user);
        $this->syncIntermediateCompletionStates($mrf, $user);
    }

    public function syncIntermediateCompletionStates(MRF $mrf, User $user): void
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return;
        }

        $readiness = $this->closureReadinessService->evaluate($mrf);
        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        if ($readiness['financially_complete']
            && ! in_array($state, [
                WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
                WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
                WorkflowStateService::STATE_CLOSED,
            ], true)
            && $this->workflowStateService->canTransition($state, WorkflowStateService::STATE_FINANCIALLY_COMPLETE)) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_FINANCIALLY_COMPLETE, $user);
            $mrf->refresh();
            $state = $mrf->workflow_state;
        }

        if ($readiness['operationally_complete']
            && in_array($state, [
                WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
                WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
                WorkflowStateService::STATE_FINANCE_IN_REVIEW,
                WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            ], true)
            && $this->workflowStateService->canTransition($state, WorkflowStateService::STATE_OPERATIONALLY_COMPLETE)) {
            $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_OPERATIONALLY_COMPLETE, $user);
        }
    }
}
