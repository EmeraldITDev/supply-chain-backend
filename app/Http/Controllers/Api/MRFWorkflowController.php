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

        // Allow PO regeneration if it was rejected or if PO already exists for this MRF
        $isRegeneration = (!empty($mrf->po_number) && $mrf->status === 'PO Rejected') || 
                         (!empty($mrf->po_number) && !empty($mrf->unsigned_po_url));

        // If not a regeneration and PO already exists, reject
        if (!$isRegeneration && $mrf->po_number && $mrf->unsigned_po_url) {
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

        // Determine PO number FIRST before validation
        // This prevents validation errors when reusing existing PO numbers during regeneration
        $requestPONumber = $request->input('po_number');
        $requestPONumber = $requestPONumber ? trim($requestPONumber) : null;
        
        // If regenerating and no new PO number provided, reuse the existing one
        if ($isRegeneration && empty($requestPONumber)) {
            $poNumber = $mrf->po_number;
            $willReusePO = true;
        } else {
            // Use provided PO number or auto-generate a new one
            $poNumber = $requestPONumber ?? $this->generatePONumber($mrf);
            $willReusePO = false;
            
            // If auto-generating, make sure it's unique (check database)
            if (empty($requestPONumber)) {
                $attempts = 0;
                while (MRF::where('po_number', $poNumber)->where('id', '!=', $mrf->id)->exists() && $attempts < 10) {
                    $poNumber = $this->generatePONumber($mrf);
                    $attempts++;
                }
            } elseif ($requestPONumber) {
                // If PO number was provided, validate it's unique (excluding this MRF for regeneration)
                $existingMRF = MRF::where('po_number', $requestPONumber)
                    ->where('id', '!=', $mrf->id)
                    ->first();
                    
                if ($existingMRF) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Validation failed',
                        'errors' => [
                            'po_number' => ['The PO number has already been taken.']
                        ],
                        'code' => 'VALIDATION_ERROR',
                        'debug' => [
                            'mrf_id' => $id,
                            'request_po_number' => $requestPONumber,
                            'existing_po_number' => $mrf->po_number,
                            'conflicting_mrf_id' => $existingMRF->mrf_id,
                            'is_regeneration' => $isRegeneration
                        ]
                    ], 422);
                }
                $poNumber = $requestPONumber;
            }
        }

        // Validate file upload - be more lenient with file types and increase max size
        $rules = [
            'unsigned_po' => 'required|file|mimes:pdf,doc,docx|max:20480', // 20MB max
            'remarks' => 'nullable|string',
            // Note: po_number is validated manually above, not in validation rules
        ];

        $validator = Validator::make($request->all(), $rules);

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
                'file_size' => $request->hasFile('unsigned_po') ? $request->file('unsigned_po')->getSize() : null,
                'file_mime' => $request->hasFile('unsigned_po') ? $request->file('unsigned_po')->getMimeType() : null,
                'file_error' => $request->hasFile('unsigned_po') ? $request->file('unsigned_po')->getError() : null,
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
                'debug' => [
                    'mrf_id' => $id,
                    'request_po_number' => $requestPONumber ?? null,
                    'determined_po_number' => $poNumber,
                    'existing_po_number' => $mrf->po_number,
                    'is_regeneration' => $isRegeneration,
                    'file_info' => $request->hasFile('unsigned_po') ? [
                        'size' => $request->file('unsigned_po')->getSize(),
                        'mime' => $request->file('unsigned_po')->getMimeType(),
                        'name' => $request->file('unsigned_po')->getClientOriginalName(),
                        'error' => $request->file('unsigned_po')->getError(),
                        'error_message' => $request->file('unsigned_po')->getErrorMessage(),
                    ] : null,
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

                $disk = config('filesystems.documents_disk', 'public');
                $poFileName = "po_{$poNumber}_" . time() . "." . $file->getClientOriginalExtension();
                $poPath = "purchase-orders/{$poFileName}";
                
                // Ensure directory exists
                $directory = dirname($poPath);
                if (!Storage::disk($disk)->exists($directory)) {
                    Storage::disk($disk)->makeDirectory($directory, 0755, true);
                }
                
                // Store the file
                $storedPath = $file->storeAs(dirname($poPath), basename($poPath), $disk);
                
                if (!$storedPath) {
                    throw new \Exception('Failed to store file on disk: ' . $disk);
                }
                
                // Get URL - handle both local and S3
                if ($disk === 'public' || $disk === 'local') {
                    $poUrl = Storage::disk($disk)->url($storedPath);
                } else {
                    // For S3 or other cloud storage
                    $poUrl = Storage::disk($disk)->url($storedPath);
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

    private function generatePONumber(MRF $mrf): string
    {
        $year = date('Y');
        $month = date('m');
        $day = date('d');
        
        // Extract last 6 characters of MRF ID (UUID) to make it unique to the request
        $mrfIdSuffix = substr(str_replace('-', '', $mrf->mrf_id), -6);
        $mrfIdSuffix = strtoupper($mrfIdSuffix);
        
        // Format: PO-MRF-YYYYMMDD-XXXXXX (e.g., PO-MRF-20260115-A1B2C3)
        // This includes: Request type (MRF), Date, and MRF ID suffix for uniqueness
        $poNumber = "PO-MRF-{$year}{$month}{$day}-{$mrfIdSuffix}";
        
        // Check if this PO number already exists (for same MRF regenerating)
        // If it exists and belongs to this MRF, it's fine (regeneration)
        // If it exists for a different MRF, add a sequence number
        $existingMRF = MRF::where('po_number', $poNumber)->first();
        if ($existingMRF && $existingMRF->id !== $mrf->id) {
            // Another MRF has this PO number, add sequence
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
