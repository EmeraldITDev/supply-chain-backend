<?php

namespace App\Services;

use App\Models\MRF;

/**
 * Maps canonical workflow_state values to legacy status / current_stage fields
 * for backward compatibility during the finance AP rollout.
 */
class WorkflowStateMapper
{
    /**
     * @return array{status?: string, current_stage?: string}
     */
    public function legacyFieldsFor(string $workflowState, ?MRF $mrf = null): array
    {
        return match ($workflowState) {
            WorkflowStateService::STATE_MRF_CREATED => [
                'status' => 'pending',
                'current_stage' => 'pending',
            ],
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW => [
                'status' => 'supply_chain',
                'current_stage' => 'supply_chain',
            ],
            WorkflowStateService::STATE_EXECUTIVE_REVIEW => [
                'status' => 'executive_review',
                'current_stage' => 'executive_review',
            ],
            WorkflowStateService::STATE_PROCUREMENT_REVIEW => [
                'status' => 'procurement_review',
                'current_stage' => 'procurement',
            ],
            WorkflowStateService::STATE_VENDOR_SELECTED => [
                'status' => 'vendor_selected',
                'current_stage' => 'supply_chain_review',
            ],
            WorkflowStateService::STATE_INVOICE_APPROVED => [
                'status' => 'pending_po_upload',
                'current_stage' => 'procurement',
            ],
            WorkflowStateService::STATE_PO_GENERATED => [
                'status' => 'awaiting_scd_signature',
                'current_stage' => 'supply_chain',
            ],
            WorkflowStateService::STATE_PO_SIGNED => [
                'status' => 'signed',
                'current_stage' => 'finance',
            ],
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING => [
                'status' => 'delivery_confirmation',
                'current_stage' => 'procurement',
            ],
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE => [
                'status' => 'delivery_confirmation_complete',
                'current_stage' => 'procurement',
            ],
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING => [
                'status' => 'finance',
                'current_stage' => 'finance',
            ],
            WorkflowStateService::STATE_FINANCE_IN_REVIEW => [
                'status' => 'finance',
                'current_stage' => 'finance',
            ],
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS => [
                'status' => 'finance',
                'current_stage' => 'finance',
            ],
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE => [
                'status' => 'financially_complete',
                'current_stage' => 'finance',
            ],
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE => [
                'status' => 'operationally_complete',
                'current_stage' => 'finance',
            ],
            WorkflowStateService::STATE_PAYMENT_PROCESSED => [
                'status' => 'chairman_payment',
                'current_stage' => 'chairman_payment',
            ],
            WorkflowStateService::STATE_CLOSED => [
                'status' => 'completed',
                'current_stage' => 'completed',
            ],
            default => [],
        };
    }
}
