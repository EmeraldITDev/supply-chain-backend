<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\NotificationService;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use App\Services\OneDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MRFController extends Controller
{
    protected NotificationService $notificationService;
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;
    protected ?OneDriveService $oneDriveService;

    public function __construct(
        NotificationService $notificationService,
        WorkflowStateService $workflowService,
        PermissionService $permissionService
    ) {
        $this->notificationService = $notificationService;
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
        
        // Initialize OneDriveService if configured
        try {
            if (config('filesystems.disks.onedrive.client_id') && 
                config('filesystems.disks.onedrive.client_secret') &&
                config('filesystems.disks.onedrive.tenant_id')) {
                $this->oneDriveService = app(OneDriveService::class);
            } else {
                $this->oneDriveService = null;
            }
        } catch (\Exception $e) {
            Log::warning('OneDriveService initialization failed in MRFController', ['error' => $e->getMessage()]);
            $this->oneDriveService = null;
        }
    }
    /**
     * Get all MRFs with optional filters
     */
    public function index(Request $request)
    {
        $query = MRF::with('requester');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search in title/description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sortBy', 'date');
        $sortOrder = $request->get('sortOrder', 'desc');
        
        $allowedSortFields = ['date', 'estimated_cost', 'title', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('date', 'desc');
        }

        // Filter by requester (for employees to see only their own)
        $user = $request->user();
        if ($user && in_array($user->role, ['employee', 'general_employee'])) {
            $query->where('requester_id', $user->id);
        }

        $mrfs = $query->get();

        return response()->json($mrfs->map(function($mrf) {
            return [
                'id' => $mrf->mrf_id,
                'title' => $mrf->title,
                'category' => $mrf->category,
                'urgency' => $mrf->urgency,
                'description' => $mrf->description,
                'quantity' => $mrf->quantity,
                'estimatedCost' => (float) $mrf->estimated_cost,
                'justification' => $mrf->justification,
                'requester' => $mrf->requester_name,
                'requesterId' => (string) $mrf->requester_id,
                'date' => $mrf->date->format('Y-m-d'),
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'workflowState' => $mrf->workflow_state,
                'approvalHistory' => $mrf->approval_history ?? [],
                'rejectionReason' => $mrf->rejection_reason,
                'isResubmission' => $mrf->is_resubmission,
                'pfiUrl' => $mrf->pfi_url,
                'pfiShareUrl' => $mrf->pfi_share_url,
                'grnRequested' => $mrf->grn_requested,
                'grnRequestedAt' => $mrf->grn_requested_at?->toIso8601String(),
                'grnCompleted' => $mrf->grn_completed,
                'grnCompletedAt' => $mrf->grn_completed_at?->toIso8601String(),
                'grnUrl' => $mrf->grn_url,
                'grnShareUrl' => $mrf->grn_share_url,
                'executive_approved' => $mrf->executive_approved ?? false,
                'executive_approved_at' => $mrf->executive_approved_at?->toIso8601String(),
                'executive_remarks' => $mrf->executive_remarks,
                'chairman_approved' => $mrf->chairman_approved ?? false,
                'chairman_approved_at' => $mrf->chairman_approved_at?->toIso8601String(),
                'chairman_remarks' => $mrf->chairman_remarks,
            ];
        }));
    }

    /**
     * Get single MRF by ID
     */
    public function show($id)
    {
        $mrf = MRF::where('mrf_id', $id)->with('requester')->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'id' => $mrf->mrf_id,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'urgency' => $mrf->urgency,
            'description' => $mrf->description,
            'quantity' => $mrf->quantity,
            'estimatedCost' => (float) $mrf->estimated_cost,
            'justification' => $mrf->justification,
            'requester' => $mrf->requester_name,
            'requesterId' => (string) $mrf->requester_id,
            'date' => $mrf->date->format('Y-m-d'),
            'status' => $mrf->status,
            'currentStage' => $mrf->current_stage,
            'approvalHistory' => $mrf->approval_history ?? [],
            'rejectionReason' => $mrf->rejection_reason,
            'isResubmission' => $mrf->is_resubmission,
            'remarks' => $mrf->remarks,
        ]);
    }

    /**
     * Create new MRF
     * Only employees (staff) can create MRF
     */
    public function store(Request $request)
    {
        // Only employees can create MRF
        $user = $request->user();
        if (!$user || $user->role !== 'employee') {
            return response()->json([
                'success' => false,
                'error' => 'Only staff members can create Material Request Forms. Please contact your administrator.',
            ], 403);
        }

        try {
            // Normalize urgency to proper case
            if ($request->has('urgency') && $request->urgency) {
                $request->merge([
                    'urgency' => ucfirst(strtolower($request->urgency))
                ]);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'category' => 'required|string|max:255',
                'urgency' => 'required|in:Low,Medium,High,Critical',
                'description' => 'required|string',
                'quantity' => 'required|string',
                'estimatedCost' => 'required|numeric|min:0',
                'justification' => 'required|string',
                'pfi' => 'nullable|file|mimes:pdf,doc,docx|max:10240', // Optional PFI upload (10MB max)
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'code' => 'VALIDATION_ERROR'
                ], 422);
            }

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                    'code' => 'UNAUTHENTICATED'
                ], 401);
            }

            // Generate MRF ID first
            $mrfId = MRF::generateMRFId();
            
            // Handle PFI upload if provided
            $pfiUrl = null;
            $pfiShareUrl = null;
            
            if ($request->hasFile('pfi')) {
                $pfiFile = $request->file('pfi');
                
                // Upload to OneDrive if configured
                if ($this->oneDriveService) {
                    try {
                        $year = date('Y');
                        $folder = "MRFs/{$year}/{$mrfId}";
                        $pfiFileName = "PFI_{$mrfId}." . $pfiFile->getClientOriginalExtension();
                        
                        $oneDriveResult = $this->oneDriveService->uploadFile($pfiFile, $folder, $pfiFileName);
                        $pfiUrl = $oneDriveResult['webUrl'];
                        
                        // Create sharing link
                        try {
                            $pfiShareUrl = $this->oneDriveService->createSharingLink($oneDriveResult['path'], 'view');
                        } catch (\Exception $e) {
                            Log::warning('Failed to create PFI sharing link', [
                                'error' => $e->getMessage(),
                                'mrf_id' => $mrfId
                            ]);
                        }
                        
                        Log::info('PFI uploaded to OneDrive', [
                            'mrf_id' => $mrfId,
                            'onedrive_path' => $oneDriveResult['path'],
                            'web_url' => $pfiUrl,
                            'share_url' => $pfiShareUrl,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('OneDrive PFI upload failed, falling back to local storage', [
                            'error' => $e->getMessage(),
                            'mrf_id' => $mrfId
                        ]);
                        // Fallback to local storage
                        $disk = config('filesystems.documents_disk', 'public');
                        $pfiFileName = "pfi_{$mrfId}_" . time() . "." . $pfiFile->getClientOriginalExtension();
                        $pfiPath = "mrfs/{$mrfId}/{$pfiFileName}";
                        $pfiFile->storeAs(dirname($pfiPath), basename($pfiPath), $disk);
                        $pfiUrl = Storage::disk($disk)->url($pfiPath);
                        $pfiShareUrl = $pfiUrl; // Use same URL as share URL for local storage
                    }
                } else {
                    // Local storage fallback
                    $disk = config('filesystems.documents_disk', 'public');
                    $mrfId = MRF::generateMRFId();
                    $pfiFileName = "pfi_{$mrfId}_" . time() . "." . $pfiFile->getClientOriginalExtension();
                    $pfiPath = "mrfs/{$mrfId}/{$pfiFileName}";
                    $pfiFile->storeAs(dirname($pfiPath), basename($pfiPath), $disk);
                    $pfiUrl = Storage::disk($disk)->url($pfiPath);
                    $pfiShareUrl = $pfiUrl;
                }
            }

            $mrf = MRF::create([
                'mrf_id' => $mrfId,
                'title' => $request->title,
                'category' => $request->category,
                'urgency' => $request->urgency,
                'description' => $request->description,
                'quantity' => $request->quantity,
                'estimated_cost' => $request->estimatedCost,
                'justification' => $request->justification,
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'date' => now(),
                'status' => 'pending',
                'current_stage' => 'executive_review',
                'workflow_state' => WorkflowStateService::STATE_MRF_CREATED,
                'approval_history' => [],
                'is_resubmission' => false,
                'pfi_url' => $pfiUrl,
                'pfi_share_url' => $pfiShareUrl,
            ]);

            // Send notification to Executive
            try {
                $this->notificationService->notifyMRFSubmitted($mrf);
            } catch (\Exception $e) {
                // Log notification error but don't fail the request
                \Log::error('Failed to send MRF notification', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'urgency' => $mrf->urgency,
                    'description' => $mrf->description,
                    'quantity' => $mrf->quantity,
                    'estimatedCost' => (float) $mrf->estimated_cost,
                    'justification' => $mrf->justification,
                    'requester' => $mrf->requester_name,
                    'requesterId' => (string) $mrf->requester_id,
                    'date' => $mrf->date->format('Y-m-d'),
                    'status' => $mrf->status,
                    'currentStage' => $mrf->current_stage,
                    'workflowState' => $mrf->workflow_state,
                    'approvalHistory' => $mrf->approval_history ?? [],
                    'rejectionReason' => $mrf->rejection_reason,
                    'isResubmission' => $mrf->is_resubmission,
                    'pfiUrl' => $mrf->pfi_url,
                    'pfiShareUrl' => $mrf->pfi_share_url,
                ]
            ], 201);
        } catch (\Exception $e) {
            \Log::error('MRF creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create MRF',
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the MRF',
                'code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Update existing MRF
     */
    public function update(Request $request, $id)
    {
        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Only allow updates if status is Pending or Rejected
        if (!in_array($mrf->status, ['Pending', 'Rejected'])) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot update MRF in current status',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'urgency' => 'sometimes|required|in:Low,Medium,High,Critical',
            'description' => 'sometimes|required|string',
            'quantity' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|required|numeric|min:0',
            'justification' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $updateData = [];
        if ($request->has('title')) $updateData['title'] = $request->title;
        if ($request->has('category')) $updateData['category'] = $request->category;
        if ($request->has('urgency')) $updateData['urgency'] = $request->urgency;
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('estimatedCost')) $updateData['estimated_cost'] = $request->estimatedCost;
        if ($request->has('justification')) $updateData['justification'] = $request->justification;

        // If updating from Rejected, reset status to Pending
        if ($mrf->status === 'Rejected') {
            $updateData['status'] = 'Pending';
            $updateData['rejection_reason'] = null;
            $updateData['is_resubmission'] = true;
        }

        $mrf->update($updateData);
        $mrf->refresh();

        return response()->json([
            'id' => $mrf->mrf_id,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'urgency' => $mrf->urgency,
            'description' => $mrf->description,
            'quantity' => $mrf->quantity,
            'estimatedCost' => (float) $mrf->estimated_cost,
            'justification' => $mrf->justification,
            'requester' => $mrf->requester_name,
            'requesterId' => (string) $mrf->requester_id,
            'date' => $mrf->date->format('Y-m-d'),
            'status' => $mrf->status,
            'currentStage' => $mrf->current_stage,
            'approvalHistory' => $mrf->approval_history ?? [],
            'rejectionReason' => $mrf->rejection_reason,
            'isResubmission' => $mrf->is_resubmission,
        ]);
    }

    /**
     * Approve MRF
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user has permission (procurement or finance role)
        if (!in_array($user->role, ['procurement', 'finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
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

        if ($mrf->status !== 'Pending') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in Pending status',
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

        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'approved_by' => $user->name,
            'approved_by_id' => $user->id,
            'approved_at' => now()->toIso8601String(),
            'remarks' => $request->remarks,
            'stage' => $mrf->current_stage,
        ];

        $mrf->update([
            'status' => 'Approved',
            'current_stage' => 'finance', // Move to next stage
            'approval_history' => $approvalHistory,
            'remarks' => $request->remarks,
        ]);

        // Send notification to requester
        $this->notificationService->notifyMRFApproved($mrf, $user, $request->remarks);

        return response()->json([
            'success' => true,
            'message' => 'MRF approved successfully',
            'mrf' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
            ]
        ]);
    }

    /**
     * Reject MRF
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user has permission
        if (!in_array($user->role, ['procurement', 'finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $mrf->update([
            'status' => 'Rejected',
            'rejection_reason' => $request->reason,
        ]);

        // Send notification to requester
        $this->notificationService->notifyMRFRejected($mrf, $user, $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'MRF rejected',
            'mrf' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'rejectionReason' => $mrf->rejection_reason,
            ]
        ]);
    }

    /**
     * Generate PO for approved MRF (Procurement Manager)
     */
    public function generatePO(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user has procurement permission
        if (!in_array($user->role, ['procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Procurement Managers can generate POs',
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

        // Validate request
        $validator = Validator::make($request->all(), [
            'po_number' => 'required|string|max:255',
            'unsigned_po' => 'required|file|mimes:pdf,doc,docx|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Handle file upload
        $unsignedPoPath = null;
        if ($request->hasFile('unsigned_po')) {
            $file = $request->file('unsigned_po');
            $fileName = time() . '_' . $file->getClientOriginalName();
            
            // Store in public disk (configured to storage/app/public)
            $disk = config('filesystems.documents_disk', 'public');
            $path = $file->storeAs('documents/pos', $fileName, $disk);
            $unsignedPoPath = $path;
            
            Log::info('PO file uploaded', [
                'mrf_id' => $id,
                'file_name' => $fileName,
                'path' => $path,
                'disk' => $disk
            ]);
        }

        // Update MRF with PO details
        $mrf->update([
            'po_number' => $request->po_number,
            'unsigned_po_url' => $unsignedPoPath,
            'status' => 'PO Generated',
            'current_stage' => 'supply_chain',
        ]);

        // Add to approval history
        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'action' => 'PO Generated',
            'by' => $user->name,
            'by_id' => $user->id,
            'at' => now()->toIso8601String(),
            'po_number' => $request->po_number,
        ];
        $mrf->approval_history = $approvalHistory;
        $mrf->save();

        // Notify Supply Chain Director
        try {
            $this->notificationService->notifyPOGenerated($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send PO generation notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase Order generated successfully',
            'data' => [
                'id' => $mrf->mrf_id,
                'poNumber' => $mrf->po_number,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'unsignedPoUrl' => $unsignedPoPath,
            ]
        ], 200);
    }

    /**
     * Upload Signed PO (Supply Chain Director)
     */
    public function uploadSignedPO(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user has supply chain permission
        if (!in_array($user->role, ['supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can upload signed POs',
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

        // Validate request
        $validator = Validator::make($request->all(), [
            'signed_po' => 'required|file|mimes:pdf|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Handle file upload
        $signedPoPath = null;
        if ($request->hasFile('signed_po')) {
            $file = $request->file('signed_po');
            $fileName = time() . '_signed_' . $file->getClientOriginalName();
            
            $disk = config('filesystems.documents_disk', 'public');
            $path = $file->storeAs('documents/pos/signed', $fileName, $disk);
            $signedPoPath = $path;
            
            Log::info('Signed PO uploaded', [
                'mrf_id' => $id,
                'file_name' => $fileName,
                'path' => $path
            ]);
        }

        // Update MRF
        $mrf->update([
            'signed_po_url' => $signedPoPath,
            'status' => 'PO Signed',
            'current_stage' => 'finance',
        ]);

        // Add to approval history
        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'action' => 'PO Signed',
            'by' => $user->name,
            'by_id' => $user->id,
            'at' => now()->toIso8601String(),
        ];
        $mrf->approval_history = $approvalHistory;
        $mrf->save();

        // Notify Finance team
        try {
            $this->notificationService->notifyPOSigned($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send PO signed notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Signed PO uploaded successfully',
            'data' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'signedPoUrl' => $signedPoPath,
            ]
        ]);
    }

    /**
     * Reject PO (Supply Chain Director)
     */
    public function rejectPO(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user has supply chain permission
        if (!in_array($user->role, ['supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can reject POs',
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

        // Validate request
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - send back to procurement
        $mrf->update([
            'status' => 'PO Rejected',
            'current_stage' => 'procurement',
            'rejection_reason' => $request->reason,
        ]);

        // Add to approval history
        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'action' => 'PO Rejected',
            'by' => $user->name,
            'by_id' => $user->id,
            'at' => now()->toIso8601String(),
            'reason' => $request->reason,
        ];
        $mrf->approval_history = $approvalHistory;
        $mrf->save();

        // Notify Procurement
        try {
            $this->notificationService->notifyPORejected($mrf, $user, $request->reason);
        } catch (\Exception $e) {
            Log::error('Failed to send PO rejection notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'PO rejected successfully',
            'data' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'rejectionReason' => $mrf->rejection_reason,
            ]
        ]);
    }

    /**
     * Delete MRF
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if user is the requester
        $isRequester = $mrf->requester_id == $user->id;
        
        // Allow deletion if:
        // 1. User is the requester AND MRF hasn't progressed beyond procurement stage (no PO generated)
        // 2. OR status is Pending or Rejected (for any user with appropriate permissions)
        $canDelete = false;
        
        if ($isRequester) {
            // Requester can delete if no PO has been generated yet
            $noPOGenerated = empty($mrf->po_number) && empty($mrf->unsigned_po_url);
            $notTooFarInWorkflow = !in_array(strtolower($mrf->status), ['supply_chain', 'finance', 'paid', 'completed']);
            
            if ($noPOGenerated && $notTooFarInWorkflow) {
                $canDelete = true;
            }
        }
        
        // Also allow deletion for pending/rejected statuses (original logic)
        if (!$canDelete && in_array($mrf->status, ['Pending', 'Rejected', 'pending', 'rejected'])) {
            $canDelete = true;
        }

        if (!$canDelete) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete MRF. Either you are not the requester, or the MRF has progressed too far in the workflow.',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf->delete();

        return response()->json([
            'success' => true,
            'message' => 'MRF deleted successfully'
        ]);
    }
}
