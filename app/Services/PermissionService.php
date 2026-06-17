<?php

namespace App\Services;

use App\Support\ProcurementOverviewAccess;
use App\Support\UserRoleNormalizer;
use App\Models\Employee;
use App\Models\User;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Services\Finance\FinanceRoutingService;

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
        return in_array($user->scmRole(), ['employee', 'staff', 'regular_staff']);
    }

    /**
     * Check if user can edit MRF (only before submission)
     */
    public function canEditMRF(User $user, MRF $mrf): bool
    {
        // Staff can only edit their own MRF before submission
        if (in_array($user->scmRole(), ['employee', 'staff', 'regular_staff'])) {
            $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
            return $mrf->requester_id === $user->id && 
                   $currentState === WorkflowStateService::STATE_MRF_CREATED;
        }
        
        // No one else can edit MRF after submission
        return false;
    }

    /**
     * Check if user can approve/reject MRF at initial stage.
     * Emerald contracts: Executive first approval.
     * Non-Emerald contracts: Supply Chain Director first approval.
     */
    public function canApproveMRF(User $user, MRF $mrf): bool
    {
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        $isEmeraldContract = strtolower(trim((string) $mrf->contract_type)) === 'emerald';

        if ($isEmeraldContract) {
            if (!in_array($user->scmRole(), ['executive', 'admin'])) {
                return false;
            }

            return $currentState === WorkflowStateService::STATE_EXECUTIVE_REVIEW;
        }

        if (!in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return false;
        }

        return $currentState === WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW;
    }

    /**
     * Check if user can select vendors (Procurement only)
     */
    public function canSelectVendors(User $user, MRF $mrf): bool
    {
        if (!in_array($user->scmRole(), ['procurement', 'procurement_manager', 'admin'])) {
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
        if (!in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return false;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        return $currentState === WorkflowStateService::STATE_VENDOR_SELECTED;
    }

    /**
     * Procurement / admin roles that may generate or upload POs.
     *
     * Matches login access patterns: normalized `users.role`, Spatie role names,
     * `is_admin`, and employee job title "Procurement Manager" (same as AuthController).
     */
    public function userActsAsProcurement(User $user): bool
    {
        if (($user->is_admin ?? false) === true) {
            return true;
        }

        $allowed = ['procurement', 'procurement_manager', 'admin'];

        foreach ($this->userRoleSlugCandidates($user) as $slug) {
            if (in_array($slug, $allowed, true)) {
                return true;
            }
        }

        if (method_exists($user, 'hasAnyRole')) {
            try {
                if ($user->hasAnyRole(['procurement', 'procurement_manager', 'admin'])) {
                    return true;
                }
            } catch (\Throwable) {
                // Spatie guard / cache issues — fall through to job-title check
            }
        }

        return $this->userHasProcurementManagerJobTitle($user);
    }

    /**
     * @return list<string>
     */
    private function userRoleSlugCandidates(User $user): array
    {
        $out = [];

        if (! empty(UserRoleNormalizer::supplyChainRole($user))) {
            $out[] = $this->slugifyRoleLabel((string) UserRoleNormalizer::supplyChainRole($user));
        }

        if (method_exists($user, 'getRoleNames')) {
            try {
                foreach ($user->getRoleNames() as $name) {
                    $out[] = $this->slugifyRoleLabel((string) $name);
                }
            } catch (\Throwable) {
            }
        }

        return array_values(array_unique(array_filter($out, static fn ($s) => $s !== '')));
    }

    private function slugifyRoleLabel(string $raw): string
    {
        $s = strtolower(trim(preg_replace('/[\s\-]+/', '_', trim($raw))));

        return match ($s) {
            'procurementmanager' => 'procurement_manager',
            'administrator' => 'admin',
            default => $s,
        };
    }

    private function userHasProcurementManagerJobTitle(User $user): bool
    {
        if (! $user->employee_id) {
            return false;
        }

        $employee = $user->relationLoaded('employee')
            ? $user->employee
            : Employee::query()->find($user->employee_id);

        if (! $employee) {
            return false;
        }

        $title = trim((string) ($employee->job_title ?? ''));

        return strcasecmp($title, 'Procurement Manager') === 0;
    }

    /**
     * Check if procurement may generate the PO PDF.
     *
     * Generation IS the "route for approval" action — it sets workflow_state=po_generated
     * and status=awaiting_scd_signature so SCD can sign. We therefore only require:
     *   - the user acts as procurement,
     *   - an RFQ exists for this MRF,
     *   - a vendor/quotation has been chosen (price comparison or selectVendor) OR
     *     the MRF/RFQ is already in a recognised post-vendor-selection state,
     *   - the PO has not already been signed.
     */
    public function canGeneratePO(User $user, MRF $mrf): bool
    {
        if (! $this->userActsAsProcurement($user)) {
            return false;
        }

        $rfq = \App\Models\RFQ::where('mrf_id', $mrf->id)->first();
        if (! $rfq) {
            return false;
        }

        if (trim((string) ($mrf->signed_po_url ?? '')) !== '') {
            return false;
        }

        if ($mrf->isPoDraft()) {
            return true;
        }

        $statusLower = strtolower(trim((string) ($mrf->status ?? '')));
        if (in_array($statusLower, ['rejected', 'cancelled', 'closed'], true)) {
            return false;
        }

        $currentState = strtolower(trim((string) ($mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED)));
        if (in_array($currentState, [
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REJECTED,
            WorkflowStateService::STATE_EXECUTIVE_REJECTED,
            WorkflowStateService::STATE_CLOSED,
        ], true)) {
            return false;
        }

        $allowedWorkflowStates = [
            WorkflowStateService::STATE_VENDOR_SELECTED,
            WorkflowStateService::STATE_QUOTATIONS_EVALUATED,
            WorkflowStateService::STATE_QUOTATIONS_RECEIVED,
            WorkflowStateService::STATE_RFQ_ISSUED,
            WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            WorkflowStateService::STATE_PROCUREMENT_APPROVED,
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
            WorkflowStateService::STATE_INVOICE_APPROVED,
            WorkflowStateService::STATE_PO_GENERATED,
            'executive_approved',
            'supply_chain_director_approved',
        ];

        if (in_array($currentState, $allowedWorkflowStates, true)) {
            return true;
        }

        if (in_array($statusLower, [
            'procurement',
            'pending_po_upload',
            'pending',
            'revision_required',
            'po rejected',
            'vendor_selected',
            'awaiting_scd_signature',
            'supply_chain',
        ], true)) {
            return true;
        }

        return $this->procurementHasPoDraftContext($mrf, $rfq);
    }

    /**
     * Fast-track PO creation from the Procurement Overview "Purchase Order" tab.
     *
     * Bypasses the full MRF approval workflow (executive review, SCD vendor approval,
     * etc.) so procurement can issue a PO and route it directly to SCD for signature.
     * Only the bare minimum is enforced: procurement role, MRF exists, PO not yet
     * signed, and the MRF is not closed/cancelled.
     */
    public function canFastTrackPO(User $user, MRF $mrf): bool
    {
        if (! $this->userActsAsProcurement($user)) {
            return false;
        }

        if (trim((string) ($mrf->signed_po_url ?? '')) !== '') {
            return false;
        }

        $statusLower = strtolower(trim((string) ($mrf->status ?? '')));
        if (in_array($statusLower, ['cancelled', 'closed'], true)) {
            return false;
        }

        $stateLower = strtolower(trim((string) ($mrf->workflow_state ?? '')));

        return ! in_array($stateLower, [
            WorkflowStateService::STATE_CLOSED,
        ], true);
    }

    /**
     * Whether procurement may persist PO draft fields (no PDF, no workflow advance).
     * Looser than {@see self::canGeneratePO}: allowed while RFQ is still awaiting SCD
     * so the PO form can be saved incrementally before final generation.
     */
    public function canSavePODraft(User $user, MRF $mrf): bool
    {
        if (! $this->userActsAsProcurement($user)) {
            return false;
        }

        if ($mrf->isPoDraft() && trim((string) ($mrf->signed_po_url ?? '')) === '') {
            return true;
        }

        $rfq = \App\Models\RFQ::where('mrf_id', $mrf->id)->first();
        if (! $rfq) {
            return false;
        }

        if (trim((string) ($mrf->signed_po_url ?? '')) !== '') {
            return false;
        }

        if ($this->canGeneratePO($user, $mrf)) {
            return true;
        }

        $state = strtolower(trim((string) ($mrf->workflow_state ?? '')));

        $draftWorkflowStates = [
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
            WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            WorkflowStateService::STATE_RFQ_ISSUED,
            WorkflowStateService::STATE_QUOTATIONS_RECEIVED,
            WorkflowStateService::STATE_QUOTATIONS_EVALUATED,
            WorkflowStateService::STATE_VENDOR_SELECTED,
            WorkflowStateService::STATE_PROCUREMENT_APPROVED,
            WorkflowStateService::STATE_PO_GENERATED,
            WorkflowStateService::STATE_INVOICE_APPROVED,
            // Legacy / alternate labels seen in data or migrations
            'executive_approved',
            'supply_chain_director_approved',
        ];

        if (in_array($state, $draftWorkflowStates, true)) {
            return true;
        }

        $statusLower = strtolower(trim((string) ($mrf->status ?? '')));

        if (in_array($statusLower, [
            'procurement',
            'pending_po_upload',
            'pending',
            'revision_required',
            'po rejected',
            'vendor_selected',
            'supply_chain',
            'awaiting_scd_signature',
        ], true)) {
            return true;
        }

        // RFQWorkflowController::selectVendor awards on the RFQ but may not sync MRF.workflow_state;
        // price comparison UI still needs draft saves before SCD approves the RFQ.
        return $this->procurementHasPoDraftContext($mrf, $rfq);
    }

    /**
     * True when procurement has progressed far enough that persisting PO draft fields is meaningful.
     */
    private function procurementHasPoDraftContext(MRF $mrf, \App\Models\RFQ $rfq): bool
    {
        if (! empty($mrf->selected_vendor_id)) {
            return true;
        }

        if (! empty($rfq->selected_quotation_id) || ! empty($rfq->selected_vendor_id)) {
            return true;
        }

        return $mrf->priceComparisons()->exists();
    }

    /**
     * Check if user can view invoices (Procurement and Finance)
     */
    public function canViewInvoices(User $user, MRF $mrf): bool
    {
        if (ProcurementOverviewAccess::isProcurementOverviewOnly($user)) {
            return true;
        }

        if (in_array($user->scmRole(), ['procurement', 'procurement_manager', 'finance', 'finance_officer', 'admin'])) {
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
        if (! in_array($user->scmRole(), ['finance', 'finance_officer', 'admin'], true)) {
            return false;
        }

        if (mrfUsesFinanceAp($mrf)) {
            return false;
        }

        if ($mrf->status === 'finance' || $mrf->current_stage === 'finance') {
            return true;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        return $currentState === WorkflowStateService::STATE_PO_SIGNED;
    }

    /**
     * Finance AP MRFs: SCM finance role may view sync status only (no internal payment actions).
     */
    public function canViewFinanceSync(User $user, MRF $mrf): bool
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return false;
        }

        return in_array($user->scmRole(), ['finance', 'finance_officer', 'admin'], true);
    }

    /**
     * Check if user can request GRN
     */
    public function canRequestGRN(User $user, MRF $mrf): bool
    {
        if (! in_array($user->scmRole(), ['finance', 'finance_officer', 'admin'], true)) {
            return false;
        }

        if (mrfUsesFinanceAp($mrf)) {
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
        return $this->canCompleteGRN($user, $mrf);
    }

    /**
     * Check if user can generate or upload GRN documents.
     */
    public function canGenerateGRN(User $user, MRF $mrf): bool
    {
        return $this->canCompleteGRN($user, $mrf);
    }

    /**
     * Check if user can complete/upload GRN (Procurement only).
     */
    public function canCompleteGRN(User $user, MRF $mrf): bool
    {
        if (! in_array($user->scmRole(), ['procurement', 'procurement_manager', 'admin'], true)) {
            return false;
        }

        if ($this->isMRFClosed($mrf)) {
            return false;
        }

        return $this->workflowStateAllowsGrnDocument($mrf);
    }

    /**
     * Check if user can upload registry documents (waybill, JCC, PFI, delivery confirmation, etc.).
     */
    public function canUploadProcurementDocument(User $user, MRF $mrf, string $type): bool
    {
        if ($this->isMRFClosed($mrf)) {
            return false;
        }

        if ($type === ProcurementDocument::TYPE_GRN) {
            return $this->canCompleteGRN($user, $mrf);
        }

        if (! in_array($user->scmRole(), ['procurement', 'procurement_manager', 'admin'], true)) {
            return false;
        }

        return $this->workflowStateAllowsProcurementDocuments($mrf);
    }

    public function canManageDeliveryConfirmation(User $user, MRF $mrf): bool
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return false;
        }

        if ($this->isMRFClosed($mrf)) {
            return false;
        }

        if (! in_array($user->scmRole(), ['procurement', 'procurement_manager', 'admin'], true)) {
            return false;
        }

        return $this->workflowStateAllowsProcurementDocuments($mrf);
    }

    public function showDeliveryConfirmationPanel(MRF $mrf): bool
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return false;
        }

        return app(\App\Services\FinanceAp\DeliveryConfirmationService::class)->showPanel($mrf);
    }

    /**
     * Check if user can view GRN (registry or legacy URL).
     */
    public function canViewGRN(User $user, MRF $mrf): bool
    {
        if ($mrf->procurementDocuments()->where('type', 'grn')->where('is_active', true)->exists()) {
            return $this->canViewDocument($user, $mrf, 'grn');
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        return in_array($currentState, [
            WorkflowStateService::STATE_GRN_COMPLETED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_CLOSED,
        ], true) && ! empty($mrf->grn_url);
    }

    private function workflowStateAllowsGrnDocument(MRF $mrf): bool
    {
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        return in_array($currentState, [
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_GRN_REQUESTED,
            WorkflowStateService::STATE_GRN_COMPLETED,
            WorkflowStateService::STATE_PAYMENT_PROCESSED,
        ], true);
    }

    private function workflowStateAllowsProcurementDocuments(MRF $mrf): bool
    {
        return $this->workflowStateAllowsGrnDocument($mrf);
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
               in_array($user->scmRole(), ['procurement', 'procurement_manager', 'executive', 'supply_chain_director', 'admin']);
    }

    /**
     * Read-only user directory (e.g. dropdowns, assignments). Broader than
     * {@see canManageUsers}; does not allow create/update/delete.
     */
    public function canListUsersDirectory(User $user): bool
    {
        if ($this->canManageUsers($user)) {
            return true;
        }

        return in_array($user->scmRole(), [
            'logistics',
            'logistics_manager',
            'logistics_officer',
        ], true);
    }

    /**
     * Check if user can view document
     */
    public function canViewDocument(User $user, MRF $mrf, string $documentType): bool
    {
        if (ProcurementOverviewAccess::isProcurementOverviewOnly($user)) {
            return in_array($documentType, ProcurementOverviewAccess::readOnlyDocumentTypes(), true);
        }

        // Executive, Procurement, Finance can view all documents
        if (in_array($user->scmRole(), ['executive', 'procurement', 'procurement_manager', 'finance', 'finance_officer', 'supply_chain_director', 'admin'])) {
            return true;
        }

        // Regular staff can only view their own MRF documents
        if (in_array($user->scmRole(), ['employee', 'staff', 'regular_staff'])) {
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
            $routing = app(FinanceRoutingService::class)->routingMeta($mrf);

            $actions = [
                'usesFinanceAp' => $routing['usesFinanceAp'],
                'financeRoute' => $routing['financeRoute'],
                'cutoverDate' => $routing['cutoverDate'],
                'canEdit' => false,
                'canApprove' => false,
                'canReject' => false,
                'canSelectVendors' => false,
                'canViewInvoices' => true, // Can still view for reference
                'canApproveInvoice' => false,
                'canGeneratePO' => false,
                'canSignPO' => false,
                'canProcessPayment' => false,
                'canViewFinanceSync' => $this->canViewFinanceSync($user, $mrf),
                'canRequestGRN' => false,
                'canUploadGRN' => false,
                'canGenerateGRN' => false,
                'canViewGRN' => $this->canViewGRN($user, $mrf),
                'showDeliveryConfirmationPanel' => false,
                'canManageDeliveryConfirmation' => false,
                'canUploadWaybill' => false,
                'canUploadJcc' => false,
                'canUploadDeliveryConfirmation' => false,
                'availableActions' => ['view'],
            ];

            if (ProcurementOverviewAccess::isProcurementOverviewOnly($user)) {
                return $this->applyProcurementOverviewReadOnly($actions);
            }

            return $actions;
        }

        $canManageDeliveryConfirmation = $this->canManageDeliveryConfirmation($user, $mrf);
        $showDeliveryConfirmationPanel = $this->showDeliveryConfirmationPanel($mrf);
        
        $routing = app(FinanceRoutingService::class)->routingMeta($mrf);

        $actions = [
            'usesFinanceAp' => $routing['usesFinanceAp'],
            'financeRoute' => $routing['financeRoute'],
            'cutoverDate' => $routing['cutoverDate'],
            'canEdit' => $this->canEditMRF($user, $mrf),
            'canApprove' => $this->canApproveMRF($user, $mrf),
            'canReject' => $this->canApproveMRF($user, $mrf), // Same permission as approve
            'canSelectVendors' => $this->canSelectVendors($user, $mrf),
            'canViewInvoices' => $this->canViewInvoices($user, $mrf),
            'canApproveInvoice' => $this->canApproveInvoice($user, $mrf),
            'canGeneratePO' => $this->canGeneratePO($user, $mrf),
            'canSignPO' => false, // PO signing is automatic after generation in new workflow
            'canProcessPayment' => $this->canProcessPayment($user, $mrf),
            'canViewFinanceSync' => $this->canViewFinanceSync($user, $mrf),
            'canRequestGRN' => $this->canRequestGRN($user, $mrf),
            'canUploadGRN' => $this->canUploadGRN($user, $mrf),
            'canGenerateGRN' => $this->canGenerateGRN($user, $mrf),
            'canViewGRN' => $this->canViewGRN($user, $mrf),
            'showDeliveryConfirmationPanel' => $showDeliveryConfirmationPanel,
            'canManageDeliveryConfirmation' => $canManageDeliveryConfirmation,
            'canUploadWaybill' => $canManageDeliveryConfirmation && $this->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_WAYBILL
            ),
            'canUploadJcc' => $canManageDeliveryConfirmation && $this->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_JCC
            ),
            'canUploadDeliveryConfirmation' => $canManageDeliveryConfirmation && $this->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_DELIVERY_CONFIRMATION
            ),
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
        if ($actions['canProcessPayment']) {
            $availableActions[] = 'process_payment';
        }
        if ($actions['canViewFinanceSync']) {
            $availableActions[] = 'view_finance_sync';
        }
        if ($actions['canRequestGRN']) $availableActions[] = 'request_grn';
        if ($actions['canUploadGRN']) $availableActions[] = 'upload_grn';
        if ($actions['canGenerateGRN']) $availableActions[] = 'generate_grn';
        if ($actions['canViewGRN']) $availableActions[] = 'view_grn';
        if ($actions['showDeliveryConfirmationPanel']) $availableActions[] = 'view_delivery_confirmation';
        if ($actions['canManageDeliveryConfirmation']) $availableActions[] = 'manage_delivery_confirmation';
        if ($actions['canUploadWaybill']) $availableActions[] = 'upload_waybill';
        if ($actions['canUploadJcc']) $availableActions[] = 'upload_jcc';
        if ($actions['canUploadDeliveryConfirmation']) $availableActions[] = 'upload_delivery_confirmation';
        
        $actions['availableActions'] = $availableActions;

        if (ProcurementOverviewAccess::isProcurementOverviewOnly($user)) {
            return $this->applyProcurementOverviewReadOnly($actions);
        }

        return $actions;
    }

    /**
     * Strip mutation rights for logistics_manager procurement overview (view-only).
     *
     * @param  array<string, mixed>  $actions
     * @return array<string, mixed>
     */
    private function applyProcurementOverviewReadOnly(array $actions): array
    {
        $readFlags = [
            'canViewInvoices',
            'canViewFinanceSync',
            'canViewGRN',
            'showDeliveryConfirmationPanel',
        ];

        foreach ($actions as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'can')) {
                continue;
            }
            if (in_array($key, $readFlags, true)) {
                continue;
            }
            if (str_starts_with($key, 'canView') || str_starts_with($key, 'show')) {
                continue;
            }
            $actions[$key] = false;
        }

        $actions['readOnly'] = true;
        $actions['isProcurementOverviewOnly'] = true;
        $actions['canManageProcurement'] = false;
        $actions['availableActions'] = array_values(array_unique(array_filter(
            $actions['availableActions'] ?? ['view'],
            fn (string $action) => in_array($action, [
                'view',
                'view_invoices',
                'view_finance_sync',
                'view_grn',
                'view_delivery_confirmation',
            ], true)
        )));

        if ($actions['availableActions'] === []) {
            $actions['availableActions'] = ['view'];
        }

        return $actions;
    }

    /**
     * Get user's dashboard data based on role
     */
    public function getDashboardData(User $user): array
    {
        $role = strtolower((string) (UserRoleNormalizer::supplyChainRole($user) ?? ''));
        
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
                'can_access_logistics' => true,
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
                'can_access_logistics' => true,
                'can_manage_fleet' => true,
                'can_initiate_srf' => true,
            ],
            'supply_chain_director', 'supply_chain' => [
                'can_create_mrf' => false,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => true,
                'can_access_logistics' => true,
                'can_manage_fleet' => true,
                'can_initiate_srf' => true,
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
            'logistics_manager', 'logistics' => [
                'can_create_mrf' => true,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => false,
                'can_access_logistics' => true,
                'can_manage_fleet' => true,
                'can_manage_trips' => true,
                'can_manage_materials' => true,
                'can_initiate_srf' => true,
            ],
            'logistics_officer' => [
                'can_create_mrf' => true,
                'can_approve' => false,
                'can_generate_po' => false,
                'can_review_po' => false,
                'can_process_payment' => false,
                'can_request_grn' => false,
                'can_manage_users' => false,
                'can_access_logistics' => true,
                'can_manage_fleet' => true,
                'can_manage_trips' => true,
                'can_manage_materials' => true,
                'can_initiate_srf' => true,
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
