<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Services\NotificationService;
use App\Services\EmailService;
use App\Services\OneDriveService;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MRFWorkflowController extends Controller
{
    protected NotificationService $notificationService;
    protected EmailService $emailService;
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;
    protected ?OneDriveService $oneDriveService;

    public function __construct(
        NotificationService $notificationService, 
        EmailService $emailService,
        WorkflowStateService $workflowService,
        PermissionService $permissionService
    ) {
        $this->notificationService = $notificationService;
        $this->emailService = $emailService;
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
        
        // Initialize OneDriveService if credentials are configured
        try {
            if (config('filesystems.disks.onedrive.client_id') && 
                config('filesystems.disks.onedrive.client_secret') &&
                config('filesystems.disks.onedrive.tenant_id')) {
                $this->oneDriveService = app(OneDriveService::class);
            } else {
                $this->oneDriveService = null;
            }
        } catch (\Exception $e) {
            Log::warning('OneDriveService initialization failed', ['error' => $e->getMessage()]);
            $this->oneDriveService = null;
        }
    }

    /**
     * Procurement Manager approves MRF and forwards to Executive
     */
    public function procurementApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can approve at this stage',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in Pending status
        if (strtolower($mrf->status) !== 'pending') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in Pending status',
                'code' => 'INVALID_STATUS',
                'current_status' => $mrf->status
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - forward to Executive
        $mrf->update([
            'status' => 'Executive Approval',
            'current_stage' => 'executive',
        ]);

        // Record approval history
        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'stage' => 'procurement',
            'action' => 'approved',
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'remarks' => $request->remarks,
        ]);

        // Notify executives
        $this->notificationService->notifyMRFForwardedToExecutive($mrf, $user);

        return response()->json([
            'success' => true,
            'message' => 'MRF approved and forwarded to Executive for approval',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'current_stage' => $mrf->current_stage,
            ]
        ]);
    }

    /**
     * Procurement sends selected vendor for Supply Chain Director approval
     * This happens after procurement selects a vendor from RFQ quotations
     */
    public function sendVendorForApproval(Request $request, $id)
    {
        $user = $request->user();

        // Check role - only procurement can send vendor for approval
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement can send vendor for approval',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in correct state
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        if ($currentState !== WorkflowStateService::STATE_PROCUREMENT_REVIEW) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in procurement review stage',
                'code' => 'INVALID_STATUS',
                'current_state' => $currentState
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,vendor_id',
            'quotation_id' => 'required|exists:quotations,quotation_id',
            'invoice_url' => 'nullable|url',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Get vendor and quotation
        $vendor = \App\Models\Vendor::where('vendor_id', $request->vendor_id)->first();
        $quotation = \App\Models\Quotation::where('quotation_id', $request->quotation_id)->first();

        if (!$vendor || !$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor or quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Update MRF with selected vendor and invoice
        $mrf->update([
            'selected_vendor_id' => $vendor->id,
            'invoice_url' => $request->invoice_url ?? $quotation->attachments[0] ?? null,
            'workflow_state' => WorkflowStateService::STATE_VENDOR_SELECTED,
            'status' => 'vendor_selected',
            'current_stage' => 'supply_chain_review',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_selected', 'procurement', $user, 
            "Vendor {$vendor->name} selected and sent for Supply Chain Director approval. " . ($request->remarks ?? ''));

        // Notify Supply Chain Director
        try {
            $scdUsers = \App\Models\User::whereIn('role', ['supply_chain_director', 'supply_chain', 'admin'])->get();
            foreach ($scdUsers as $scdUser) {
                $scdUser->notify(new \App\Notifications\SystemAnnouncementNotification(
                    'Vendor Selection Pending Approval',
                    "MRF {$mrf->mrf_id} - Vendor {$vendor->name} selected and pending your approval"
                ));
            }
            Log::info('Vendor approval notification sent', ['mrf_id' => $mrf->mrf_id, 'vendor_id' => $vendor->vendor_id]);
        } catch (\Exception $e) {
            Log::warning('Failed to send vendor approval notification', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor selection sent for Supply Chain Director approval',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'selected_vendor' => [
                    'id' => $vendor->vendor_id,
                    'name' => $vendor->name,
                ],
                'workflow_state' => $mrf->workflow_state,
            ]
        ]);
    }

    /**
     * Supply Chain Director approves vendor selection
     */
    public function approveVendorSelection(Request $request, $id)
    {
        $user = $request->user();

        // Check role - only Supply Chain Director can approve
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can approve vendor selection',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in vendor_selected state
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        if ($currentState !== WorkflowStateService::STATE_VENDOR_SELECTED) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending vendor approval',
                'code' => 'INVALID_STATUS',
                'current_state' => $currentState
            ], 422);
        }

        // Check if vendor is selected
        if (!$mrf->selected_vendor_id) {
            return response()->json([
                'success' => false,
                'error' => 'No vendor selected for this MRF',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - approve vendor selection (this allows PO generation)
        $mrf->update([
            'workflow_state' => WorkflowStateService::STATE_INVOICE_APPROVED,
            'status' => 'invoice_approved',
            'current_stage' => 'procurement',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_approved', 'supply_chain', $user, 
            'Vendor selection approved. ' . ($request->remarks ?? ''));

        // Notify Procurement that vendor is approved
        try {
            $procurementUsers = \App\Models\User::whereIn('role', ['procurement', 'procurement_manager', 'admin'])->get();
            foreach ($procurementUsers as $procUser) {
                $procUser->notify(new \App\Notifications\SystemAnnouncementNotification(
                    'Vendor Selection Approved',
                    "MRF {$mrf->mrf_id} - Vendor selection has been approved. You can now proceed with invoice review."
                ));
            }
            Log::info('Vendor approved notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::warning('Failed to send vendor approval notification', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor selection approved',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
            ]
        ]);
    }

    /**
     * Supply Chain Director rejects vendor selection
     */
    public function rejectVendorSelection(Request $request, $id)
    {
        $user = $request->user();

        // Check role - only Supply Chain Director can reject
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can reject vendor selection',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in vendor_selected state
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        if ($currentState !== WorkflowStateService::STATE_VENDOR_SELECTED) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending vendor approval',
                'code' => 'INVALID_STATUS',
                'current_state' => $currentState
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - reject vendor selection, return to procurement
        $mrf->update([
            'selected_vendor_id' => null,
            'invoice_url' => null,
            'invoice_share_url' => null,
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            'status' => 'procurement_review',
            'current_stage' => 'procurement',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_rejected', 'supply_chain', $user, 
            'Vendor selection rejected. Reason: ' . $request->reason . ($request->remarks ? "\n" . $request->remarks : ''));

        // Notify Procurement
        try {
            $procurementUsers = \App\Models\User::whereIn('role', ['procurement', 'procurement_manager', 'admin'])->get();
            foreach ($procurementUsers as $procUser) {
                $procUser->notify(new \App\Notifications\SystemAnnouncementNotification(
                    'Vendor Selection Rejected',
                    "MRF {$mrf->mrf_id} - Vendor selection rejected. Reason: {$request->reason}. Please select another vendor."
                ));
            }
            Log::info('Vendor rejected notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::warning('Failed to send vendor rejection notification', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor selection rejected. MRF returned to procurement for vendor reselection.',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
            ]
        ]);
    }

    /**
     * Executive approves MRF
     * Logic: If cost > 1M → chairman_review, else → procurement
     */
    public function executiveApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['executive', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only executives can approve at this stage',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in correct workflow state
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        if ($currentState !== WorkflowStateService::STATE_EXECUTIVE_REVIEW) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending executive approval',
                'code' => 'INVALID_STATUS',
                'current_state' => $currentState,
                'current_status' => $mrf->status
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - mark as executive approved first
        $mrf->update([
            'executive_approved' => true,
            'executive_approved_by' => $user->id,
            'executive_approved_at' => now(),
            'executive_remarks' => $request->remarks,
        ]);

        // Transition to executive_approved state, then to procurement_review
        // First transition: executive_review -> executive_approved
        $transitionSuccess1 = $this->workflowService->transition($mrf, WorkflowStateService::STATE_EXECUTIVE_APPROVED, $user);
        
        if (!$transitionSuccess1) {
            Log::error('Failed to transition MRF to executive_approved', [
                'mrf_id' => $mrf->mrf_id,
                'current_state' => $mrf->workflow_state,
                'user_id' => $user->id
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to transition MRF state',
                'code' => 'TRANSITION_FAILED'
            ], 500);
        }
        
        // Refresh MRF to get updated state
        $mrf->refresh();
        
        // Second transition: executive_approved -> procurement_review
        $transitionSuccess2 = $this->workflowService->transition($mrf, WorkflowStateService::STATE_PROCUREMENT_REVIEW, $user);
        
        if (!$transitionSuccess2) {
            Log::error('Failed to transition MRF to procurement_review', [
                'mrf_id' => $mrf->mrf_id,
                'current_state' => $mrf->workflow_state,
                'user_id' => $user->id
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to transition MRF to procurement review',
                'code' => 'TRANSITION_FAILED'
            ], 500);
        }
        
        // Refresh MRF again to get final state
        $mrf->refresh();
        
        // Update status fields
        $mrf->update([
            'status' => 'procurement_review',
            'current_stage' => 'procurement',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'approved', 'executive_review', $user, $request->remarks ?? '');

        // Send notifications
        try {
            $this->notificationService->notifyMRFPendingProcurement($mrf);
        } catch (\Exception $e) {
            Log::warning('Failed to send procurement notification', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'MRF approved by executive',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'current_stage' => $mrf->current_stage,
                'workflow_state' => $mrf->workflow_state,
                'next_approver' => 'Procurement Manager',
            ]
        ]);
    }

    /**
     * Chairman approves MRF (high-value only)
     * Logic: Move to procurement after chairman approval
     */
    public function chairmanApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['chairman', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only chairman can approve at this stage',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in chairman_review status
        if ($mrf->status !== 'chairman_review') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending chairman approval',
                'code' => 'INVALID_STATUS'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF
        $mrf->update([
            'chairman_approved' => true,
            'chairman_approved_by' => $user->id,
            'chairman_approved_at' => now(),
            'chairman_remarks' => $request->remarks,
            'status' => 'procurement',
            'current_stage' => 'procurement',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'approved', 'chairman_review', $user, $request->remarks);

        // Notify procurement
        $this->notificationService->notifyMRFPendingProcurement($mrf);

        return response()->json([
            'success' => true,
            'message' => 'MRF approved by chairman',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'current_stage' => $mrf->current_stage,
            ]
        ]);
    }

    /**
     * Generate PO (Procurement Manager)
     */
    public function generatePO(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can generate POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->with('items')->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in procurement status (allow both 'procurement' and rejected PO statuses)
        // Use case-insensitive comparison
        $statusLower = strtolower(trim($mrf->status ?? ''));
        $allowedStatuses = ['procurement', 'po rejected', 'pending']; // Also allow pending for flexibility
        
        // Also check if MRF has no PO yet (can always generate first PO)
        $hasExistingPO = !empty(trim($mrf->po_number ?? '')) || !empty(trim($mrf->unsigned_po_url ?? ''));
        
        if (!in_array($statusLower, $allowedStatuses) && $hasExistingPO) {
            // Allow admin to generate PO from any status if no PO exists yet
            $isAdmin = in_array(strtolower($user->role ?? ''), ['admin']);
            
            if (!$isAdmin) {
                return response()->json([
                    'success' => false,
                    'error' => 'MRF is not in procurement stage. Current status: ' . $mrf->status,
                    'code' => 'INVALID_STATUS',
                    'current_status' => $mrf->status,
                    'current_stage' => $mrf->current_stage,
                    'has_existing_po' => $hasExistingPO
                ], 422);
            }
        }

        // Allow PO regeneration if:
        // 1. MRF was rejected (PO Rejected status), OR
        // 2. PO already exists but is unsigned (supply_chain status - can regenerate), OR
        // 3. PO exists but not signed (supply_chain stage)
        $hasExistingPO = !empty(trim($mrf->po_number ?? '')) || !empty(trim($mrf->unsigned_po_url ?? ''));
        $hasSignedPO = !empty(trim($mrf->signed_po_url ?? ''));
        $statusLower = strtolower(trim($mrf->status ?? ''));
        $stageLower = strtolower(trim($mrf->current_stage ?? ''));
        
        $isRegeneration = ($hasExistingPO && $statusLower === 'po rejected') || 
                         ($hasExistingPO && !$hasSignedPO && ($statusLower === 'supply_chain' || $stageLower === 'supply_chain')) ||
                         ($hasExistingPO && !$hasSignedPO);

        // If PO is already signed, don't allow regeneration (unless admin)
        if ($hasSignedPO && !in_array(strtolower($user->role ?? ''), ['admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot regenerate PO that has already been signed. Please contact an administrator.',
                'code' => 'PO_ALREADY_SIGNED',
                'data' => [
                    'existing_po_number' => $mrf->po_number,
                    'po_url' => $mrf->unsigned_po_url,
                    'signed_po_url' => $mrf->signed_po_url
                ]
            ], 422);
        }

        // Auto-generate PO number - always generate unique PO number automatically
        // PO numbers are auto-generated to avoid conflicts and duplicates
        if ($isRegeneration && !empty($mrf->po_number)) {
            // For regeneration, generate a new PO number with version indicator
            $poNumber = $this->generatePONumber($mrf);
            // Add regeneration suffix if regenerating
            $poNumber = $poNumber . '-R' . ($mrf->po_version + 1);
        } else {
            // For new PO, auto-generate unique number
            $poNumber = $this->generatePONumber($mrf);
        }
        
        // Final uniqueness check (should never happen with timestamp-based generation)
        $attempts = 0;
        while (MRF::where('po_number', $poNumber)->where('id', '!=', $mrf->id)->exists() && $attempts < 10) {
            // Add microsecond to ensure uniqueness
            usleep(1000); // Wait 1ms
            $poNumber = $this->generatePONumber($mrf);
            if ($isRegeneration) {
                $poNumber = $poNumber . '-R' . ($mrf->po_version + 1);
            }
            $attempts++;
        }
        
        $willReusePO = false;

        // Validate file upload - check if file exists first
        // Don't use strict validation rules that might fail before we can inspect the file
        $fileExists = $request->hasFile('unsigned_po');
        $file = $fileExists ? $request->file('unsigned_po') : null;
        
        // Build validation rules dynamically based on file existence
        $rules = [
            'remarks' => 'nullable|string',
            // Note: po_number is auto-generated - not accepted from request
        ];
        
        // Only add file validation if file exists
        if ($fileExists && $file) {
            // Check if file is valid first
            if ($file->isValid()) {
                $rules['unsigned_po'] = 'file|mimes:pdf,doc,docx|max:20480'; // 20MB max
            } else {
                // File exists but is invalid - we'll handle this separately
                $rules['unsigned_po'] = 'required'; // This will fail and we can provide better error
            }
        } else {
            // File doesn't exist - make it required
            $rules['unsigned_po'] = 'required|file';
        }

        $validator = Validator::make($request->all(), $rules);

        // Handle file validation errors with better messages
        if ($validator->fails()) {
            $errors = $validator->errors();
            
            // If file validation failed, provide detailed diagnostics
            if ($errors->has('unsigned_po')) {
                if ($fileExists && $file) {
                    // File exists but failed validation - check why
                    if (!$file->isValid()) {
                        $errorCode = $file->getError();
                        $errorMessage = $file->getErrorMessage();
                        
                        $userMessage = match($errorCode) {
                            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large. Maximum size is 20MB. Please compress or reduce the file size.',
                            UPLOAD_ERR_PARTIAL => 'File upload was incomplete. Please try again.',
                            UPLOAD_ERR_NO_FILE => 'No file was selected. Please choose a file.',
                            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error. Please contact administrator.',
                            UPLOAD_ERR_CANT_WRITE => 'Server storage error. Please contact administrator.',
                            UPLOAD_ERR_EXTENSION => 'File upload blocked by server settings. Please contact administrator.',
                            default => 'File upload failed: ' . $errorMessage
                        };
                        
                        $errors->add('unsigned_po', $userMessage);
                    } else {
                        // File is valid but failed other validation rules
                        $originalErrors = $errors->get('unsigned_po');
                        $errors->forget('unsigned_po');
                        
                        // Check file size manually
                        $fileSize = $file->getSize();
                        $maxSize = 20480 * 1024; // 20MB in bytes
                        if ($fileSize > $maxSize) {
                            $errors->add('unsigned_po', 'File size (' . round($fileSize / 1024 / 1024, 2) . 'MB) exceeds maximum allowed size of 20MB.');
                        }
                        
                        // Check file extension
                        $extension = strtolower($file->getClientOriginalExtension());
                        $allowedExtensions = ['pdf', 'doc', 'docx'];
                        if (!in_array($extension, $allowedExtensions)) {
                            $errors->add('unsigned_po', 'Invalid file type. Only PDF, DOC, and DOCX files are allowed. Your file type: ' . $extension);
                        }
                        
                        // If no specific error, use original error message
                        if ($errors->get('unsigned_po')->isEmpty() && !empty($originalErrors)) {
                            foreach ($originalErrors as $error) {
                                $errors->add('unsigned_po', $error);
                            }
                        }
                    }
                } else {
                    // File doesn't exist in request
                    $errors->forget('unsigned_po');
                    $errors->add('unsigned_po', 'Please select a file to upload. Supported formats: PDF, DOC, DOCX (Max 20MB)');
                }
            }
        }

        if ($validator->fails()) {
            // Log validation errors for debugging
            Log::warning('PO generation validation failed', [
                'mrf_id' => $id,
                'mrf_db_id' => $mrf->id,
                'request_po_number' => $requestPONumber ?? 'not provided',
                'determined_po_number' => $poNumber,
                'existing_po_number' => $mrf->po_number,
                'errors' => $validator->errors()->toArray(),
                'is_regeneration' => $isRegeneration,
                'will_reuse_po' => $willReusePO,
                'has_file' => $request->hasFile('unsigned_po'),
                'file_size' => $request->hasFile('unsigned_po') ? $request->file('unsigned_po')->getSize() : null,
                'file_mime' => $request->hasFile('unsigned_po') ? $request->file('unsigned_po')->getMimeType() : null,
                'file_error' => $request->hasFile('unsigned_po') ? $request->file('unsigned_po')->getError() : null,
                'request_keys' => array_keys($request->all()),
            ]);
            
            // Format errors for better frontend display
            $formattedErrors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                if ($key === 'unsigned_po') {
                    // Provide more helpful error messages for file upload
                    $formattedErrors[$key] = $messages;
                    // If it's a generic upload error, add helpful context
                    if (stripos(implode(' ', $messages), 'upload') !== false && 
                        $request->hasFile('unsigned_po') && 
                        !$request->file('unsigned_po')->isValid()) {
                        $file = $request->file('unsigned_po');
                        $formattedErrors[$key][] = 'File upload error code: ' . $file->getError() . ' - ' . $file->getErrorMessage();
                    }
                } else {
                    $formattedErrors[$key] = $messages;
                }
            }
            
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $formattedErrors,
                'code' => 'VALIDATION_ERROR',
                'debug' => [
                    'mrf_id' => $id,
                    'request_po_number' => $requestPONumber ?? null,
                    'determined_po_number' => $poNumber,
                    'existing_po_number' => $mrf->po_number,
                    'is_regeneration' => $isRegeneration,
                    'has_file_in_request' => $request->hasFile('unsigned_po'),
                    'file_info' => $request->hasFile('unsigned_po') ? [
                        'size' => $request->file('unsigned_po')->getSize(),
                        'size_mb' => round($request->file('unsigned_po')->getSize() / 1024 / 1024, 2),
                        'mime' => $request->file('unsigned_po')->getMimeType(),
                        'extension' => $request->file('unsigned_po')->getClientOriginalExtension(),
                        'name' => $request->file('unsigned_po')->getClientOriginalName(),
                        'error' => $request->file('unsigned_po')->getError(),
                        'error_message' => $request->file('unsigned_po')->getErrorMessage(),
                        'is_valid' => $request->file('unsigned_po')->isValid(),
                    ] : [
                        'message' => 'No file found in request. Make sure the file is being sent as multipart/form-data.',
                        'request_has_file' => false,
                    ],
                ]
            ], 422);
        }

        // Handle file upload with improved error handling
        $poUrl = null;
        $poShareUrl = null;
        $useOneDrive = $this->oneDriveService !== null;
        
        // Check if file was uploaded (handles both multipart/form-data and base64)
        if (!$request->hasFile('unsigned_po') && !$request->has('unsigned_po')) {
            return response()->json([
                'success' => false,
                'error' => 'PO file is required',
                'code' => 'FILE_REQUIRED',
                'errors' => [
                    'unsigned_po' => ['The unsigned po file is required.']
                ]
            ], 422);
        }
        
        // Get the file - could be UploadedFile or string (base64)
        $file = $request->hasFile('unsigned_po') ? $request->file('unsigned_po') : null;
        
        if (!$file) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid file upload - file not found in request',
                'code' => 'FILE_UPLOAD_ERROR',
                'errors' => [
                    'unsigned_po' => ['The unsigned po failed to upload. Please ensure the file is not corrupted and try again.']
                ]
            ], 422);
        }
        
        // Validate file before processing
        if (!$file->isValid()) {
            $errorMessage = $file->getErrorMessage();
            $errorCode = $file->getError();
            
            // Provide user-friendly error messages
            $userFriendlyMessage = match($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large. Maximum size is 20MB.',
                UPLOAD_ERR_PARTIAL => 'File upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error. Please contact administrator.',
                UPLOAD_ERR_CANT_WRITE => 'Server storage error. Please contact administrator.',
                UPLOAD_ERR_EXTENSION => 'File upload blocked by server extension.',
                default => 'File upload failed: ' . $errorMessage
            };
            
            Log::error('Invalid file upload', [
                'mrf_id' => $id,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'File upload failed',
                'errors' => [
                    'unsigned_po' => [$userFriendlyMessage]
                ],
                'code' => 'FILE_UPLOAD_ERROR',
                'error_code' => $errorCode
            ], 422);
        }
        
        // Additional file validation
        $fileSize = $file->getSize();
        $maxSize = 20480 * 1024; // 20MB in bytes
        
        if ($fileSize > $maxSize) {
            return response()->json([
                'success' => false,
                'error' => 'File is too large',
                'errors' => [
                    'unsigned_po' => ['File size exceeds maximum allowed size of 20MB.']
                ],
                'code' => 'FILE_TOO_LARGE',
                'file_size' => $fileSize,
                'max_size' => $maxSize
            ], 422);
        }
        
        // Check file MIME type
        $allowedMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $fileMime = $file->getMimeType();
        
        if (!in_array($fileMime, $allowedMimes)) {
            Log::warning('File MIME type not in allowed list', [
                'mrf_id' => $id,
                'file_mime' => $fileMime,
                'file_name' => $file->getClientOriginalName(),
                'allowed_mimes' => $allowedMimes
            ]);
            // Don't fail - some servers report different MIME types for the same file
            // We'll rely on extension validation instead
        }
        
        try {
            // Use OneDrive if configured
            if ($useOneDrive) {
                try {
                    // Delete old PO file from OneDrive if regenerating
                    if ($isRegeneration && $mrf->unsigned_po_url) {
                        Log::info('Attempting to delete old PO file from OneDrive', [
                            'po_url' => $mrf->unsigned_po_url
                        ]);
                    }

                    // Upload to OneDrive in PurchaseOrders folder (organized by year/month)
                    $folder = 'PurchaseOrders/' . date('Y/m');
                    $poFileName = "PO_{$poNumber}_{$mrf->mrf_id}." . $file->getClientOriginalExtension();
                    
                    $oneDriveResult = $this->oneDriveService->uploadFile($file, $folder, $poFileName);
                    
                    if (!isset($oneDriveResult['webUrl']) || empty($oneDriveResult['webUrl'])) {
                        throw new \Exception('OneDrive upload returned no URL');
                    }
                    
                    $poUrl = $oneDriveResult['webUrl'];
                    
                    // Create view-only sharing link for the PO document
                    try {
                        $poShareUrl = $this->oneDriveService->createSharingLink($oneDriveResult['path'], 'view');
                        Log::info('OneDrive sharing link created for PO', [
                            'mrf_id' => $id,
                            'po_number' => $poNumber,
                            'share_url' => $poShareUrl
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to create sharing link for PO', [
                            'error' => $e->getMessage(),
                            'mrf_id' => $id
                        ]);
                        // Continue without sharing link - web URL still works
                    }
                    
                    Log::info($isRegeneration ? 'PO file regenerated on OneDrive' : 'PO file uploaded to OneDrive', [
                        'mrf_id' => $id,
                        'po_number' => $poNumber,
                        'onedrive_path' => $oneDriveResult['path'],
                        'web_url' => $poUrl,
                        'share_url' => $poShareUrl,
                        'is_regeneration' => $isRegeneration
                    ]);
                } catch (\Exception $e) {
                    Log::error('OneDrive upload failed, falling back to local storage', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'mrf_id' => $id
                    ]);
                    // Fall back to local storage on error
                    $useOneDrive = false;
                }
            }
            
            // Fallback to local/S3 storage if OneDrive is not configured or failed
            if (!$useOneDrive) {
                // Delete old PO file if regenerating
                if ($isRegeneration && $mrf->unsigned_po_url) {
                    try {
                        $disk = config('filesystems.documents_disk', 'public');
                        $oldPath = str_replace(Storage::disk($disk)->url(''), '', $mrf->unsigned_po_url);
                        // Clean up path
                        $oldPath = ltrim(str_replace('/storage/', '', $oldPath), '/');
                        if (Storage::disk($disk)->exists($oldPath)) {
                            Storage::disk($disk)->delete($oldPath);
                            Log::info('Deleted old PO file for regeneration', ['old_path' => $oldPath]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete old PO file', ['error' => $e->getMessage()]);
                    }
                }

                $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 'public'));
                
                // Verify disk is available
                if (!config("filesystems.disks.{$disk}")) {
                    throw new \Exception("Storage disk '{$disk}' is not configured. Please check your filesystem configuration.");
                }
                
                $poFileName = "po_{$poNumber}_" . time() . "." . $file->getClientOriginalExtension();
                $poPath = "purchase-orders/{$poFileName}";
                
                // Ensure directory exists
                try {
                    $directory = dirname($poPath);
                    if (!Storage::disk($disk)->exists($directory)) {
                        $created = Storage::disk($disk)->makeDirectory($directory, 0755, true);
                        if (!$created) {
                            throw new \Exception("Failed to create directory: {$directory}");
                        }
                        Log::info('Created directory for PO storage', [
                            'directory' => $directory,
                            'disk' => $disk
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Directory creation failed', [
                        'directory' => $directory ?? 'unknown',
                        'disk' => $disk,
                        'error' => $e->getMessage()
                    ]);
                    throw new \Exception('Failed to create storage directory. Please check file permissions.');
                }
                
                // Store the file - use putFileAs for better error handling
                try {
                    $storedPath = Storage::disk($disk)->putFileAs(
                        dirname($poPath),
                        $file,
                        basename($poPath)
                    );
                    
                    if (!$storedPath) {
                        throw new \Exception('File storage returned null - storage may be full or permissions issue');
                    }
                    
                    // Verify file was actually stored
                    if (!Storage::disk($disk)->exists($storedPath)) {
                        throw new \Exception('File was not found after storage - upload may have failed');
                    }
                    
                    Log::info('PO file stored successfully', [
                        'stored_path' => $storedPath,
                        'disk' => $disk,
                        'file_size' => Storage::disk($disk)->size($storedPath)
                    ]);
                } catch (\Exception $e) {
                    Log::error('File storage failed', [
                        'disk' => $disk,
                        'path' => $poPath,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new \Exception('Failed to store file: ' . $e->getMessage());
                }
                
                // Get URL - handle both local and S3
                try {
                    if ($disk === 'public' || $disk === 'local') {
                        $poUrl = Storage::disk($disk)->url($storedPath);
                        // Ensure URL is absolute
                        if (!filter_var($poUrl, FILTER_VALIDATE_URL)) {
                            $baseUrl = config('app.url');
                            $poUrl = rtrim($baseUrl, '/') . '/' . ltrim($poUrl, '/');
                        }
                    } else {
                        // For S3 or other cloud storage
                        $poUrl = Storage::disk($disk)->url($storedPath);
                    }
                    
                    if (empty($poUrl)) {
                        throw new \Exception('Failed to generate file URL');
                    }
                } catch (\Exception $e) {
                    Log::error('URL generation failed', [
                        'disk' => $disk,
                        'path' => $storedPath,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail completely - file is stored, URL can be generated later
                    $poUrl = $storedPath; // Use path as fallback
                }
                
                Log::info($isRegeneration ? 'PO file regenerated' : 'PO file uploaded', [
                    'mrf_id' => $id,
                    'po_number' => $poNumber,
                    'file_name' => $poFileName,
                    'stored_path' => $storedPath,
                    'url' => $poUrl,
                    'disk' => $disk,
                    'file_size' => $file->getSize(),
                    'is_regeneration' => $isRegeneration
                ]);
            }
            
            // Verify upload was successful
            if (empty($poUrl)) {
                throw new \Exception('PO file upload failed - no URL was generated');
            }
            
        } catch (\Exception $e) {
            Log::error('PO file upload failed', [
                'mrf_id' => $id,
                'po_number' => $poNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_mime' => $file->getMimeType(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload PO file: ' . $e->getMessage(),
                'code' => 'UPLOAD_FAILED',
                'details' => [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'max_size' => $file->getMaxFilesize(),
                ]
            ], 500);
        }

        // Update MRF
        $updateData = [
            'po_number' => $poNumber,
            'unsigned_po_url' => $poUrl,
            'po_generated_at' => now(),
            'status' => 'supply_chain',
            'current_stage' => 'supply_chain',
            'rejection_reason' => null, // Clear rejection reason if regenerating
        ];
        
        // Add sharing URL if available (use web URL as fallback)
        if (isset($poShareUrl) && $poShareUrl) {
            $updateData['unsigned_po_share_url'] = $poShareUrl;
        } elseif ($poUrl) {
            // Use web URL as sharing URL if sharing link creation failed
            $updateData['unsigned_po_share_url'] = $poUrl;
        }
        
        $mrf->update($updateData);

        // Record in approval history
        $action = $isRegeneration ? 'regenerated_po' : 'generated_po';
        $remarks = $isRegeneration 
            ? "PO regenerated after rejection: {$poNumber}" 
            : "PO generated: {$poNumber}";
        MRFApprovalHistory::record($mrf, $action, 'procurement', $user, $remarks);

        // Notify Supply Chain Director
        $this->notificationService->notifyPOReadyForSignature($mrf);

        return response()->json([
            'success' => true,
            'message' => 'PO generated successfully',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $mrf->po_number,
                'unsigned_po_url' => $mrf->unsigned_po_url,
                'status' => $mrf->status,
            ]
        ]);
    }

    /**
     * Upload signed PO (Supply Chain Director)
     * 
     * Workflow:
     * 1. Procurement generates unsigned PO → MRF status becomes "supply_chain"
     * 2. Supply Chain reviews/downloads the unsigned PO (via unsignedPoUrl in MRF response)
     * 3. Supply Chain uploads their signed version using this endpoint
     */
    public function uploadSignedPO(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can sign POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in supply_chain status (case-insensitive)
        $statusLower = strtolower(trim($mrf->status ?? ''));
        if ($statusLower !== 'supply_chain') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending PO signature. Current status: ' . $mrf->status,
                'code' => 'INVALID_STATUS',
                'current_status' => $mrf->status
            ], 422);
        }
        
        // Verify that an unsigned PO exists (procurement must generate PO first)
        // Supply Chain needs to review/download the unsigned PO before uploading signed version
        if (empty($mrf->unsigned_po_url) || empty($mrf->po_number)) {
            return response()->json([
                'success' => false,
                'error' => 'No unsigned PO found. Procurement must generate a PO before Supply Chain can upload a signed version.',
                'code' => 'NO_UNSIGNED_PO',
                'details' => [
                    'has_po_number' => !empty($mrf->po_number),
                    'has_unsigned_po_url' => !empty($mrf->unsigned_po_url)
                ]
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'signed_po' => 'required|file|mimes:pdf|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Upload signed PO - use OneDrive if configured, otherwise use local/S3 storage
        $signedPOFile = $request->file('signed_po');
        $signedPOUrl = null;
        $signedPOShareUrl = null;
        $useOneDrive = $this->oneDriveService !== null;
        
        if ($useOneDrive) {
            try {
                // Upload to OneDrive in PurchaseOrders_Signed folder (organized by year/month)
                $folder = 'PurchaseOrders_Signed/' . date('Y/m');
                $signedPOFileName = "PO_Signed_{$mrf->po_number}_{$mrf->mrf_id}.pdf";
                
                $oneDriveResult = $this->oneDriveService->uploadFile($signedPOFile, $folder, $signedPOFileName);
                $signedPOUrl = $oneDriveResult['webUrl'];
                
                // Create view-only sharing link for the signed PO
                try {
                    $signedPOShareUrl = $this->oneDriveService->createSharingLink($oneDriveResult['path'], 'view');
                    Log::info('OneDrive sharing link created for signed PO', [
                        'mrf_id' => $id,
                        'po_number' => $mrf->po_number,
                        'share_url' => $signedPOShareUrl
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to create sharing link for signed PO', [
                        'error' => $e->getMessage(),
                        'mrf_id' => $id
                    ]);
                }
                
                Log::info('Signed PO uploaded to OneDrive', [
                    'mrf_id' => $id,
                    'po_number' => $mrf->po_number,
                    'onedrive_path' => $oneDriveResult['path'],
                    'web_url' => $signedPOUrl,
                    'share_url' => $signedPOShareUrl,
                ]);
            } catch (\Exception $e) {
                Log::error('OneDrive upload failed for signed PO, falling back to local storage', [
                    'error' => $e->getMessage(),
                    'mrf_id' => $id
                ]);
                $useOneDrive = false;
            }
        }
        
        // Fallback to local/S3 storage if OneDrive is not configured or failed
        if (!$useOneDrive) {
            $disk = config('filesystems.documents_disk', 'public');
        $signedPOFileName = "po_signed_{$mrf->po_number}_" . time() . ".pdf";
        $signedPOPath = "purchase-orders/signed/{$signedPOFileName}";
        Storage::disk($disk)->putFileAs('purchase-orders/signed', $signedPOFile, $signedPOFileName);
        $signedPOUrl = Storage::disk($disk)->url($signedPOPath);
        }

        // Update MRF
        $updateData = [
            'signed_po_url' => $signedPOUrl,
            'po_signed_at' => now(),
            'status' => 'finance',
            'current_stage' => 'finance',
        ];
        
        // Add sharing URL if available (use web URL as fallback)
        if (isset($signedPOShareUrl) && $signedPOShareUrl) {
            $updateData['signed_po_share_url'] = $signedPOShareUrl;
        } elseif ($signedPOUrl) {
            $updateData['signed_po_share_url'] = $signedPOUrl;
        }
        
        $mrf->update($updateData);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'signed_po', 'supply_chain', $user, 'PO signed and uploaded');

        // Notify Finance team
        $this->notificationService->notifyPOSignedToFinance($mrf);

        return response()->json([
            'success' => true,
            'message' => 'Signed PO uploaded successfully',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'signed_po_url' => $mrf->signed_po_url,
                'status' => $mrf->status,
            ]
        ]);
    }

    /**
     * Reject PO (Supply Chain Director returns to procurement for revision)
     */
    public function rejectPO(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can reject POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in supply_chain status
        if ($mrf->status !== 'supply_chain') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending PO signature',
                'code' => 'INVALID_STATUS'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - increment version, clear URLs, return to procurement
        $mrf->update([
            'po_version' => $mrf->po_version + 1,
            'unsigned_po_url' => null,
            'signed_po_url' => null,
            'status' => 'procurement',
            'current_stage' => 'procurement',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'rejected_po', 'supply_chain', $user, $request->reason . ($request->comments ? "\n" . $request->comments : ''));

        // Notify procurement
        $this->notificationService->notifyPORejectedToProcurement($mrf, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'PO rejected and returned to procurement',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'po_version' => $mrf->po_version,
            ]
        ]);
    }

    /**
     * Process payment (Finance marks as ready for chairman payment approval)
     */
    public function processPayment(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Finance team can process payments',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in finance status
        if ($mrf->status !== 'finance') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in finance stage',
                'code' => 'INVALID_STATUS'
            ], 422);
        }

        // Update MRF
        $mrf->update([
            'status' => 'chairman_payment',
            'current_stage' => 'chairman_payment',
            'payment_status' => 'processing',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'payment_processed', 'finance', $user, 'Payment processed and sent for chairman approval');

        // Notify Chairman
        $this->notificationService->notifyPaymentPendingChairman($mrf);

        return response()->json([
            'success' => true,
            'message' => 'Payment sent for chairman approval',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'payment_status' => $mrf->payment_status,
            ]
        ]);
    }

    /**
     * Approve payment (Chairman final approval)
     */
    public function approvePayment(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['chairman', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Chairman can approve payments',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in chairman_payment status
        if ($mrf->status !== 'chairman_payment') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending payment approval',
                'code' => 'INVALID_STATUS'
            ], 422);
        }

        // Update MRF - mark as completed
        $mrf->update([
            'status' => 'completed',
            'current_stage' => 'completed',
            'payment_status' => 'approved',
            'payment_approved_at' => now(),
            'payment_approved_by' => $user->id,
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'payment_approved', 'chairman_payment', $user, 'Payment approved - MRF completed');

        // Notify all stakeholders
        $this->notificationService->notifyMRFCompleted($mrf);

        return response()->json([
            'success' => true,
            'message' => 'Payment approved - MRF workflow completed',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'payment_status' => $mrf->payment_status,
                'completed_at' => $mrf->payment_approved_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Reject MRF (can be done at any approval stage)
     */
    public function rejectMRF(Request $request, $id)
    {
        $user = $request->user();

        // Check if user has permission to reject (executive, chairman, or admin)
        if (!in_array($user->role, ['executive', 'chairman', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions to reject MRF',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF
        $mrf->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'rejection_comments' => $request->comments,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'rejected', $mrf->current_stage, $user, $request->reason . ($request->comments ? "\n" . $request->comments : ''));

        // Notify requester
        $this->notificationService->notifyMRFRejected($mrf, $user, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'MRF rejected',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'rejection_reason' => $mrf->rejection_reason,
            ]
        ]);
    }

    /**
     * Helper: Generate unique PO number
     */
    /**
     * Delete/Clear PO for an MRF (Procurement Manager or Admin)
     * This allows procurement managers/admins to clear a PO and regenerate it
     * Admin can delete PO regardless of MRF status
     */
    public function deletePO(Request $request, $id)
    {
        $user = $request->user();

        // Check role - procurement managers and admin can delete POs
        // Use case-insensitive comparison for role
        $userRole = strtolower(trim($user->role ?? ''));
        $allowedRoles = ['procurement_manager', 'procurement', 'admin'];
        
        if (!in_array($userRole, array_map('strtolower', $allowedRoles))) {
            Log::warning('Unauthorized PO deletion attempt', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'mrf_id' => $id
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers and admins can delete POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $isAdmin = ($userRole === 'admin');

        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if PO exists - be more lenient with whitespace/null checks
        $hasPONumber = !empty(trim($mrf->po_number ?? ''));
        $hasPOUrl = !empty(trim($mrf->unsigned_po_url ?? ''));
        
        if (!$hasPONumber && !$hasPOUrl) {
            Log::info('PO deletion attempted but no PO exists', [
                'mrf_id' => $id,
                'po_number' => $mrf->po_number,
                'po_url_exists' => !empty($mrf->unsigned_po_url)
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'No PO found for this MRF',
                'code' => 'NO_PO',
                'details' => [
                    'mrf_id' => $id,
                    'has_po_number' => $hasPONumber,
                    'has_po_url' => $hasPOUrl
                ]
            ], 422);
        }
        
        // Admin can always delete, but for non-admins, log status check
        if (!$isAdmin) {
            Log::info('PO deletion initiated', [
                'mrf_id' => $id,
                'mrf_status' => $mrf->status,
                'current_stage' => $mrf->current_stage,
                'po_number' => $mrf->po_number,
                'deleted_by' => $user->id,
                'deleted_by_role' => $user->role
            ]);
        }

        // Delete PO files from storage if they exist
        try {
            // Try to delete from OneDrive if configured
            if ($this->oneDriveService && $mrf->unsigned_po_url) {
                try {
                    // Extract path from URL if possible, or use a pattern
                    // OneDrive paths are stored in file_path or can be extracted from URL
                    Log::info('Attempting to delete PO file from OneDrive', [
                        'mrf_id' => $id,
                        'po_url' => $mrf->unsigned_po_url
                    ]);
                    // Note: OneDrive file deletion would require the file path/ID
                    // For now, we'll just clear the database references
                } catch (\Exception $e) {
                    Log::warning('Failed to delete PO from OneDrive', [
                        'error' => $e->getMessage(),
                        'mrf_id' => $id
                    ]);
                }
            }

            // Delete from local/S3 storage if exists
            if ($mrf->unsigned_po_url && !$this->oneDriveService) {
                try {
                    $disk = config('filesystems.documents_disk', 'public');
                    $urlPath = parse_url($mrf->unsigned_po_url, PHP_URL_PATH);
                    
                    if ($urlPath) {
                        // Try multiple path extraction methods
                        $possiblePaths = [
                            ltrim($urlPath, '/storage/'),
                            ltrim(str_replace('/storage/', '', $urlPath), '/'),
                            ltrim($urlPath, '/'),
                            basename($urlPath),
                        ];
                        
                        // Also try extracting from full URL path
                        $baseUrl = Storage::disk($disk)->url('');
                        $pathWithoutBase = str_replace($baseUrl, '', $mrf->unsigned_po_url);
                        if ($pathWithoutBase && $pathWithoutBase !== $mrf->unsigned_po_url) {
                            $possiblePaths[] = ltrim($pathWithoutBase, '/');
                        }
                        
                        $deleted = false;
                        foreach ($possiblePaths as $filePath) {
                            if (empty($filePath)) continue;
                            
                            if (Storage::disk($disk)->exists($filePath)) {
                                Storage::disk($disk)->delete($filePath);
                                Log::info('Deleted PO file from storage', [
                                    'path' => $filePath,
                                    'disk' => $disk,
                                    'mrf_id' => $id
                                ]);
                                $deleted = true;
                                break;
                            }
                        }
                        
                        if (!$deleted) {
                            Log::warning('PO file not found in storage for deletion', [
                                'url' => $mrf->unsigned_po_url,
                                'possible_paths' => $possiblePaths,
                                'disk' => $disk,
                                'mrf_id' => $id
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to delete PO file from storage', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'mrf_id' => $id,
                        'url' => $mrf->unsigned_po_url
                    ]);
                    // Continue - don't fail deletion if file cleanup fails
                }
            }

            // Delete signed PO if exists
            if ($mrf->signed_po_url && !$this->oneDriveService) {
                try {
                    $disk = config('filesystems.documents_disk', 'public');
                    $urlPath = parse_url($mrf->signed_po_url, PHP_URL_PATH);
                    
                    if ($urlPath) {
                        // Try multiple path extraction methods
                        $possiblePaths = [
                            ltrim($urlPath, '/storage/'),
                            ltrim(str_replace('/storage/', '', $urlPath), '/'),
                            ltrim($urlPath, '/'),
                            basename($urlPath),
                        ];
                        
                        $baseUrl = Storage::disk($disk)->url('');
                        $pathWithoutBase = str_replace($baseUrl, '', $mrf->signed_po_url);
                        if ($pathWithoutBase && $pathWithoutBase !== $mrf->signed_po_url) {
                            $possiblePaths[] = ltrim($pathWithoutBase, '/');
                        }
                        
                        foreach ($possiblePaths as $filePath) {
                            if (empty($filePath)) continue;
                            
                            if (Storage::disk($disk)->exists($filePath)) {
                                Storage::disk($disk)->delete($filePath);
                                Log::info('Deleted signed PO file from storage', [
                                    'path' => $filePath,
                                    'disk' => $disk
                                ]);
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to delete signed PO file', [
                        'error' => $e->getMessage(),
                        'url' => $mrf->signed_po_url
                    ]);
                    // Continue - don't fail deletion if file cleanup fails
                }
            }
        } catch (\Exception $e) {
            Log::error('Error deleting PO files', [
                'error' => $e->getMessage(),
                'mrf_id' => $id
            ]);
            // Continue with clearing database fields even if file deletion fails
        }

        // Determine what status to reset to based on MRF workflow position
        $statusLower = strtolower(trim($mrf->status ?? ''));
        $currentStageLower = strtolower(trim($mrf->current_stage ?? ''));
        
        // Reset status based on where MRF is in workflow
        $newStatus = $mrf->status;
        $newStage = $mrf->current_stage;
        
        // If MRF is in supply_chain or beyond (but not completed/paid), reset to procurement
        if (in_array($statusLower, ['supply_chain', 'po rejected', 'finance']) || 
            in_array($currentStageLower, ['supply_chain', 'finance'])) {
            $newStatus = 'procurement';
            $newStage = 'procurement';
        } elseif (in_array($statusLower, ['completed', 'paid', 'chairman_payment'])) {
            // If already completed/paid, keep status but reset stage to procurement
            // This allows admin to clear PO even from completed MRFs
            $newStage = 'procurement';
            // Only reset status if admin (more permissive)
            if ($isAdmin) {
                $newStatus = 'procurement';
            }
        }

        // Clear PO-related fields
        $updateData = [
            'po_number' => null,
            'unsigned_po_url' => null,
            'unsigned_po_share_url' => null,
            'signed_po_url' => null,
            'signed_po_share_url' => null,
            'po_generated_at' => null,
            'po_signed_at' => null,
            'po_version' => 1,
            'po_rejection_reason' => null,
            'status' => $newStatus,
            'current_stage' => $newStage,
        ];

        try {
            $mrf->update($updateData);
            
            Log::info('PO deleted successfully', [
                'mrf_id' => $id,
                'old_status' => $mrf->getOriginal('status'),
                'new_status' => $newStatus,
                'old_stage' => $mrf->getOriginal('current_stage'),
                'new_stage' => $newStage,
                'deleted_by' => $user->id,
                'deleted_by_role' => $user->role,
                'was_admin' => $isAdmin
            ]);

            // Record in approval history
            try {
                MRFApprovalHistory::record($mrf, 'po_deleted', 'procurement', $user, 'PO deleted for regeneration' . ($isAdmin ? ' (admin override)' : ''));
            } catch (\Exception $e) {
                Log::warning('Failed to record PO deletion in approval history', [
                    'error' => $e->getMessage(),
                    'mrf_id' => $id
                ]);
            }

            // Notify relevant parties (only if MRF is back in procurement stage)
            if ($newStatus === 'procurement' || $newStage === 'procurement') {
                try {
                    $this->notificationService->notifyMRFPendingProcurement($mrf);
                } catch (\Exception $e) {
                    Log::warning('Failed to send notification after PO deletion', ['error' => $e->getMessage()]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'PO deleted successfully. MRF is now ready for PO regeneration.',
                'data' => [
                    'mrf_id' => $mrf->mrf_id,
                    'status' => $mrf->status,
                    'current_stage' => $mrf->current_stage,
                    'previous_status' => $mrf->getOriginal('status'),
                    'previous_stage' => $mrf->getOriginal('current_stage'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update MRF after PO deletion', [
                'mrf_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'PO files deleted but failed to update MRF: ' . $e->getMessage(),
                'code' => 'UPDATE_FAILED'
            ], 500);
        }
    }

    /**
     * Generate unique PO number automatically
     * Format: PO-YYYY-MMDD-HHMMSS-XXXXX
     * Ensures uniqueness by using timestamp and MRF ID suffix
     */
    private function generatePONumber(MRF $mrf): string
    {
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $hour = date('H');
        $minute = date('i');
        $second = date('s');
        
        // Extract last 5 characters of MRF ID to make it unique to the request
        $mrfIdSuffix = substr(str_replace('-', '', $mrf->mrf_id), -5);
        $mrfIdSuffix = strtoupper($mrfIdSuffix);
        
        // Format: PO-YYYY-MMDD-HHMMSS-XXXXX (e.g., PO-2026-0115-143052-A1B2C)
        // This ensures uniqueness with timestamp + MRF ID suffix
        $poNumber = "PO-{$year}-{$month}{$day}-{$hour}{$minute}{$second}-{$mrfIdSuffix}";
        
        // Double-check uniqueness - if somehow duplicate exists, add sequence
        $existingMRF = MRF::where('po_number', $poNumber)
            ->where('id', '!=', $mrf->id)
            ->first();
            
        if ($existingMRF) {
            // Very rare case - add sequence number
            $sequence = 1;
            $lastPO = MRF::where('po_number', 'like', "{$poNumber}-%")
                ->orderBy('po_number', 'desc')
                ->first();
            
            if ($lastPO && preg_match('/-(\d+)$/', $lastPO->po_number, $matches)) {
                $sequence = (int) $matches[1] + 1;
            }
            
            $poNumber = "{$poNumber}-{$sequence}";
        }

        return $poNumber;
    }

    /**
     * Helper: Generate PO document (PDF)
     */
    private function generatePODocument(MRF $mrf, string $poNumber): string
    {
        // TODO: Implement actual PDF generation using library like dompdf or snappy
        // For now, return a placeholder
        $content = "Purchase Order: {$poNumber}\n";
        $content .= "MRF: {$mrf->mrf_id}\n";
        $content .= "Title: {$mrf->title}\n";
        $content .= "Estimated Cost: {$mrf->currency} {$mrf->estimated_cost}\n";
        $content .= "\nItems:\n";
        
        foreach ($mrf->items as $item) {
            $content .= "- {$item->item_name} x {$item->quantity} {$item->unit}\n";
        }
        
        return $content;
    }
}
