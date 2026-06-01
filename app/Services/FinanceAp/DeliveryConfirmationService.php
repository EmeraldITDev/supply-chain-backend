<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Facades\Log;

class DeliveryConfirmationService
{
    public const DOCUMENT_LABELS = [
        ProcurementDocument::TYPE_GRN => 'Goods Received Note (GRN)',
        ProcurementDocument::TYPE_WAYBILL => 'Waybill',
        ProcurementDocument::TYPE_JCC => 'Job Completion Certificate (JCC)',
        ProcurementDocument::TYPE_PFI => 'Proforma Invoice (PFI)',
        ProcurementDocument::TYPE_DELIVERY_CONFIRMATION => 'Delivery Confirmation',
        ProcurementDocument::TYPE_OTHER => 'Supporting Document',
    ];

    public const DOCUMENT_ACTIONS = [
        ProcurementDocument::TYPE_GRN => ['generate_grn', 'upload_grn'],
        ProcurementDocument::TYPE_WAYBILL => ['upload_waybill'],
        ProcurementDocument::TYPE_JCC => ['upload_jcc'],
        ProcurementDocument::TYPE_PFI => ['upload_pfi'],
        ProcurementDocument::TYPE_DELIVERY_CONFIRMATION => ['upload_delivery_confirmation'],
        ProcurementDocument::TYPE_OTHER => ['upload_other'],
    ];

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

    public function showPanel(MRF $mrf): bool
    {
        if (! $this->requiresStage($mrf)) {
            return false;
        }

        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        return in_array($state, [
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function panelPayload(MRF $mrf): array
    {
        $evaluation = $this->evaluate($mrf);
        $schedule = $this->paymentScheduleService->findForMrf($mrf);
        $vendorId = $this->documentService->resolveVendorId($mrf);
        $checklistTypes = $this->resolveChecklistDocumentTypes($schedule, $evaluation);

        return array_merge($evaluation, [
            'showPanel' => $this->showPanel($mrf),
            'usesFinanceAp' => mrfUsesFinanceAp($mrf),
            'workflowState' => $mrf->workflow_state,
            'checklist' => $this->buildDocumentChecklist($mrf, $checklistTypes, $vendorId),
            'refreshHint' => [
                'pollAfterUpload' => true,
                'endpoints' => [
                    'deliveryConfirmation' => '/api/mrfs/{id}/delivery-confirmation',
                    'workflowGates' => '/api/mrfs/{id}/workflow-gates',
                    'procurementDocuments' => '/api/mrfs/{id}/procurement-documents',
                ],
            ],
        ]);
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

    public function documentLabel(string $type): string
    {
        return self::DOCUMENT_LABELS[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function buildDocumentChecklist(MRF $mrf, array $types, ?int $vendorId = null): array
    {
        $items = [];

        foreach (array_values(array_unique($types)) as $type) {
            $document = $this->documentService->findActiveDocument($mrf, $type, $vendorId);

            $items[] = [
                'type' => $type,
                'label' => $this->documentLabel($type),
                'required' => true,
                'satisfied' => $document !== null,
                'document' => $document ? $this->documentService->transform($document) : null,
                'actions' => self::DOCUMENT_ACTIONS[$type] ?? ['upload_other'],
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function resolveChecklistDocumentTypes($schedule, array $evaluation): array
    {
        $current = $this->paymentScheduleService->currentPendingMilestone($schedule);

        if ($current && $this->paymentScheduleService->milestoneRequiresDeliveryConfirmation($current)) {
            return $this->paymentScheduleService->requiredDocumentsForMilestone($current);
        }

        return $evaluation['requiredDocuments'];
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
