<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\PaymentSchedule;
use App\Models\ProcurementDocument;
use App\Services\PaymentScheduleService;
use App\Services\WorkflowStateService;

class VendorInvoiceGateService
{
    public function __construct(
        private PaymentScheduleService $paymentScheduleService,
    ) {
    }

    public function canSubmitInvoice(MRF $mrf): bool
    {
        return $this->status($mrf)['canSubmit'];
    }

    /**
     * @return array{
     *     canSubmit: bool,
     *     reason: string,
     *     gateType: ?string,
     *     usesFinanceAp: bool
     * }
     */
    public function status(MRF $mrf, ?PaymentSchedule $schedule = null, ?bool $hasConfirmedGrn = null): array
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return [
                'canSubmit' => false,
                'reason' => 'This MRF uses the legacy finance path until Finance AP cutover applies.',
                'gateType' => null,
                'usesFinanceAp' => false,
            ];
        }

        if (! $mrf->selected_vendor_id) {
            return [
                'canSubmit' => false,
                'reason' => 'No vendor has been selected for this MRF.',
                'gateType' => null,
                'usesFinanceAp' => true,
            ];
        }

        $schedule ??= $this->paymentScheduleService->findForMrf($mrf);

        if ($this->paymentScheduleService->hasAdvanceMilestone($schedule)) {
            if ($this->isScdVendorQuoteApproved($mrf)) {
                return [
                    'canSubmit' => true,
                    'reason' => 'Vendor invoice submission is open after Supply Chain Director vendor quote approval (advance milestone schedule).',
                    'gateType' => 'advance',
                    'usesFinanceAp' => true,
                ];
            }

            return [
                'canSubmit' => false,
                'reason' => 'Waiting for Supply Chain Director approval of the selected vendor quotation.',
                'gateType' => 'advance',
                'usesFinanceAp' => true,
            ];
        }

        if ($this->isDeliveryConfirmed($mrf, $hasConfirmedGrn)) {
            return [
                'canSubmit' => true,
                'reason' => 'Vendor invoice submission is open after delivery confirmation (GRN received and confirmed).',
                'gateType' => 'delivery',
                'usesFinanceAp' => true,
            ];
        }

        return [
            'canSubmit' => false,
            'reason' => 'Waiting for delivery confirmation and GRN before vendor invoice submission.',
            'gateType' => 'delivery',
            'usesFinanceAp' => true,
        ];
    }

    private function isScdVendorQuoteApproved(MRF $mrf): bool
    {
        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        return in_array($state, [
            WorkflowStateService::STATE_INVOICE_APPROVED,
            WorkflowStateService::STATE_PO_GENERATED,
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_CLOSED,
        ], true);
    }

    private function isDeliveryConfirmed(MRF $mrf, ?bool $hasConfirmedGrn = null): bool
    {
        $state = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        $stateAllows = in_array($state, [
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_CLOSED,
            WorkflowStateService::STATE_GRN_COMPLETED,
        ], true);

        if (! $stateAllows) {
            return false;
        }

        return $hasConfirmedGrn ?? $this->hasConfirmedGrn($mrf);
    }

    private function hasConfirmedGrn(MRF $mrf): bool
    {
        if (ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', ProcurementDocument::TYPE_GRN)
            ->where('is_active', true)
            ->exists()) {
            return true;
        }

        return (bool) ($mrf->grn_completed && ! empty($mrf->grn_url));
    }
}
