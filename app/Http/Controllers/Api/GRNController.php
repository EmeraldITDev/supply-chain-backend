<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use App\Services\NotificationService;
use App\Services\OneDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GRNController extends Controller
{
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;
    protected NotificationService $notificationService;
    protected ?OneDriveService $oneDriveService;

    public function __construct(
        WorkflowStateService $workflowService,
        PermissionService $permissionService,
        NotificationService $notificationService
    ) {
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
        $this->notificationService = $notificationService;
        
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
            Log::warning('OneDriveService initialization failed in GRNController', ['error' => $e->getMessage()]);
            $this->oneDriveService = null;
        }
    }

    /**
     * Finance Officer requests GRN
     */
    public function requestGRN(Request $request, $id)
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

        // Check permission
        if (!$this->permissionService->canRequestGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to request GRN',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Check workflow state
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        if ($currentState !== WorkflowStateService::STATE_PAYMENT_PROCESSED) {
            return response()->json([
                'success' => false,
                'error' => 'GRN can only be requested after payment is processed',
                'code' => 'INVALID_STATE',
                'current_state' => $currentState
            ], 422);
        }

        // Update MRF
        $mrf->update([
            'grn_requested' => true,
            'grn_requested_at' => now(),
            'grn_requested_by' => $user->id,
        ]);

        // Transition workflow state
        $this->workflowService->transition($mrf, WorkflowStateService::STATE_GRN_REQUESTED, $user);

        // Notify Procurement Manager
        try {
            $this->notificationService->notifyGRNRequested($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send GRN request notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'GRN requested successfully',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
                'grn_requested' => $mrf->grn_requested,
                'grn_requested_at' => $mrf->grn_requested_at,
            ]
        ]);
    }

    /**
     * Procurement Manager completes GRN
     */
    public function completeGRN(Request $request, $id)
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

        // Check permission
        if (!$this->permissionService->canCompleteGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to complete GRN',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Check workflow state
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        if ($currentState !== WorkflowStateService::STATE_GRN_REQUESTED) {
            return response()->json([
                'success' => false,
                'error' => 'GRN can only be completed when it has been requested',
                'code' => 'INVALID_STATE',
                'current_state' => $currentState
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'grn' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Handle GRN file upload
        $grnFile = $request->file('grn');
        $grnUrl = null;
        $grnShareUrl = null;

        // Upload to OneDrive if configured
        if ($this->oneDriveService) {
            try {
                $year = date('Y');
                $month = date('m');
                $folder = "GRNs/{$year}/{$month}";
                $grnFileName = "GRN_{$mrf->po_number}_{$mrf->mrf_id}." . $grnFile->getClientOriginalExtension();
                
                $oneDriveResult = $this->oneDriveService->uploadFile($grnFile, $folder, $grnFileName);
                $grnUrl = $oneDriveResult['webUrl'];
                
                // Create sharing link
                try {
                    $grnShareUrl = $this->oneDriveService->createSharingLink($oneDriveResult['path'], 'view');
                } catch (\Exception $e) {
                    Log::warning('Failed to create GRN sharing link', [
                        'error' => $e->getMessage(),
                        'mrf_id' => $mrf->mrf_id
                    ]);
                }
                
                Log::info('GRN uploaded to OneDrive', [
                    'mrf_id' => $mrf->mrf_id,
                    'po_number' => $mrf->po_number,
                    'onedrive_path' => $oneDriveResult['path'],
                    'web_url' => $grnUrl,
                    'share_url' => $grnShareUrl,
                ]);
            } catch (\Exception $e) {
                Log::error('OneDrive GRN upload failed, falling back to local storage', [
                    'error' => $e->getMessage(),
                    'mrf_id' => $mrf->mrf_id
                ]);
                // Fallback to local storage
                $disk = config('filesystems.documents_disk', 'public');
                $grnFileName = "grn_{$mrf->po_number}_" . time() . "." . $grnFile->getClientOriginalExtension();
                $grnPath = "grns/{$mrf->mrf_id}/{$grnFileName}";
                $grnFile->storeAs(dirname($grnPath), basename($grnPath), $disk);
                $grnUrl = Storage::disk($disk)->url($grnPath);
                $grnShareUrl = $grnUrl;
            }
        } else {
            // Local storage fallback
            $disk = config('filesystems.documents_disk', 'public');
            $grnFileName = "grn_{$mrf->po_number}_" . time() . "." . $grnFile->getClientOriginalExtension();
            $grnPath = "grns/{$mrf->mrf_id}/{$grnFileName}";
            $grnFile->storeAs(dirname($grnPath), basename($grnPath), $disk);
            $grnUrl = Storage::disk($disk)->url($grnPath);
            $grnShareUrl = $grnUrl;
        }

        // Update MRF
        $mrf->update([
            'grn_completed' => true,
            'grn_completed_at' => now(),
            'grn_completed_by' => $user->id,
            'grn_url' => $grnUrl,
            'grn_share_url' => $grnShareUrl,
        ]);

        // Transition workflow state
        $this->workflowService->transition($mrf, WorkflowStateService::STATE_GRN_COMPLETED, $user);

        // Notify Finance Officer
        try {
            $this->notificationService->notifyGRNCompleted($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send GRN completion notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'GRN completed successfully',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
                'grn_completed' => $mrf->grn_completed,
                'grn_completed_at' => $mrf->grn_completed_at,
                'grn_url' => $mrf->grn_url,
                'grn_share_url' => $mrf->grn_share_url,
            ]
        ]);
    }
}
