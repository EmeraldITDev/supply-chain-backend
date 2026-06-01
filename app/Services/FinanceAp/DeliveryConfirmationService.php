<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\User;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Facades\Log;

class DeliveryConfirmationService
{
    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
        private ProcurementDocumentService $documentService,
        private WorkflowStateService $workflowStateService,
    ) {
    }

    public function requiresStage(MRF $mrf): bool
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return false;
        }

        $schedule = $this->paymentScheduleService->findForMrf($mrf);

        return $this->paymentScheduleService->requiresDeliveryConfirmationStage($schedule);
    }

    /**
     * @return array{
     *     required: bool,
     *     satisfied: bool,
     *     currentMilestone: ?array<string, mixed>,
     *     requiredDocuments: list<string>,
     *     missingDocuments: list<string>,
     *     uploadedDocuments: list<string>
     * }
     */
    public function evaluate(MRF $mrf): array
    {
        $schedule = $this->paymentScheduleService->findForMrf($mrf);
        $required = $this->paymentScheduleService->requiresDeliveryConfirmationStage($schedule);

        if (! $required) {
            return [
                'required' => false,
                'satisfied' => true,
                'currentMilestone' => null,
                'requiredDocuments' => [],
                'missingDocuments' => [],
                'uploadedDocuments' => [],
            ];
        }

        $requiredDocuments = $this->aggregateRequiredDocuments($schedule);
        $vendorId = $this->documentService->resolveVendorId($mrf);
        $missingDocuments = $this->documentService->missingDocumentTypes($mrf, $requiredDocuments, $vendorId);
        $uploadedDocuments = array_values(array_diff($requiredDocuments, $missingDocuments));
        $current = $this->paymentScheduleService->currentPendingMilestone($schedule);

        return [
            'required' => true,
            'satisfied' => $missingDocuments === [],
            'currentMilestone' => $current ? $this->milestonePayload($current) : null,
            'requiredDocuments' => $requiredDocuments,
            'missingDocuments' => $missingDocuments,
            'uploadedDocuments' => $uploadedDocuments,
        ];
    }

    public function tryAdvance(MRF $mrf, User $user): bool
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return false;
        }

        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        if ($state !== WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING) {
            return false;
        }

        $evaluation = $this->evaluate($mrf);

        if (! $evaluation['satisfied']) {
            return false;
        }

        if (! $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE, $user)) {
            return false;
        }

        $mrf->refresh();

        if (! $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING, $user)) {
            Log::warning('Delivery confirmation complete but finance handoff transition failed', [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
            ]);

            return false;
        }

        Log::info('Delivery confirmation gate cleared; finance handoff pending', [
            'mrf_id' => $mrf->mrf_id,
        ]);

        return true;
    }

    /**
     * @return list<string>
     */
    private function aggregateRequiredDocuments($schedule): array
    {
        $documents = [];

        foreach ($this->paymentScheduleService->milestonesWithOperationalDocumentRequirements($schedule) as $milestone) {
            if ($this->paymentScheduleService->milestoneRequiresDeliveryConfirmation($milestone)) {
                $documents = array_merge(
                    $documents,
                    $this->paymentScheduleService->requiredDocumentsForMilestone($milestone)
                );
            }
        }

        return array_values(array_unique($documents));
    }

    /**
     * @return array<string, mixed>
     */
    private function milestonePayload(PaymentMilestone $milestone): array
    {
        return [
            'id' => $milestone->id,
            'milestoneNumber' => $milestone->milestone_number,
            'label' => $milestone->label,
            'triggerCondition' => $milestone->trigger_condition,
            'requiredDocuments' => $this->paymentScheduleService->requiredDocumentsForMilestone($milestone),
            'status' => $milestone->status,
        ];
    }
}
