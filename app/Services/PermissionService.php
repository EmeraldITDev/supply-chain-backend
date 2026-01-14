<?php

namespace App\Services;

use App\Models\User;
use App\Models\MRF;

class PermissionService
{
    protected WorkflowStateService $workflowService;

    public function __construct(WorkflowStateService $workflowService)
    {
        $this->workflowService = $workflowService;
    }

    /**
     * Check if user can create MRF
     */
    public function canCreateMRF(User $user): bool
    {
        return in_array($user->role, ['employee', 'staff', 'regular_staff']);
    }

    /**
     * Check if user can approve/reject MRF
     */
    public function canApproveMRF(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['executive', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_MRF_CREATED;
    }

    /**
     * Check if user can generate PO
     */
    public function canGeneratePO(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_MRF_APPROVED;
    }

    /**
     * Check if user can review/reject PO
     */
    public function canReviewPO(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return in_array($currentState, [
            WorkflowStateService::STATE_PO_GENERATED,
            WorkflowStateService::STATE_PO_REVIEWED
        ]);
    }

    /**
     * Check if user can sign PO
     */
    public function canSignPO(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_PO_REVIEWED;
    }

    /**
     * Check if user can process payment
     */
    public function canProcessPayment(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['finance', 'finance_officer', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_PO_SIGNED;
    }

    /**
     * Check if user can request GRN
     */
    public function canRequestGRN(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['finance', 'finance_officer', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_PAYMENT_PROCESSED;
    }

    /**
     * Check if user can complete GRN
     */
    public function canCompleteGRN(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_GRN_REQUESTED;
    }

    /**
     * Check if user can manage other users
     */
    public function canManageUsers(User $user): bool
    {
        return $user->can_manage_users === true || 
               $user->is_admin === true ||
               in_array($user->role, ['procurement', 'procurement_manager', 'executive', 'supply_chain_director', 'admin']);
    }

    /**
     * Check if user can view document
     */
    public function canViewDocument(User $user, MRF $mrf, string $documentType): bool
    {
        // Executive, Procurement, Finance can view all documents
        if (in_array($user->role, ['executive', 'procurement', 'procurement_manager', 'finance', 'finance_officer', 'supply_chain_director', 'admin'])) {
            return true;
        }

        // Regular staff can only view their own MRF documents
        if (in_array($user->role, ['employee', 'staff', 'regular_staff'])) {
            return $mrf->requester_id === $user->id && $documentType === 'mrf';
        }

        return false;
    }

    /**
     * Get user's dashboard data based on role
     */
    public function getDashboardData(User $user): array
    {
        $role = strtolower($user->role);
        
        return match($role) {
            'employee', 'staff', 'regular_staff' => [
                'can_create_mrf' => true,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => false,
            ],
            'executive' => [
                'can_create_mrf' => false,
                'can_approve' => true,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => true,
            ],
            'procurement', 'procurement_manager' => [
                'can_create_mrf' => false,
                'can_approve' => false,
                'can_generate_po' => true,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_complete_grn' => true,
                'can_manage_users' => true,
            ],
            'supply_chain_director', 'supply_chain' => [
                'can_create_mrf' => false,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => true,
                'can_sign_po' => true,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => true,
            ],
            'finance', 'finance_officer' => [
                'can_create_mrf' => false,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => true,
                'can_request_grn' => true,
                'can_manage_users' => false,
            ],
            default => [
                'can_create_mrf' => false,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => false,
            ],
        };
    }
}
