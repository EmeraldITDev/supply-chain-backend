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

        // Check if MRF is in correct status (case-insensitive)
        $statusLower = strtolower($mrf->status);
        if ($statusLower !== 'pending' && $statusLower !== 'executive approval' && $statusLower !== 'executive_review') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending executive approval',
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

        // Determine next status based on estimated cost
        $nextStatus = $mrf->estimated_cost > 1000000 ? 'chairman_review' : 'procurement';
        $nextStage = $mrf->estimated_cost > 1000000 ? 'chairman_review' : 'procurement';

        // Update MRF
        $mrf->update([
            'executive_approved' => true,
            'executive_approved_by' => $user->id,
            'executive_approved_at' => now(),
            'executive_remarks' => $request->remarks,
            'status' => $nextStatus,
            'current_stage' => $nextStage,
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'approved', 'executive_review', $user, $request->remarks);

        // Send notifications
        if ($nextStatus === 'chairman_review') {
            $this->notificationService->notifyMRFPendingChairmanApproval($mrf);
        } else {
            $this->notificationService->notifyMRFPendingProcurement($mrf);
        }

        return response()->json([
            'success' => true,
            'message' => 'MRF approved by executive',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'current_stage' => $mrf->current_stage,
                'next_approver' => $nextStatus === 'chairman_review' ? 'Chairman' : 'Procurement Manager',
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
        $allowedStatuses = ['procurement', 'PO Rejected'];
        if (!in_array($mrf->status, $allowedStatuses)) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in procurement stage. Current status: ' . $mrf->status,
                'code' => 'INVALID_STATUS'
            ], 422);
        }

        // Allow PO regeneration if it was rejected
        $isRegeneration = !empty($mrf->po_number) && $mrf->status === 'PO Rejected';

        // If not a regeneration, check if PO already exists
        if (!$isRegeneration && $mrf->po_number) {
            return response()->json([
                'success' => false,
                'error' => 'PO already generated for this MRF',
                'code' => 'DUPLICATE_PO',
                'data' => [
                    'existing_po_number' => $mrf->po_number,
                    'po_url' => $mrf->unsigned_po_url
                ]
            ], 422);
        }

        // Validate - allow same PO number for regeneration (ignore unique check for this MRF)
        $rules = [
            'unsigned_po' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10MB max
            'remarks' => 'nullable|string',
        ];

        // Only validate PO number uniqueness if not regenerating
        if (!$isRegeneration) {
            $rules['po_number'] = 'nullable|string|max:50|unique:m_r_f_s,po_number';
        } else {
            $rules['po_number'] = 'nullable|string|max:50';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Auto-generate PO number if not provided, or reuse existing for regeneration
        $poNumber = $request->po_number ?? $mrf->po_number ?? $this->generatePONumber();

        // Handle file upload
        $poUrl = null;
        $poShareUrl = null;
        $useOneDrive = $this->oneDriveService !== null;
        
        if ($request->hasFile('unsigned_po')) {
            $file = $request->file('unsigned_po');
            
            // Use OneDrive if configured
            if ($useOneDrive) {
                try {
                    // Delete old PO file from OneDrive if regenerating
                    // Extract path from URL if it exists (for legacy files)
                    if ($isRegeneration && $mrf->unsigned_po_url) {
                        // Try to extract path from URL or use a pattern
                        Log::info('Attempting to delete old PO file from OneDrive', [
                            'po_url' => $mrf->unsigned_po_url
                        ]);
                    }

                    // Upload to OneDrive in PurchaseOrders folder (organized by year/month)
                    $folder = 'PurchaseOrders/' . date('Y/m');
                    $poFileName = "PO_{$poNumber}_{$mrf->mrf_id}." . $file->getClientOriginalExtension();
                    
                    $oneDriveResult = $this->oneDriveService->uploadFile($file, $folder, $poFileName);
                    
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
                        Storage::disk($disk)->delete($oldPath);
                        Log::info('Deleted old PO file for regeneration', ['old_path' => $oldPath]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete old PO file', ['error' => $e->getMessage()]);
                    }
                }

                $disk = config('filesystems.documents_disk', 'public');
                $poFileName = "po_{$poNumber}_" . time() . "." . $file->getClientOriginalExtension();
        $poPath = "purchase-orders/{$poFileName}";
                
                // Store the file
                $file->storeAs(dirname($poPath), basename($poPath), $disk);
        $poUrl = Storage::disk($disk)->url($poPath);
                
                Log::info($isRegeneration ? 'PO file regenerated' : 'PO file uploaded', [
                    'mrf_id' => $id,
                    'po_number' => $poNumber,
                    'file_name' => $poFileName,
                    'path' => $poPath,
                    'disk' => $disk,
                    'is_regeneration' => $isRegeneration
                ]);
            }
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

        // Check if MRF is in supply_chain status
        if ($mrf->status !== 'supply_chain') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not pending PO signature',
                'code' => 'INVALID_STATUS'
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
    private function generatePONumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        // Format: PO-YYYY-MM-XXX (e.g., PO-2026-01-001)
        $prefix = "PO-{$year}-{$month}";
        
        $lastPO = MRF::where('po_number', 'like', "{$prefix}-%")
            ->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO && preg_match('/-(\d+)$/', $lastPO->po_number, $matches)) {
            $lastNumber = (int) $matches[1];
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "{$prefix}-{$newNumber}";
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
