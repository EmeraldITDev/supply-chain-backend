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
     * Check if user can edit MRF (only before submission)
     */
    public function canEditMRF(User $user, MRF $mrf): bool
    {
        // Staff can only edit their own MRF before submission
        if (in_array($user->role, ['employee', 'staff', 'regular_staff'])) {
            $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
            return $mrf->requester_id === $user->id && 
                   $currentState === WorkflowStateService::STATE_MRF_CREATED;
        }
        
        // No one else can edit MRF after submission
        return false;
    }

    /**
     * Check if user can approve/reject MRF (Executive only)
     */
    public function canApproveMRF(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['executive', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_EXECUTIVE_REVIEW;
    }

    /**
     * Check if user can select vendors (Procurement only)
     */
    public function canSelectVendors(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_EXECUTIVE_APPROVED ||
               $currentState === WorkflowStateService::STATE_PROCUREMENT_REVIEW;
    }

    /**
     * Check if user can approve invoice (Supply Chain Director only)
     */
    public function canApproveInvoice(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_VENDOR_SELECTED;
    }

    /**
     * Check if user can generate PO (Procurement only, after invoice approval)
     */
    public function canGeneratePO(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        
        // Allow PO generation (sending request to vendors) after Executive approval
        // This includes: procurement_review, vendor_selected, invoice_received, invoice_approved
        $allowedStates = [
            WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            WorkflowStateService::STATE_VENDOR_SELECTED,
            WorkflowStateService::STATE_INVOICE_RECEIVED,
            WorkflowStateService::STATE_INVOICE_APPROVED,
        ];
        
        return in_array($currentState, $allowedStates);
    }

    /**
     * Check if user can view invoices (Procurement and Finance)
     */
    public function canViewInvoices(User $user, MRF $mrf): bool
    {
        if (in_array($user->role, ['procurement', 'procurement_manager', 'finance', 'finance_officer', 'admin'])) {
            $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
            return in_array($currentState, [
                WorkflowStateService::STATE_INVOICE_RECEIVED,
                WorkflowStateService::STATE_INVOICE_APPROVED,
                WorkflowStateService::STATE_PO_GENERATED,
                WorkflowStateService::STATE_PO_SIGNED,
                WorkflowStateService::STATE_PAYMENT_PROCESSED,
                WorkflowStateService::STATE_GRN_REQUESTED,
                WorkflowStateService::STATE_GRN_COMPLETED,
                WorkflowStateService::STATE_CLOSED,
            ]);
        }
        return false;
    }

    /**
     * Check if user can process payment (Finance only)
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
     * Check if user can upload GRN (Procurement only)
     */
    public function canUploadGRN(User $user, MRF $mrf): bool
    {
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_GRN_REQUESTED;
    }

    /**
     * Check if user can view GRN (All roles can view after upload)
     */
    public function canViewGRN(User $user, MRF $mrf): bool
    {
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return in_array($currentState, [
            WorkflowStateService::STATE_GRN_COMPLETED,
            WorkflowStateService::STATE_CLOSED,
        ]) && !empty($mrf->grn_url);
    }

    /**
     * Check if MRF is closed (read-only)
     */
    public function isMRFClosed(MRF $mrf): bool
    {
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_CLOSED;
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
     * Get all available actions for a user on a specific MRF
     * This is the main method the frontend should use to determine what to show
     */
    public function getAvailableActions(User $user, MRF $mrf): array
    {
        $isClosed = $this->isMRFClosed($mrf);
        
        // If MRF is closed, no actions are available
        if ($isClosed) {
            return [
                'canEdit' => false,
                'canApprove' => false,
                'canReject' => false,
                'canSelectVendors' => false,
                'canViewInvoices' => true, // Can still view for reference
                'canApproveInvoice' => false,
                'canGeneratePO' => false,
                'canSignPO' => false,
                'canProcessPayment' => false,
                'canRequestGRN' => false,
                'canUploadGRN' => false,
                'canViewGRN' => $this->canViewGRN($user, $mrf),
                'availableActions' => ['view'],
            ];
        }
        
        $actions = [
            'canEdit' => $this->canEditMRF($user, $mrf),
            'canApprove' => $this->canApproveMRF($user, $mrf),
            'canReject' => $this->canApproveMRF($user, $mrf), // Same permission as approve
            'canSelectVendors' => $this->canSelectVendors($user, $mrf),
            'canViewInvoices' => $this->canViewInvoices($user, $mrf),
            'canApproveInvoice' => $this->canApproveInvoice($user, $mrf),
            'canGeneratePO' => $this->canGeneratePO($user, $mrf),
            'canSignPO' => false, // PO signing is automatic after generation in new workflow
            'canProcessPayment' => $this->canProcessPayment($user, $mrf),
            'canRequestGRN' => $this->canRequestGRN($user, $mrf),
            'canUploadGRN' => $this->canUploadGRN($user, $mrf),
            'canViewGRN' => $this->canViewGRN($user, $mrf),
        ];
        
        // Build list of available action keys
        $availableActions = ['view']; // Always can view
        if ($actions['canEdit']) $availableActions[] = 'edit';
        if ($actions['canApprove']) $availableActions[] = 'approve';
        if ($actions['canReject']) $availableActions[] = 'reject';
        if ($actions['canSelectVendors']) $availableActions[] = 'select_vendors';
        if ($actions['canViewInvoices']) $availableActions[] = 'view_invoices';
        if ($actions['canApproveInvoice']) $availableActions[] = 'approve_invoice';
        if ($actions['canGeneratePO']) $availableActions[] = 'generate_po';
        if ($actions['canProcessPayment']) $availableActions[] = 'process_payment';
        if ($actions['canRequestGRN']) $availableActions[] = 'request_grn';
        if ($actions['canUploadGRN']) $availableActions[] = 'upload_grn';
        if ($actions['canViewGRN']) $availableActions[] = 'view_grn';
        
        $actions['availableActions'] = $availableActions;
        
        return $actions;
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
                'can_review_po' => false,
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
