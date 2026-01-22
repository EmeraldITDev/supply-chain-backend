<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GRNController extends Controller
{
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;
    protected NotificationService $notificationService;

    public function __construct(
        WorkflowStateService $workflowService,
        PermissionService $permissionService,
        NotificationService $notificationService
    ) {
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
        $this->notificationService = $notificationService;
    }
    
    /**
     * Get the storage disk for documents
     */
    protected function getStorageDisk(): string
    {
        return config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
    }
    
    /**
     * Get file URL - for S3 uses temporary signed URL, for local uses public URL
     */
    protected function getFileUrl(string $filePath, string $disk, int $expirationHours = 24): string
    {
        if ($disk === 's3') {
            try {
                return Storage::disk($disk)->temporaryUrl($filePath, now()->addHours($expirationHours));
            } catch (\Exception $e) {
                Log::warning('S3 temporary URL generation failed, using regular URL', [
                    'error' => $e->getMessage(),
                    'path' => $filePath
                ]);
                return Storage::disk($disk)->url($filePath);
            }
        }
        
        // For local/public storage
        $url = Storage::disk($disk)->url($filePath);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $baseUrl = config('app.url');
            return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }
        return $url;
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

        // Upload to S3 storage
        $disk = $this->getStorageDisk();
        $grnFileName = "grn_{$mrf->po_number}_" . time() . "." . $grnFile->getClientOriginalExtension();
        $grnPath = "grns/" . date('Y/m') . "/{$mrf->mrf_id}/{$grnFileName}";
        
        // Ensure directory structure exists (for S3, this is just the path)
        $directory = dirname($grnPath);
        if ($disk !== 's3' && !Storage::disk($disk)->exists($directory)) {
            Storage::disk($disk)->makeDirectory($directory, 0755, true);
        }
        
        $grnFile->storeAs($directory, basename($grnPath), $disk);
        
        // Get URL (temporary signed URL for S3, public URL for local)
        $grnUrl = $this->getFileUrl($grnPath, $disk);
        $grnShareUrl = $grnUrl;
        
        Log::info('GRN uploaded to storage', [
                    'mrf_id' => $mrf->mrf_id,
                    'po_number' => $mrf->po_number,
            'stored_path' => $grnPath,
            'url' => $grnUrl,
            'disk' => $disk
        ]);

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
