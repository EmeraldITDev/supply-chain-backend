<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessPurchaseOrderGenerationJob;
use App\Jobs\ProcessPurchaseOrderNotificationsJob;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\RFQ;
use App\Models\Quotation;
use App\Models\Vendor;
use App\Models\QuotationItem;
use App\Models\POTermsTemplate;
use App\Models\ProcurementDocument;
use App\Services\FinanceAp\FinanceApWorkflowOrchestrator;
use App\Services\NotificationService;
use App\Services\EmailService;
use App\Services\WorkflowNotificationService;
use App\Services\WorkflowStateService;
use App\Support\DatabaseNotifications;
use App\Services\MrfParallelFirstApprovalService;
use Illuminate\Support\Facades\DB;
use App\Services\PermissionService;
use App\Services\PurchaseOrderPdfService;
use App\Services\PaymentScheduleService;
use App\Services\PriceComparisonPoLineService;
use App\Services\ProcurementDocumentService;
use App\Services\QuotationAttachmentService;
use App\Support\PurchaseOrderCurrency;
use App\Support\PurchaseOrderInvoiceCc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MRFWorkflowController extends Controller
{
    protected NotificationService $notificationService;
    protected EmailService $emailService;
    protected WorkflowNotificationService $workflowNotificationService;
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;

    public function __construct(
        NotificationService $notificationService,
        EmailService $emailService,
        WorkflowNotificationService $workflowNotificationService,
        WorkflowStateService $workflowService,
        PermissionService $permissionService
    ) {
        $this->notificationService = $notificationService;
        $this->emailService = $emailService;
        $this->workflowNotificationService = $workflowNotificationService;
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
    }

    /**
     * Get the storage disk for documents
     * Uses S3 for production, configurable via DOCUMENTS_DISK env variable
     */
    protected function getStorageDisk(): string
    {
        return config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
    }

    /**
     * Get file URL - for S3 uses temporary signed URL, for local uses public URL
     * Default expiration is 7 days to prevent URL expiration issues
     */
    protected function getFileUrl(string $filePath, string $disk, int $expirationHours = 168): string
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
     * NEW WORKFLOW: Supply Chain Director approves/rejects MRF
     * First step in the simplified workflow
     *
     * After Supply Chain Director approval → Procurement Manager review
     */
    public function supplyChainDirectorApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role - only supply chain directors can approve
        if (!in_array($user->scmRole(), ['supply_chain_director', 'director', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can approve at this stage',
                'code' => 'FORBIDDEN',
                'requiredRole' => 'supply_chain_director'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $parallelService = app(MrfParallelFirstApprovalService::class);

        return DB::transaction(function () use ($request, $user, $mrf, $parallelService) {
            $locked = MRF::where('id', $mrf->id)->lockForUpdate()->first();

            if (! $locked) {
                return response()->json([
                    'success' => false,
                    'error' => 'MRF not found',
                    'code' => 'NOT_FOUND',
                ], 404);
            }

            $validStates = ['supply_chain_director_review', MrfParallelFirstApprovalService::STATE];
            if (! in_array($locked->workflow_state, $validStates, true)) {
                if ($parallelService->isAlreadyApproved($locked)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'This MRF has already been approved.',
                        'code' => 'ALREADY_APPROVED',
                        'currentWorkflowState' => $locked->workflow_state,
                    ], 422);
                }

                return response()->json([
                    'success' => false,
                    'error' => 'MRF is not awaiting Supply Chain Director review',
                    'code' => 'INVALID_WORKFLOW_STATE',
                    'currentWorkflowState' => $locked->workflow_state,
                    'expectedState' => 'supply_chain_director_review',
                ], 422);
            }

            $isApproved = $request->action === 'approve';
            $isParallel = $parallelService->isParallelPending($locked);

            // Determine next stage based on contract type and value
            $nextStage = 'procurement_review';
            $nextWorkflowState = 'supply_chain_director_approved';
            $isHighValueCustomType = false;

            if ($isApproved) {
                $isCustomType = $locked->routed_reason === 'custom_contract_type'
                    || $locked->routed_reason === 'parallel_first_approval_custom';
                $estimatedCost = (float) ($locked->estimated_cost ?? 0);
                $isHighValue = $estimatedCost > 1000000;

                if ($isCustomType && $isHighValue) {
                    $nextStage = 'lazarus_director_approval';
                    $nextWorkflowState = 'lazarus_director_approval';
                    $isHighValueCustomType = true;
                }
            } else {
                $nextStage = 'rejected';
                $nextWorkflowState = 'supply_chain_director_rejected';
            }

            $locked->update([
                'status' => $isApproved ? ($isHighValueCustomType ? 'lazarus_director_approval' : 'procurement_review') : 'rejected',
                'current_stage' => $nextStage,
                'workflow_state' => $nextWorkflowState,
                'remarks' => $request->remarks,
                'director_approved_at' => $isApproved ? now() : null,
                'director_approved_by' => $isApproved ? $user->name : null,
                'director_remarks' => $isApproved ? $request->remarks : null,
                'procurement_review_started_at' => $isApproved && ! $isHighValueCustomType ? now() : null,
                'last_action_by_role' => in_array($user->scmRole(), ['admin']) ? 'admin' : 'supply_chain_director',
                'first_approval_by_role' => $isApproved && $isParallel
                    ? MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR
                    : $locked->first_approval_by_role,
            ]);

            try {
                $locked->load('requester');

                if ($isApproved && $isHighValueCustomType) {
                    $this->notificationService->notifyLazarusDirectorApprovalPending($locked, $user, $request->remarks);
                } elseif ($isApproved) {
                    $this->notificationService->notifyMRFApproved($locked, $user, $request->remarks);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to send MRF approved notification', [
                    'mrf_id' => $locked->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $approvalRecord = MRFApprovalHistory::create([
                'mrf_id' => $locked->id,
                'stage' => 'supply_chain_director',
                'action' => $isApproved ? 'approved' : 'rejected',
                'approver_id' => $user->id,
                'approver_name' => $user->name,
                'approver_email' => $user->email,
                'remarks' => $request->remarks,
                'performer_name' => $user->name,
                'performer_role' => $user->scmRole(),
                'performed_by' => $user->id,
            ]);

            try {
                Activity::create([
                    'type' => 'mrf_approved',
                    'title' => $isApproved ? 'MRF Approved by Supply Chain Director' : 'MRF Rejected by Supply Chain Director',
                    'description' => $isApproved ?
                        "MRF {$locked->mrf_id} was approved by Supply Chain Director {$user->name} and forwarded to Procurement Manager review" :
                        "MRF {$locked->mrf_id} was rejected by Supply Chain Director {$user->name}",
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'reference_type' => 'mrf',
                    'reference_id' => $locked->mrf_id,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to log activity', ['error' => $e->getMessage()]);
            }

            $approvalMessage = $isApproved
                ? ($isHighValueCustomType
                    ? 'MRF approved by Supply Chain Director and forwarded to Director (Lazarus Angbazo) for high-value authorization'
                    : 'Approved by Supply Chain Director')
                : 'MRF rejected';

            return response()->json([
                'success' => true,
                'message' => $approvalMessage,
                'data' => [
                    'mrfId' => $locked->mrf_id,
                    'status' => $locked->status,
                    'workflowState' => $locked->workflow_state,
                    'currentStage' => $locked->current_stage,
                    'firstApprovalByRole' => $locked->first_approval_by_role,
                    'firstApprovalStatusLabel' => MrfParallelFirstApprovalService::statusLabelForRole($locked->first_approval_by_role),
                    'approvalRecord' => $approvalRecord,
                    'isHighValueCustomType' => $isHighValueCustomType,
                ],
            ]);
        });
    }
    /**
     * Procurement Manager approves MRF after Supply Chain Director approval
     * Issues RFQs to identified vendors
     *
     * Updated for new simplified workflow
     */
    public function lazarusDirectorApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role - only Lazarus Angbazo or admin can approve at this stage
        if (!in_array($user->scmRole(), ['director', 'admin']) && $user->email !== 'lazarus.angbazo@emeraldcfze.com') {
            return response()->json([
                'success' => false,
                'error' => 'Only Lazarus Angbazo (Director) can approve high-value custom contract MRFs at this stage',
                'code' => 'FORBIDDEN',
                'requiredRole' => 'director (Lazarus Angbazo)'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in pending lazarus director review
        if ($mrf->workflow_state !== 'lazarus_director_approval') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not awaiting Lazarus Director approval',
                'code' => 'INVALID_WORKFLOW_STATE',
                'currentWorkflowState' => $mrf->workflow_state,
                'expectedState' => 'lazarus_director_approval'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $isApproved = $request->action === 'approve';

        // Update MRF - route to procurement_review after Lazarus approval
        $mrf->update([
            'status' => $isApproved ? 'procurement_review' : 'rejected',
            'current_stage' => $isApproved ? 'procurement_review' : 'rejected',
            'workflow_state' => $isApproved ? 'supply_chain_director_approved' : 'supply_chain_director_rejected',
            'remarks' => $request->remarks,
            'director_approved_at' => $isApproved ? now() : null,
            'director_approved_by' => $isApproved ? $user->name : null,
            'director_remarks' => $isApproved ? $request->remarks : null,
            'procurement_review_started_at' => $isApproved ? now() : null,
            'last_action_by_role' => in_array($user->scmRole(), ['admin']) ? 'admin' : 'director',
        ]);

        try {
            $mrf->load('requester');
            if ($isApproved) {
                $this->notificationService->notifyMRFApproved($mrf, $user, $request->remarks);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send MRF approval notification after Lazarus approval', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        // Record approval history
        $approvalRecord = MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'stage' => 'lazarus_director',
            'action' => $isApproved ? 'approved' : 'rejected',
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_email' => $user->email,
            'remarks' => $request->remarks,
            'performer_name' => $user->name,
            'performer_role' => $user->scmRole(),
            'performed_by' => $user->id
        ]);

        // Log activity
        try {
            Activity::create([
                'type' => 'mrf_approved',
                'title' => $isApproved ? 'MRF Approved by Lazarus Director' : 'MRF Rejected by Lazarus Director',
                'description' => $isApproved ?
                    "High-value custom contract MRF {$mrf->mrf_id} was approved by Lazarus Director {$user->name} and forwarded to Procurement Manager review" :
                    "High-value custom contract MRF {$mrf->mrf_id} was rejected by Lazarus Director {$user->name}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'reference_type' => 'mrf',
                'reference_id' => $mrf->mrf_id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log activity', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => $isApproved ?
                'High-value custom contract MRF approved by Lazarus Director and forwarded to Procurement Manager' :
                'MRF rejected by Lazarus Director',
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'status' => $mrf->status,
                'workflowState' => $mrf->workflow_state,
                'currentStage' => $mrf->current_stage,
                'approvalRecord' => $approvalRecord,
            ]
        ]);
    }

    /**
     * Procurement Manager approves MRF after Supply Chain Director approval
     * Issues RFQs to identified vendors
     *
     * Updated for new simplified workflow
     */
    public function procurementApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role - only procurement managers/team can approve
        if (!in_array($user->scmRole(), ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can approve at this stage',
                'code' => 'FORBIDDEN',
                'requiredRole' => 'procurement_manager'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $isEmeraldContract = strtolower(trim((string) $mrf->contract_type)) === 'emerald';
        $isCustomType = $mrf->routed_reason === 'custom_contract_type';

        // Contract-type driven initial approval gate:
        // - Emerald: procurement can only proceed after executive approval
        // - Non-Emerald: procurement can only proceed after initial SCD approval
        // - Custom types (high-value): must go through Lazarus director approval first
        $validStates = $isEmeraldContract
            ? ['executive_approved', 'procurement_review']
            : ['supply_chain_director_approved', 'procurement_review'];

        // High-value custom contract types can also be approved via lazarus_director_approval
        // which updates workflow_state to supply_chain_director_approved for procurement
        if ($isCustomType && $mrf->workflow_state === 'lazarus_director_approval') {
            $validStates[] = 'lazarus_director_approval';
        }

        // Keep compatibility for legacy records that only store "pending" after initial approval.
        if ($mrf->workflow_state === 'pending') {
            if ($isEmeraldContract && (bool) $mrf->executive_approved) {
                $validStates[] = 'pending';
            }
            if (!$isEmeraldContract) {
                $validStates[] = 'pending';
            }
        }

        if (!in_array($mrf->workflow_state, $validStates)) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not ready for Procurement Manager review',
                'code' => 'INVALID_WORKFLOW_STATE',
                'currentWorkflowState' => $mrf->workflow_state,
                'expectedStates' => $validStates
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $isApproved = $request->action === 'approve';

        // Update MRF
        $mrf->update([
            'status' => $isApproved ? 'approved_for_rfq' : 'rejected',
            'current_stage' => $isApproved ? 'rfq_issuance' : 'rejected',
            'workflow_state' => $isApproved ? 'procurement_approved' : 'supply_chain_director_rejected',
            'remarks' => $request->remarks,
        ]);

        // Record approval history
        $approvalRecord = MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'stage' => 'procurement',
            'action' => $isApproved ? 'approved' : 'rejected',
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_email' => $user->email,
            'remarks' => $request->remarks,
            'performed_by' => $user->id
        ]);

        // Log activity
        try {
            Activity::create([
                'type' => 'mrf_approved',
                'title' => $isApproved ? 'MRF Approved by Procurement Manager' : 'MRF Rejected by Procurement Manager',
                'description' => $isApproved ?
                    "MRF {$mrf->mrf_id} approved by Procurement Manager {$user->name}. Ready for RFQ issuance." :
                    "MRF {$mrf->mrf_id} rejected by Procurement Manager {$user->name}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'reference_type' => 'mrf',
                'reference_id' => $mrf->mrf_id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log activity', ['error' => $e->getMessage()]);
        }

        // Send notification to procurement team if approved
        if ($isApproved) {
            try {
                $this->notificationService->notifyMRFApprovedForRFQ($mrf, $user);
            } catch (\Exception $e) {
                Log::warning('Failed to send approval notification', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $isApproved ?
                'MRF approved. Proceed to issue RFQs to vendors.' :
                'MRF rejected',
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'status' => $mrf->status,
                'workflowState' => $mrf->workflow_state,
                'currentStage' => $mrf->current_stage,
                'approvalRecord' => $approvalRecord,
                'nextStep' => $isApproved ? 'Issue RFQs to vendors' : null,
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
        if (!in_array($user->scmRole(), ['procurement', 'procurement_manager', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement can send vendor for approval',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::query()
            ->where(function ($q) use ($id) {
                $q->where('mrf_id', $id)->orWhere('formatted_id', $id);
                if ($id !== '' && is_numeric($id)) {
                    $q->orWhere('id', (int) $id);
                }
            })
            ->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in correct state - allow multiple states where vendor selection is valid
        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
        $allowedStates = [
            WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            WorkflowStateService::STATE_EXECUTIVE_APPROVED,
            'executive_approved',
            'procurement_review',
            'supply_chain_director_approved',
        ];

        // Also check if executive has approved (which means procurement can proceed)
        $isExecutiveApproved = $mrf->executive_approved ?? false;

        if (!in_array($currentState, $allowedStates) && !$isExecutiveApproved) {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in a valid state for vendor selection. Executive approval may be required first.',
                'code' => 'INVALID_STATUS',
                'current_state' => $currentState,
                'executive_approved' => $isExecutiveApproved
            ], 422);
        }

        if ($mrf->priceComparisons()->count() === 0) {
            $mrf->syncPriceComparisonsFromQuotations();
        }

        if ($mrf->priceComparisons()->count() === 0) {
            return response()->json([
                'success' => false,
                'error' => 'No price comparison data is available. Ensure this MRF has RFQ quotations from vendors, or save a price comparison in the comparison step first.',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,vendor_id',
            'quotation_id' => 'required|exists:quotations,quotation_id',
            'invoice_url' => 'nullable|url',
            'remarks' => 'nullable|string|max:2000',
            'selection_reason' => 'nullable|string|max:2000',
            'selectionReason' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $selectionReasonText = trim((string) ($request->input('selection_reason')
            ?? $request->input('selectionReason')
            ?? $request->input('remarks')
            ?? ''));
        $selectionReasonText = $selectionReasonText === '' ? null : $selectionReasonText;

        // Get vendor and quotation with all relationships
        $vendor = \App\Models\Vendor::where('vendor_id', $request->vendor_id)->first();
        $quotation = \App\Models\Quotation::where('quotation_id', $request->quotation_id)
            ->with(['rfq.mrf', 'rfq.items', 'vendor', 'items.rfqItem'])
            ->first();

        if (!$vendor || !$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor or quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Verify quotation belongs to the MRF
        $rfq = $quotation->rfq;
        if (!$rfq || $rfq->mrf_id !== $mrf->id) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation does not belong to this MRF',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Load MRF with all relationships for complete context
        $mrf->load(['requester', 'executiveApprover', 'chairmanApprover', 'items']);

        // Update MRF with selected vendor and invoice
        $mrf->update([
            'selected_vendor_id' => $vendor->id,
            'selected_quotation_id' => $quotation->id,
            'invoice_url' => $request->invoice_url ?? ($quotation->attachments[0] ?? null),
            'workflow_state' => WorkflowStateService::STATE_VENDOR_SELECTED,
            'status' => 'vendor_selected',
            'current_stage' => 'supply_chain_review',
        ]);

        if ($mrf->priceComparisons()->exists()) {
            $mrf->priceComparisons()->update(['is_selected' => false, 'selection_reason' => null]);
            $mrf->priceComparisons()->where('vendor_id', $vendor->id)->update([
                'is_selected' => true,
                'selection_reason' => $selectionReasonText,
            ]);
        }

        // Update RFQ workflow state to supply_chain_review
        if ($rfq) {
            $rfq->update([
                'workflow_state' => 'supply_chain_review',
                'selected_vendor_id' => $vendor->id,
                'selected_quotation_id' => $quotation->id,
            ]);
            Log::info('RFQ workflow state updated to supply_chain_review', ['rfq_id' => $rfq->rfq_id, 'mrf_id' => $mrf->mrf_id]);
        }

        app(\App\Services\LineItemBudgetService::class)->hydrateMrfQuotedAmounts($mrf, $quotation);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_selected', 'procurement', $user,
            "Vendor {$vendor->name} selected and sent for Supply Chain Director approval. " . ($selectionReasonText ?? ''));

        // Prepare complete data for notification and response
        // Include ALL quotation details - nothing should be hidden or summarized
        $completeQuotationData = [
            'selection_reason' => $selectionReasonText,
            'selectionReason' => $selectionReasonText,
            'quotation' => [
                'id' => $quotation->quotation_id,
                'quoteNumber' => $quotation->quote_number,
                'totalAmount' => (float) $quotation->total_amount,
                'currency' => $quotation->currency ?? 'NGN',
                'price' => (float) $quotation->price,
                'deliveryDays' => $quotation->delivery_days ?? null,
                'deliveryDate' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,
                'paymentTerms' => $quotation->payment_terms ?? null,
                'validityDays' => $quotation->validity_days,
                'warrantyPeriod' => $quotation->warranty_period,
                'notes' => $quotation->notes,
                'scopeOfWork' => $quotation->notes, // Scope of work is in notes field
                'specifications' => $quotation->notes, // Specifications may be in notes or items
                'attachments' => app(QuotationAttachmentService::class)->hydrateAttachments((function($attachments) {
                    if ($attachments === null || $attachments === '' || $attachments === []) {
                        return [];
                    }

                    if (is_string($attachments)) {
                        return [$attachments];
                    }

                    if (!is_array($attachments)) {
                        return [];
                    }

                    $isAssoc = array_keys($attachments) !== range(0, count($attachments) - 1);
                    if ($isAssoc) {
                        return [$attachments];
                    }

                    $out = [];
                    foreach ($attachments as $a) {
                        if ($a === null || $a === '') {
                            continue;
                        }

                        if (is_string($a)) {
                            $out[] = $a;
                            continue;
                        }

                        if (!is_array($a)) {
                            continue;
                        }

                        $aIsAssoc = array_keys($a) !== range(0, count($a) - 1);
                        if ($aIsAssoc) {
                            $out[] = $a;
                            continue;
                        }

                        foreach ($a as $inner) {
                            if ($inner !== null && $inner !== '') {
                                $out[] = $inner;
                            }
                        }
                    }

                    return array_values($out);
                })($quotation->attachments)), // All uploaded documents
                'submittedAt' => $quotation->submitted_at ? $quotation->submitted_at->toIso8601String() : null,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status ?? 'pending',
            ],
            'vendor' => [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'address' => $vendor->address,
                'contactPerson' => $vendor->contact_person,
                'rating' => (float) $vendor->rating,
            ],
            'rfq' => [
                'id' => $rfq->rfq_id,
                'title' => $rfq->getDisplayTitle(),
                'description' => $rfq->description,
                'category' => $rfq->category,
                'deadline' => $rfq->deadline ? $rfq->deadline->format('Y-m-d') : null,
                'estimatedCost' => (float) $rfq->estimated_cost,
                'paymentTerms' => $rfq->payment_terms,
                'supportingDocuments' => $rfq->supporting_documents ?? [],
                'items' => $rfq->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'itemName' => $item->item_name,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'specifications' => $item->specifications,
                    ];
                }),
            ],
            'mrf' => [
                'id' => $mrf->mrf_id,
                'title' => $mrf->title,
                'category' => $mrf->category,
                'contractType' => $mrf->contract_type,
                'description' => $mrf->description,
                'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
                'currency' => $mrf->currency ?? 'NGN',
                'urgency' => $mrf->urgency,
                'executiveApproved' => (bool) $mrf->executive_approved,
                'executiveApprovedAt' => $mrf->executive_approved_at ? $mrf->executive_approved_at->toIso8601String() : null,
                'executiveApprovedBy' => $mrf->executiveApprover ? [
                    'id' => $mrf->executiveApprover->id,
                    'name' => $mrf->executiveApprover->name,
                    'email' => $mrf->executiveApprover->email,
                ] : null,
                'chairmanApproved' => (bool) $mrf->chairman_approved,
                'chairmanApprovedAt' => $mrf->chairman_approved_at ? $mrf->chairman_approved_at->toIso8601String() : null,
                'requester' => $mrf->requester ? [
                    'id' => $mrf->requester->id,
                    'name' => $mrf->requester->name,
                    'email' => $mrf->requester->email,
                ] : null,
                'items' => $mrf->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'itemName' => $item->item_name,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'estimatedCost' => (float) $item->estimated_cost,
                    ];
                }),
            ],
            'quotationItems' => $quotation->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'itemName' => $item->item_name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unitPrice' => (float) $item->unit_price,
                    'totalPrice' => (float) $item->total_price,
                    'specifications' => $item->specifications,
                ];
            }),
        ];

        // Notify Supply Chain Director with complete information
        try {
            $scdUsers = \App\Models\User::whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain', 'admin'])->get();
            foreach ($scdUsers as $scdUser) {
                DatabaseNotifications::send($scdUser, new \App\Notifications\SystemAnnouncementNotification(
                    'Vendor Selection Pending Approval',
                    "MRF {$mrf->mrf_id} - Vendor {$vendor->name} selected and pending your approval",
                    [
                        'action_url' => "/mrfs/{$mrf->mrf_id}",
                        'quotation_data' => $completeQuotationData,
                    ]
                ));
            }
            Log::info('Vendor approval notification sent with complete data', ['mrf_id' => $mrf->mrf_id, 'vendor_id' => $vendor->vendor_id]);
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
                'selection_reason' => $selectionReasonText,
                'selectionReason' => $selectionReasonText,
                'quotation_data' => $completeQuotationData, // Include complete data in response
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
        if (!in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can approve vendor selection',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

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

        // Update MRF - approve vendor selection (move to Pending PO Upload)
        // After SCD approval, workflow moves back to Procurement Manager for PO upload
        $mrf->update([
            'workflow_state' => WorkflowStateService::STATE_INVOICE_APPROVED,
            'status' => 'pending_po_upload', // Clear status indicating PO upload is required
            'current_stage' => 'procurement', // Return to procurement for PO generation
        ]);

        // Update RFQ status to approved
        $rfq = \App\Models\RFQ::where('mrf_id', $mrf->id)->first();
        if ($rfq) {
            $rfq->update([
                'workflow_state' => 'approved',
                'status' => 'Awarded',
            ]);
            Log::info('RFQ status updated to approved', ['rfq_id' => $rfq->rfq_id, 'mrf_id' => $mrf->mrf_id]);
        }

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_approved', 'supply_chain', $user,
            'Vendor selection approved. ' . ($request->remarks ?? ''));

        app(FinanceApWorkflowOrchestrator::class)->afterVendorQuoteScdApproved($mrf, $user);

        try {
            app(WorkflowNotificationService::class)->notifyVendorQuoteScdApproved($mrf->fresh());
        } catch (\Exception $e) {
            Log::warning('Failed to send vendor quote approved notifications', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Notify Procurement that vendor is approved and PO upload is required
        // Provide clear actionable guidance: "Upload PO" is the next step
        try {
            $procurementUsers = \App\Models\User::whereIn('supply_chain_role', ['procurement', 'procurement_manager', 'admin'])->get();
            foreach ($procurementUsers as $procUser) {
                DatabaseNotifications::send($procUser, new \App\Notifications\SystemAnnouncementNotification(
                    'Vendor Selection Approved - PO Upload Required',
                    "MRF {$mrf->mrf_id} - Vendor selection has been approved by Supply Chain Director. Please upload the Purchase Order.",
                    [
                        'action_url' => "/mrfs/{$mrf->mrf_id}",
                        'action_label' => 'Upload PO',
                        'next_action' => 'upload_po',
                    ]
                ));
            }
            Log::info('Vendor approved notification sent with PO upload action', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::warning('Failed to send vendor approval notification', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor selection approved. MRF is now pending PO upload.',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
                'status' => $mrf->status,
                'current_stage' => $mrf->current_stage,
                'next_action' => 'upload_po',
                'next_action_label' => 'Upload Purchase Order',
                'vendorInvoiceGateOpen' => app(\App\Services\FinanceAp\VendorInvoiceGateService::class)->canSubmitInvoice($mrf->fresh()),
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
        if (!in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can reject vendor selection',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

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

        // Update RFQ status back to procurement_review
        $rfq = \App\Models\RFQ::where('mrf_id', $mrf->id)->first();
        if ($rfq) {
            $rfq->update([
                'workflow_state' => 'procurement_review',
            ]);
            Log::info('RFQ status updated to procurement_review', ['rfq_id' => $rfq->rfq_id, 'mrf_id' => $mrf->mrf_id]);
        }

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_rejected', 'supply_chain', $user,
            'Vendor selection rejected. Reason: ' . $request->reason . ($request->remarks ? "\n" . $request->remarks : ''));

        // Notify Procurement
        try {
            $procurementUsers = \App\Models\User::whereIn('supply_chain_role', ['procurement', 'procurement_manager', 'admin'])->get();
            foreach ($procurementUsers as $procUser) {
                DatabaseNotifications::send($procUser, new \App\Notifications\SystemAnnouncementNotification(
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
        if (!in_array($user->scmRole(), ['executive', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only executives can approve at this stage',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
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

        $parallelService = app(MrfParallelFirstApprovalService::class);

        return DB::transaction(function () use ($request, $user, $mrf, $parallelService) {
            $locked = MRF::where('id', $mrf->id)->lockForUpdate()->first();

            if (! $locked) {
                return response()->json([
                    'success' => false,
                    'error' => 'MRF not found',
                    'code' => 'NOT_FOUND',
                ], 404);
            }

            $currentState = $locked->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;
            $isParallel = $parallelService->isParallelPending($locked);

            if (! $isParallel) {
                if (strtolower(trim((string) $locked->contract_type)) !== 'emerald') {
                    return response()->json([
                        'success' => false,
                        'error' => 'Executive approval is only valid for Emerald contract MRFs.',
                        'code' => 'INVALID_CONTRACT_WORKFLOW',
                    ], 422);
                }

                if ($currentState !== WorkflowStateService::STATE_EXECUTIVE_REVIEW) {
                    if ($parallelService->isAlreadyApproved($locked)) {
                        return response()->json([
                            'success' => false,
                            'error' => 'This MRF has already been approved.',
                            'code' => 'ALREADY_APPROVED',
                            'current_state' => $currentState,
                        ], 422);
                    }

                    return response()->json([
                        'success' => false,
                        'error' => 'MRF is not pending executive approval',
                        'code' => 'INVALID_STATUS',
                        'current_state' => $currentState,
                        'current_status' => $locked->status,
                    ], 422);
                }
            } elseif ($parallelService->isAlreadyApproved($locked)) {
                return response()->json([
                    'success' => false,
                    'error' => 'This MRF has already been approved.',
                    'code' => 'ALREADY_APPROVED',
                    'current_state' => $currentState,
                ], 422);
            }

            $locked->update([
                'executive_approved' => true,
                'executive_approved_by' => $user->id,
                'executive_approved_at' => now(),
                'executive_remarks' => $request->remarks,
                'last_action_by_role' => in_array($user->scmRole(), ['admin']) ? 'admin' : 'executive',
                'first_approval_by_role' => $isParallel
                    ? MrfParallelFirstApprovalService::ROLE_EXECUTIVE
                    : $locked->first_approval_by_role,
            ]);

            try {
                $locked->load('requester');
                $this->notificationService->notifyMRFApproved($locked, $user, $request->remarks);
            } catch (\Exception $e) {
                \Log::error('Failed to send MRF approved notification', [
                    'mrf_id' => $locked->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $transitionSuccess1 = $this->workflowService->transition($locked, WorkflowStateService::STATE_EXECUTIVE_APPROVED, $user);

            if (! $transitionSuccess1) {
                Log::error('Failed to transition MRF to executive_approved', [
                    'mrf_id' => $locked->mrf_id,
                    'current_state' => $locked->workflow_state,
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to transition MRF state',
                    'code' => 'TRANSITION_FAILED',
                ], 500);
            }

            $locked->refresh();

            $transitionSuccess2 = $this->workflowService->transition($locked, WorkflowStateService::STATE_PROCUREMENT_REVIEW, $user);

            if (! $transitionSuccess2) {
                Log::error('Failed to transition MRF to procurement_review', [
                    'mrf_id' => $locked->mrf_id,
                    'current_state' => $locked->workflow_state,
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to transition MRF to procurement review',
                    'code' => 'TRANSITION_FAILED',
                ], 500);
            }

            $locked->refresh();

            $locked->update([
                'status' => 'procurement_review',
                'current_stage' => 'procurement',
                'procurement_review_started_at' => now(),
                'last_action_by_role' => in_array($user->scmRole(), ['admin']) ? 'admin' : 'executive',
            ]);

            MRFApprovalHistory::record($locked, 'approved', 'executive_review', $user, $request->remarks ?? '');

            try {
                Activity::create([
                    'type' => 'mrf_approved',
                    'title' => 'MRF Approved by Executive',
                    'description' => "MRF {$locked->mrf_id} was approved by {$user->name}",
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'entity_type' => 'mrf',
                    'entity_id' => $locked->mrf_id,
                    'status' => 'approved',
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to log MRF approval activity', ['error' => $e->getMessage()]);
            }

            try {
                $this->notificationService->notifyMRFPendingProcurement($locked);
            } catch (\Exception $e) {
                Log::warning('Failed to send procurement notification', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Approved by Executive',
                'data' => [
                    'mrf_id' => $locked->mrf_id,
                    'status' => $locked->status,
                    'current_stage' => $locked->current_stage,
                    'workflow_state' => $locked->workflow_state,
                    'firstApprovalByRole' => $locked->first_approval_by_role,
                    'firstApprovalStatusLabel' => MrfParallelFirstApprovalService::statusLabelForRole($locked->first_approval_by_role),
                    'next_approver' => 'Procurement Manager',
                ],
            ]);
        });
    }

    /**
     * Chairman approves MRF (high-value only)
     * Logic: Move to procurement after chairman approval
     */
    public function chairmanApprove(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->scmRole(), ['chairman', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only chairman can approve at this stage',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

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

        // Log activity
        try {
            Activity::create([
                'type' => 'mrf_approved',
                'title' => 'MRF Approved by Chairman',
                'description' => "MRF {$mrf->mrf_id} was approved by {$user->name}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'mrf',
                'entity_id' => $mrf->mrf_id,
                'status' => 'approved',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log MRF chairman approval activity', ['error' => $e->getMessage()]);
        }

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
        $genStarted = microtime(true);
        Log::info('PO generation: request received', [
            'route_id' => (string) $id,
            'save_as_draft' => $request->boolean('save_as_draft') || $request->boolean('saveAsDraft'),
            'has_file' => $request->hasFile('unsigned_po'),
            'payload_keys' => array_values(array_filter(
                array_keys($request->except(['password', 'token', 'authorization', 'unsigned_po'])),
                static fn ($k) => is_string($k) && $k !== ''
            )),
        ]);

        $user = $request->user();

        // Check role (Spatie + users.role column)
        if (!$this->permissionService->userActsAsProcurement($user)) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can generate POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfForPoRequest($request, (string) $id, ['items']);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found for the URL id. Confirm the path uses the same identifier as this MRF (mrf_id or formatted_id from the list API). If the UI shows a different MRF than the URL, send that id as mrf_id or formatted_id in the JSON body.',
                'code' => 'NOT_FOUND',
                'data' => [
                    'route_id' => (string) $id,
                ],
            ], 404);
        }

        $fastTrack = $this->resolveFastTrackFlag($request);
        $allowMissingRfq = $request->boolean('allow_missing_rfq');
        // Load RFQ before draft/final gates — draft path previously referenced
        // undefined $rfq when allow_missing_rfq=true (intermittent 500s).
        $rfq = RFQ::where('mrf_id', $mrf->id)->with('items')->first();

        $saveAsDraft = $request->boolean('save_as_draft', false)
            || $request->boolean('saveAsDraft', false);
        if ($saveAsDraft) {
            if ($request->hasFile('unsigned_po')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Draft mode does not support file uploads. Omit save_as_draft to upload a signed PO.',
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }

            $draftLoose = $fastTrack || ($allowMissingRfq && ! $rfq);
            $draftAllowed = $draftLoose
                ? $this->permissionService->canFastTrackPO($user, $mrf)
                : $this->permissionService->canSavePODraft($user, $mrf);

            if (! $draftAllowed) {
                return response()->json([
                    'success' => false,
                    'error' => 'Saving a PO draft is not allowed for this MRF at the current stage.',
                    'code' => 'FORBIDDEN',
                    'data' => [
                        'fast_track' => $fastTrack,
                        'allow_missing_rfq' => $allowMissingRfq,
                        'mrf_status' => $mrf->status,
                        'mrf_workflow_state' => $mrf->workflow_state,
                    ],
                ], 403);
            }

            return $this->savePOAsDraft($request, $mrf, $user, $fastTrack);
        }

        if (\App\Support\PaymentMilestoneRequest::provided($request)) {
            \App\Support\PaymentMilestoneRequest::mergeIntoRequest($request);
            try {
                \App\Support\PaymentMilestoneRequest::validatePercentages(
                    \App\Support\PaymentMilestoneRequest::resolve($request)
                );
                app(PaymentScheduleService::class)->applyFromRequest($mrf, $user, $request);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        // Final PO generation: normal path uses canGeneratePO (RFQ + workflow).
        // Fast-track or explicit allow_missing_rfq with no RFQ uses the loose gate and
        // may build a synthetic PDF dataset from price comparisons / MRF lines.
        $generationLoose = $fastTrack || ($allowMissingRfq && ! $rfq);
        $generationAllowed = $generationLoose
            ? $this->permissionService->canFastTrackPO($user, $mrf)
            : $this->permissionService->canGeneratePO($user, $mrf);

        if (! $generationAllowed) {
            return response()->json([
                'success' => false,
                'error' => 'PO generation not allowed for this MRF at the current stage.',
                'code' => 'FORBIDDEN',
                'data' => [
                    'fast_track' => $fastTrack,
                    'allow_missing_rfq' => $allowMissingRfq,
                    'mrf_status' => $mrf->status,
                    'mrf_workflow_state' => $mrf->workflow_state,
                ],
            ], 403);
        }

        $hasExistingPO = ! empty(trim($mrf->po_number ?? '')) || ! empty(trim($mrf->unsigned_po_url ?? ''));
        $hasSignedPO = ! empty(trim($mrf->signed_po_url ?? ''));
        $statusLower = strtolower(trim($mrf->status ?? ''));
        $stageLower = strtolower(trim($mrf->current_stage ?? ''));

        $isRegeneration = ($hasExistingPO && $statusLower === 'po rejected')
            || ($hasExistingPO && ! $hasSignedPO && ($statusLower === 'supply_chain' || $stageLower === 'supply_chain'))
            || ($hasExistingPO && ! $hasSignedPO);

        // Once a PO is signed, only admins may regenerate.
        if ($hasSignedPO && ! in_array(strtolower($user->scmRole() ?? ''), ['admin'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot regenerate PO that has already been signed. Please contact an administrator.',
                'code' => 'PO_ALREADY_SIGNED',
                'data' => [
                    'existing_po_number' => $mrf->po_number,
                    'po_url' => $mrf->unsigned_po_url,
                    'signed_po_url' => $mrf->signed_po_url,
                ],
            ], 422);
        }

        // Check if this is a file upload (Mode 1) or auto-generation (Mode 2)
        $isFileUpload = $request->hasFile('unsigned_po');
        $isAutoGeneration = $request->has('po_number') && !$isFileUpload;

        if ($isFileUpload) {
            // Mode 1: File Upload (Existing behavior)
        $validator = Validator::make($request->all(), [
                'po_number' => 'nullable|string|max:255',
                'unsigned_po' => 'required|file|mimes:pdf,doc,docx|max:20480',
            'remarks' => 'nullable|string',
            'po_type' => 'nullable|in:goods,services,logistics',
            'custom_terms' => 'nullable|string',
            'terms_mode' => 'nullable|in:standard,custom,both',
            'po_terms_mode' => 'nullable|in:standard,custom,both',
            'fast_track' => 'nullable|boolean',
            'fastTrack' => 'nullable|boolean',
            'bypass_executive_review' => 'nullable|boolean',
            'bypassExecutiveReview' => 'nullable|boolean',
            'allow_missing_rfq' => 'nullable|boolean',
            'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'taxAmount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
                        ], 422);
        }

            // Use provided PO number or existing draft number; defer auto-generation
            // until after we resolve the payload's vendor so the PO number token
            // is derived from the same vendor record used for the PDF.
            $poNumber = $request->po_number ?? $mrf->po_number ?? null;
        } else {
            // Mode 2: Auto-Generation (JSON body)
            $validator = Validator::make($request->all(), [
                'po_number' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
            'po_type' => 'nullable|in:goods,services,logistics',
            'custom_terms' => 'nullable|string',
            'terms_mode' => 'nullable|in:standard,custom,both',
            'po_terms_mode' => 'nullable|in:standard,custom,both',
            'fast_track' => 'nullable|boolean',
            'fastTrack' => 'nullable|boolean',
            'bypass_executive_review' => 'nullable|boolean',
            'bypassExecutiveReview' => 'nullable|boolean',
            'allow_missing_rfq' => 'nullable|boolean',
            'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'taxAmount' => 'nullable|numeric|min:0',
            ]);

        if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'code' => 'VALIDATION_ERROR'
                ], 422);
            }

            // Use provided PO number or existing draft number; defer auto-generation
            // until after we resolve the payload's vendor so the PO number token
            // is derived from the same vendor record used for the PDF.
            $poNumber = $request->po_number ?? $mrf->po_number ?? null;
        }

        $this->applyRequestedCurrency($request, $mrf);

        // Check if PO number already exists (for uniqueness)
        if (MRF::where('po_number', $poNumber)->where('id', '!=', $mrf->id)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'PO number already exists. Please use a different PO number.',
                'code' => 'DUPLICATE_PO_NUMBER'
            ], 422);
        }

        $termsMode = $this->normalisePOTermsMode($request);
        if ($termsMode === 'custom' && trim((string) ($request->input('custom_terms') ?? '')) === '') {
            return response()->json([
                'success' => false,
                'error' => 'When terms_mode is "custom", custom_terms must be provided and non-empty.',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        // Handle file upload mode (Mode 1)
        $storedPoPath = null;
        $storedPoFileName = null;
        if ($isFileUpload) {
                        $file = $request->file('unsigned_po');

            // Validate file
            if (!$file->isValid()) {
            return response()->json([
                'success' => false,
                    'error' => 'Invalid file upload',
                    'code' => 'FILE_UPLOAD_ERROR'
            ], 422);
        }

            // Upload file to storage (S3)
        $poUrl = null;
        $poShareUrl = null;

            try {
                $disk = $this->getStorageDisk();
                $poFileName = "po_{$poNumber}_" . time() . "." . $file->getClientOriginalExtension();
                $poPath = "purchase-orders/" . date('Y/m') . "/{$poFileName}";

                // Ensure directory structure exists (for S3, this is just the path)
                $directory = dirname($poPath);
                if ($disk !== 's3' && !Storage::disk($disk)->exists($directory)) {
                    Storage::disk($disk)->makeDirectory($directory, 0755, true);
                }

                Storage::disk($disk)->putFileAs($directory, $file, basename($poPath));

                $storedPoPath = $poPath;
                $storedPoFileName = basename($poPath);

                // Get URL (temporary signed URL for S3, public URL for local)
                $poUrl = $this->getFileUrl($poPath, $disk);
                $poShareUrl = $poUrl;

                if (empty($poUrl)) {
                    throw new \Exception('Failed to upload PO file');
                }
            } catch (\Exception $e) {
                Log::error('PO file upload failed', [
                'mrf_id' => $id,
                    'po_number' => $poNumber,
                    'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                    'error' => 'Failed to upload PO file: ' . $e->getMessage(),
                    'code' => 'UPLOAD_FAILED'
                ], 500);
            }
        } else {
            // Mode 2: Auto-Generation — persist draft fields, queue PDF + notifications.
            $poData = $this->resolvePoGenerationPayload($mrf, $rfq, $request, $fastTrack, $allowMissingRfq);

            if (! $poData['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $poData['error'],
                    'code' => $poData['code'],
                ], $poData['status'] ?? 400);
            }

            // If we deferred PO generation earlier, generate now using the
            // authoritative vendor resolved for the payload so the slug used
            // for the PO number matches the vendor shown in the PDF.
            if (empty($poNumber)) {
                $resolvedVendor = $poData['resolved_vendor'] ?? null;
                $poNumber = $this->generatePONumber($mrf, $resolvedVendor ?? null);
            }

            $taxRate = (float) ($request->input('tax_rate', $request->input('taxRate', 0)));
            $subtotal = 0;
            if (isset($poData['data']['items']) && is_array($poData['data']['items'])) {
                foreach ($poData['data']['items'] as $item) {
                    $unitPrice = $item['unit_price'] ?? (($item['total_price'] ?? 0) / max(1, $item['quantity'] ?? 1));
                    $subtotal += ($unitPrice * ($item['quantity'] ?? 1));
                }
            }

            $taxAmount = 0;
            if ($taxRate > 0) {
                $taxAmount = ($subtotal * $taxRate) / 100;
            } elseif ($request->has('tax_amount') || $request->has('taxAmount')) {
                $taxAmount = (float) ($request->input('tax_amount', $request->input('taxAmount', 0)));
            }

            $poType = strtolower((string) ($request->input('po_type') ?: $mrf->po_type ?: 'goods'));
            $standardTerms = POTermsTemplate::query()
                ->where('po_type', $poType)
                ->where('is_active', true)
                ->latest('id')
                ->value('content');
            $customTerms = $request->input('custom_terms');
            $mergedTerms = $this->mergePoSpecialTerms($termsMode, $standardTerms, $customTerms);

            $paymentTerms = $request->input('payment_terms', $request->input('paymentTerms', $mrf->po_payment_terms));
            $deliveryDate = null;
            foreach (['delivery_date', 'deliveryDate', 'expected_delivery_date'] as $dk) {
                $raw = $request->input($dk);
                if (is_string($raw) && trim($raw) !== '') {
                    try {
                        $deliveryDate = Carbon::parse($raw)->toDateString();
                        break;
                    } catch (\Throwable) {
                    }
                }
            }

            $draftUpdate = [
                'po_number' => $poNumber,
                'ship_to_address' => $request->input('ship_to_address', $request->input('shipToAddress')),
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'po_special_terms' => $mergedTerms !== '' ? $mergedTerms : ($request->po_special_terms ?? null),
                'custom_terms' => $customTerms,
                'po_terms_mode' => $termsMode,
                'po_type' => $poType,
                'po_payment_terms' => is_string($paymentTerms) ? $paymentTerms : null,
                'invoice_submission_email' => $request->input('invoice_submission_email', $request->input('invoiceSubmissionEmail')),
                'invoice_submission_cc' => PurchaseOrderInvoiceCc::merge(
                    $request->input('invoice_submission_cc', $request->input('invoiceSubmissionCc')),
                    $mrf->invoice_submission_cc,
                    PurchaseOrderInvoiceCc::defaultCc(),
                ),
                'remarks' => $request->has('remarks') ? $request->input('remarks') : $mrf->remarks,
                'expected_delivery_date' => $deliveryDate ?? $mrf->expected_delivery_date,
                'procurement_manager_id' => $user->id,
                'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
                'po_generation_error' => null,
                'po_generation_failed_at' => null,
            ];

            if ($mrf->is_po_linked || ($mrf->source ?? 'standard') === 'po_generated') {
                $draftUpdate['linked_po_id'] = $poNumber;
            }

            $existingCols = array_flip(\App\Support\TableColumnCache::columnsFor('m_r_f_s'));
            if ($existingCols !== []) {
                $draftUpdate = array_intersect_key($draftUpdate, $existingCols);
            }

            Log::info('PO generation: persisting fields before PDF queue', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber,
                'is_regeneration' => $isRegeneration,
                'fields' => array_keys($draftUpdate),
            ]);

            $mrf->update($draftUpdate);

            ProcessPurchaseOrderGenerationJob::dispatch(
                $mrf->id,
                $user->id,
                [
                    'po_number' => $poNumber,
                    'po_data' => $poData['data'],
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'subtotal' => $subtotal,
                    'fast_track' => $fastTrack,
                    'is_regeneration' => $isRegeneration,
                    'has_rfq' => (bool) $rfq,
                ],
            )->afterResponse();

            Log::info('PO generation queued', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber,
                'fast_track' => $fastTrack,
                'elapsed_ms' => (int) round((microtime(true) - $genStarted) * 1000),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PO generation queued',
                'processing' => true,
                'data' => [
                    'mrf' => [
                        'id' => $mrf->mrf_id,
                        'po_number' => $poNumber,
                        'poNumber' => $poNumber,
                        'fast_tracked' => $fastTrack,
                        'fastTracked' => $fastTrack,
                        'synthetic_po' => ! $rfq && ($fastTrack || $allowMissingRfq),
                        'syntheticPo' => ! $rfq && ($fastTrack || $allowMissingRfq),
                    ],
                    'timing_ms' => [
                        'accept' => (int) round((microtime(true) - $genStarted) * 1000),
                    ],
                ],
            ], 202);
        }

        // Calculate tax if tax_rate is provided (file upload path only)
        $taxRate = (float) ($request->input('tax_rate', $request->input('taxRate', 0)));
        $taxAmount = 0;

        // Calculate subtotal from MRF line items (file upload path).
        $subtotal = 0;
        $mrf->loadMissing('items');
        foreach ($mrf->items as $item) {
            $unitPrice = $item->unit_price ?? (($item->total_price ?? 0) / max(1, $item->quantity ?? 1));
            $subtotal += ($unitPrice * ($item->quantity ?? 1));
        }

        // Calculate tax amount if tax_rate is provided
        if ($taxRate > 0) {
            $taxAmount = ($subtotal * $taxRate) / 100;
        } elseif ($request->has('tax_amount') || $request->has('taxAmount')) {
            $taxAmount = (float) ($request->input('tax_amount', $request->input('taxAmount', 0)));
        }

        $poType = strtolower((string) ($request->input('po_type') ?: 'goods'));
        $standardTerms = POTermsTemplate::query()
            ->where('po_type', $poType)
            ->where('is_active', true)
            ->latest('id')
            ->value('content');
        $customTerms = $request->input('custom_terms');
        $mergedTerms = $this->mergePoSpecialTerms($termsMode, $standardTerms, $customTerms);

        // Update MRF - set workflow state for SCD signature after PO generation
        $updateData = [
            'po_number' => $poNumber,
            'unsigned_po_url' => $poUrl,
            'po_generated_at' => now(),
            'po_draft_saved_at' => null,
            'workflow_state' => WorkflowStateService::STATE_PO_GENERATED,
            'status' => 'awaiting_scd_signature',
            'current_stage' => 'supply_chain',
            'procurement_manager_id' => $user->id,
            'rejection_reason' => null, // Clear rejection reason if regenerating
            // PO Details
            'ship_to_address' => $request->ship_to_address ?? null,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'po_special_terms' => $mergedTerms !== '' ? $mergedTerms : ($request->po_special_terms ?? null),
            'custom_terms' => $customTerms,
            'po_terms_mode' => $termsMode,
            'invoice_submission_email' => $request->invoice_submission_email ?? null,
            'invoice_submission_cc' => PurchaseOrderInvoiceCc::merge(
                $request->invoice_submission_cc ?? null,
                $mrf->invoice_submission_cc,
                PurchaseOrderInvoiceCc::defaultCc(),
            ),
            'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
        ];

        if ($mrf->is_po_linked || ($mrf->source ?? 'standard') === 'po_generated') {
            $updateData['linked_po_id'] = $poNumber;
        }

        // Add sharing URL if available (use web URL as fallback)
        if (isset($poShareUrl) && $poShareUrl) {
            $updateData['unsigned_po_share_url'] = $poShareUrl;
        } elseif ($poUrl) {
            // Use web URL as sharing URL if sharing link creation failed
            $updateData['unsigned_po_share_url'] = $poUrl;
        }

        $mrf->update($updateData);

        $poTotalForSchedule = $subtotal + $taxAmount;
        if ($poTotalForSchedule <= 0) {
            $poTotalForSchedule = (float) ($mrf->estimated_cost ?? 0);
        }
        app(PaymentScheduleService::class)->lockOnPoGeneration($mrf, $poTotalForSchedule);

        if ($storedPoPath && $poUrl) {
            $this->registerPoPdfInRegistry(
                $mrf,
                $user,
                $storedPoPath,
                $poUrl,
                $storedPoFileName ?? ('po_' . $poNumber . '.pdf'),
            );
        }

        ProcessPurchaseOrderNotificationsJob::dispatch(
            $mrf->id,
            $user->id,
            $poNumber,
            $fastTrack,
            $isRegeneration,
            (bool) $rfq,
        )->afterResponse();

        if ($fastTrack) {
            Log::info('PO fast-tracked from Procurement Overview', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber,
                'user_id' => $user->id,
                'previous_workflow_state' => $mrf->getOriginal('workflow_state'),
                'previous_status' => $mrf->getOriginal('status'),
            ]);
        }

        $mrf->refresh();

        $poStreamUrl = $mrf->freshUnsignedPoStreamUrl() ?? $mrf->unsigned_po_url;

        $paymentMilestones = app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf);

        return response()->json([
            'success' => true,
            'message' => 'PO generated successfully',
            'data' => [
                'payment_milestones' => $paymentMilestones,
                'paymentMilestones' => $paymentMilestones,
                'mrf' => [
                    'id' => $mrf->mrf_id,
                'po_number' => $mrf->po_number,
                    'poNumber' => $mrf->po_number,
                'unsigned_po_url' => $poStreamUrl,
                    'unsignedPOUrl' => $poStreamUrl,
                    'unsigned_po_share_url' => $poStreamUrl,
                    'unsignedPOShareUrl' => $poStreamUrl,
                    'workflow_state' => $mrf->workflow_state,
                    'workflowState' => $mrf->workflow_state,
                'status' => $mrf->status,
                'custom_terms' => $mrf->custom_terms,
                'customTerms' => $mrf->custom_terms,
                'po_terms_mode' => $mrf->po_terms_mode,
                'poTermsMode' => $mrf->po_terms_mode,
                'fast_tracked' => $fastTrack,
                'fastTracked' => $fastTrack,
                'synthetic_po' => ! $rfq && ($fastTrack || $allowMissingRfq),
                'syntheticPo' => ! $rfq && ($fastTrack || $allowMissingRfq),
                'priceComparisons' => $mrf->priceComparisons()->get(),
                    'payment_milestones' => $paymentMilestones,
                    'paymentMilestones' => $paymentMilestones,
                    ...$mrf->currencyApiFields(),
                ],
                'po_url' => $poStreamUrl,
                'fast_tracked' => $fastTrack,
                'synthetic_po' => ! $rfq && ($fastTrack || $allowMissingRfq),
                'resolvedVendors' => [],
                'resolved_vendors' => [],
                'notifications_queued' => true,
            ]
        ]);
    }

    /**
     * Reads any of the accepted fast-track flag aliases from the request:
     * fast_track, fastTrack, bypass_executive_review, bypassExecutiveReview.
     * Used by the Procurement Overview "Purchase Order" tab to skip MRF
     * approval workflow gates.
     */
    private function resolveFastTrackFlag(Request $request): bool
    {
        foreach (['fast_track', 'fastTrack', 'bypass_executive_review', 'bypassExecutiveReview'] as $key) {
            if ($request->boolean($key)) {
                return true;
            }
        }

        return false;
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
        if (!in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can sign POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is pending SCD signature (case-insensitive)
        $statusLower = strtolower(trim($mrf->status ?? ''));
        if (!in_array($statusLower, ['supply_chain', 'awaiting_scd_signature'])) {
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

        // Upload signed PO to S3 storage
        $signedPOFile = $request->file('signed_po');
        $signedPOUrl = null;
        $signedPOShareUrl = null;

        $disk = $this->getStorageDisk();
        $signedPOFileName = "po_signed_{$mrf->po_number}_" . time() . ".pdf";
        $signedPOPath = "purchase-orders/signed/" . date('Y/m') . "/{$signedPOFileName}";

        // Ensure directory structure exists (for S3, this is just the path)
        $directory = dirname($signedPOPath);
        if ($disk !== 's3' && !Storage::disk($disk)->exists($directory)) {
            Storage::disk($disk)->makeDirectory($directory, 0755, true);
        }

        Storage::disk($disk)->putFileAs($directory, $signedPOFile, basename($signedPOPath));

        // Get URL (temporary signed URL for S3, public URL for local)
        $signedPOUrl = $this->getFileUrl($signedPOPath, $disk);
        $signedPOShareUrl = $signedPOUrl;

        Log::info('Signed PO uploaded to storage', [
                    'mrf_id' => $id,
                    'po_number' => $mrf->po_number,
            'stored_path' => $signedPOPath,
            'url' => $signedPOUrl,
            'disk' => $disk
        ]);

        // Update MRF via unified PO signed transition
        $this->workflowService->applyPoSigned($mrf, $user, [
            'signed_po_url' => $signedPOUrl,
            'po_signed_at' => now(),
            'signed_po_share_url' => $signedPOShareUrl ?? $signedPOUrl,
        ], force: true);

        $mrf->refresh();

        $this->registerSignedPoInRegistry(
            $mrf,
            $user,
            $signedPOPath,
            $signedPOUrl,
            basename($signedPOPath),
        );

        app(FinanceApWorkflowOrchestrator::class)->afterPoSigned($mrf, $user);

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

    public function signPurchaseOrder(Request $request, string $po)
    {
        $user = $request->user();
        if (!$user || !in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can sign purchase orders.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $po)->orWhere('po_number', $po)->first();
        if (!$mrf) {
            return response()->json(['success' => false, 'error' => 'PO not found', 'code' => 'NOT_FOUND'], 404);
        }
        if (strtolower((string) $mrf->status) !== 'awaiting_scd_signature') {
            return response()->json([
                'success' => false,
                'error' => 'PO is not awaiting SCD signature.',
                'code' => 'INVALID_STATUS',
            ], 422);
        }
        if (empty($user->signature_image_path)) {
            return response()->json([
                'success' => false,
                'error' => 'No signature image found for this user.',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $rfq = RFQ::where('mrf_id', $mrf->id)->with('items')->first();
        $poData = $this->resolvePoGenerationPayload($mrf, $rfq, $request, true, true);
        if (! $poData['success']) {
            return response()->json(['success' => false, 'error' => $poData['error'] ?? 'Unable to prepare PO data'], $poData['status'] ?? 422);
        }
        $sigDiskName = config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));
        $sigDisk = Storage::disk($sigDiskName);
        $sigPath = $user->signature_image_path;
        if (!$sigDisk->exists($sigPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Signature file not found on storage.',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }
        if ($sigDiskName === 's3') {
            $tmp = tempnam(sys_get_temp_dir(), 'sig_');
            if ($tmp === false) {
                return response()->json(['success' => false, 'error' => 'Could not prepare signature for PDF.'], 500);
            }
            file_put_contents($tmp, $sigDisk->get($sigPath));
            $poData['data']['signature_image_url'] = $tmp;
        } else {
            $poData['data']['signature_image_url'] = $sigDisk->path($sigPath);
        }
        $pdfBinary = $this->generatePOPDF($poData['data'], (string) ($mrf->po_number ?: $mrf->mrf_id), $user);

        $disk = $this->getStorageDisk();
        $signedPath = 'purchase-orders/signed/' . date('Y/m') . '/po_signed_' . ($mrf->po_number ?? $mrf->mrf_id) . '_' . time() . '.pdf';
        Storage::disk($disk)->put($signedPath, $pdfBinary);
        $signedUrl = $this->getFileUrl($signedPath, $disk);

        $this->workflowService->applyPoSigned($mrf, $user, [
            'signed_po_url' => $signedUrl,
            'signed_po_share_url' => $signedUrl,
            'po_signed_at' => now(),
        ], force: true);

        $mrf->refresh();

        $this->registerSignedPoInRegistry(
            $mrf,
            $user,
            $signedPath,
            $signedUrl,
            basename($signedPath),
        );

        app(FinanceApWorkflowOrchestrator::class)->afterPoSigned($mrf, $user);

        return response()->json([
            'success' => true,
            'message' => 'PO signed successfully.',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $mrf->po_number,
                'status' => $mrf->status,
                'signed_po_url' => $mrf->signed_po_url,
            ],
        ]);
    }

    /**
     * Reject PO (Supply Chain Director returns to procurement for revision)
     */
    public function rejectPO(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Director can reject POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if MRF is in SCD signature stage
        if (!in_array(strtolower((string) $mrf->status), ['supply_chain', 'awaiting_scd_signature'])) {
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
            'status' => 'revision_required',
            'current_stage' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_INVOICE_APPROVED,
            'rejection_reason' => $request->reason,
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'rejected_po', 'supply_chain', $user, $request->reason . ($request->comments ? "\n" . $request->comments : ''));

        // Notify procurement
        $this->notificationService->notifyPORejectedToProcurement($mrf, $request->reason);
        if ($mrf->procurement_manager_id) {
            $manager = \App\Models\User::find($mrf->procurement_manager_id);
            if ($manager) {
                DatabaseNotifications::send($manager, new \App\Notifications\SystemAnnouncementNotification(
                    'PO Returned for Revision',
                    "PO {$mrf->po_number} was returned for revision. Reason: {$request->reason}",
                    [
                        'action_url' => "/mrfs/{$mrf->mrf_id}",
                        'badge' => 'Returned for Revision',
                    ]
                ));
            }
        }

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
        if (!in_array($user->scmRole(), ['finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Finance team can process payments',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if (mrfUsesFinanceAp($mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'This MRF is processed through Finance AP. Use the Finance AP platform for milestone payments.',
                'code' => 'FINANCE_AP_ROUTED',
            ], 422);
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
        if (!in_array($user->scmRole(), ['chairman', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Chairman can approve payments',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if (mrfUsesFinanceAp($mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'This MRF is processed through Finance AP. Payment approval happens in Finance AP.',
                'code' => 'FINANCE_AP_ROUTED',
            ], 422);
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
        if (!in_array($user->scmRole(), ['executive', 'chairman', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions to reject MRF',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

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

        // Log activity
        try {
            Activity::create([
                'type' => 'mrf_rejected',
                'title' => 'MRF Rejected',
                'description' => "MRF {$mrf->mrf_id} was rejected by {$user->name}. Reason: {$request->reason}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'mrf',
                'entity_id' => $mrf->mrf_id,
                'status' => 'rejected',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log MRF rejection activity', ['error' => $e->getMessage()]);
        }

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
        $userRole = strtolower(trim($user->scmRole() ?? ''));
        $allowedRoles = ['procurement_manager', 'procurement', 'admin'];

        if (!in_array($userRole, array_map('strtolower', $allowedRoles))) {
            Log::warning('Unauthorized PO deletion attempt', [
                'user_id' => $user->id,
                'user_role' => $user->scmRole(),
                'mrf_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers and admins can delete POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $isAdmin = ($userRole === 'admin');

        $mrf = $this->findMrfByAnyId((string) $id);

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
                'deleted_by_role' => $user->scmRole()
            ]);
        }

        // Delete PO files from storage if they exist
        try {
            // Delete from S3/local storage if exists
            if ($mrf->unsigned_po_url) {
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
            if ($mrf->signed_po_url) {
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
                'deleted_by_role' => $user->scmRole(),
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
     * Persist a PO as a draft on the MRF without progressing the workflow.
     *
     * Differs from finalisation in three ways:
     *  - No PDF is rendered or uploaded.
     *  - workflow_state, status and current_stage are left untouched.
     *  - No Finance / SCD notifications are dispatched.
     *
     * A subsequent call to generatePO without save_as_draft will finalise
     * the draft and clear po_draft_saved_at.
     *
     * Draft validation is intentionally loose — required-field checks that
     * apply to finalisation must not block draft saves.
     */
    private function savePOAsDraft(Request $request, MRF $mrf, $user, bool $fastTrack = false)
    {
        $startedAt = microtime(true);
        $payloadKeys = array_values(array_filter(
            array_keys($request->except(['password', 'token', 'authorization'])),
            static fn ($k) => is_string($k) && $k !== ''
        ));
        Log::info('PO draft save started', [
            'mrf_id' => $mrf->mrf_id,
            'user_id' => $user->id ?? null,
            'payload_keys' => $payloadKeys,
            'fast_track' => $fastTrack,
        ]);

        $validator = Validator::make($request->all(), [
            'po_number' => 'nullable|string|max:255',
            'po_type' => 'nullable|in:goods,services,logistics',
            'custom_terms' => 'nullable|string',
            'po_special_terms' => 'nullable|string',
            'terms_mode' => 'nullable|in:standard,custom,both',
            'po_terms_mode' => 'nullable|in:standard,custom,both',
            'fast_track' => 'nullable|boolean',
            'fastTrack' => 'nullable|boolean',
            'bypass_executive_review' => 'nullable|boolean',
            'bypassExecutiveReview' => 'nullable|boolean',
            'allow_missing_rfq' => 'nullable|boolean',
            'ship_to_address' => 'nullable|string|max:1000',
            'shipToAddress' => 'nullable|string|max:1000',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'taxAmount' => 'nullable|numeric|min:0',
            'invoice_submission_email' => 'nullable|string|max:255',
            'invoiceSubmissionEmail' => 'nullable|string|max:255',
            'invoice_submission_cc' => 'nullable|string|max:500',
            'invoiceSubmissionCc' => 'nullable|string|max:500',
            'remarks' => 'nullable|string',
            'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
            'delivery_date' => 'nullable|date',
            'deliveryDate' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:2000',
            'paymentTerms' => 'nullable|string|max:2000',
            'expected_delivery_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            Log::warning('PO draft save validation failed', [
                'mrf_id' => $mrf->mrf_id,
                'errors' => $validator->errors()->toArray(),
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $milestonesStarted = microtime(true);
        if (\App\Support\PaymentMilestoneRequest::provided($request)) {
            \App\Support\PaymentMilestoneRequest::mergeIntoRequest($request);
            try {
                \App\Support\PaymentMilestoneRequest::validatePercentages(
                    \App\Support\PaymentMilestoneRequest::resolve($request)
                );
                app(PaymentScheduleService::class)->applyFromRequestForPoDraft($mrf, $user, $request);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::warning('PO draft milestone validation failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'errors' => $e->errors(),
                    'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }
        $milestonesMs = (int) round((microtime(true) - $milestonesStarted) * 1000);

        // Assign a PO number on first draft save so drafts appear in po_list queries.
        $poNumber = trim((string) ($request->input('po_number') ?? ''));
        if ($poNumber === '') {
            $poNumber = trim((string) ($mrf->po_number ?? ''));
        }
        if ($poNumber === '') {
            $poNumber = $this->generatePONumber($mrf);
        }
        if (MRF::where('po_number', $poNumber)->where('id', '!=', $mrf->id)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'PO number already exists. Please use a different PO number.',
                'code' => 'DUPLICATE_PO_NUMBER',
            ], 422);
        }

        $termsMode = $this->normalisePOTermsMode($request, $mrf);
        // Drafts may use terms_mode=custom with empty custom_terms — finalisation
        // enforces non-empty; drafts must not.
        $customTerms = $request->has('custom_terms')
            ? $request->input('custom_terms')
            : $mrf->custom_terms;

        $poType = strtolower((string) ($request->input('po_type') ?: $mrf->po_type ?: 'goods'));
        if (! in_array($poType, ['goods', 'services', 'logistics'], true)) {
            $poType = 'goods';
        }

        // Defer standard-terms template lookup + merge until finalise. Drafts only
        // persist mode + custom_terms so saves stay under ~2s.
        $termsStarted = microtime(true);
        $mergedTerms = $request->input('po_special_terms', $mrf->po_special_terms);
        $termsMs = (int) round((microtime(true) - $termsStarted) * 1000);

        $taxRate = $request->input(
            'tax_rate',
            $request->input('taxRate', $mrf->tax_rate ?? 0)
        );
        $taxAmount = $request->has('tax_amount') || $request->has('taxAmount')
            ? (float) $request->input('tax_amount', $request->input('taxAmount'))
            : ($mrf->tax_amount ?? 0);

        $shipTo = $request->input(
            'ship_to_address',
            $request->input('shipToAddress', $mrf->ship_to_address)
        );
        $invoiceEmail = $request->input(
            'invoice_submission_email',
            $request->input('invoiceSubmissionEmail', $mrf->invoice_submission_email)
        );
        $invoiceCc = PurchaseOrderInvoiceCc::merge(
            $request->input('invoice_submission_cc', $request->input('invoiceSubmissionCc')),
            $mrf->invoice_submission_cc,
            PurchaseOrderInvoiceCc::defaultCc(),
        );

        $paymentTerms = $request->input('payment_terms', $request->input('paymentTerms'));
        if ($paymentTerms === null && ! $request->exists('payment_terms') && ! $request->exists('paymentTerms')) {
            $paymentTerms = $mrf->po_payment_terms;
        }

        $deliveryDate = null;
        foreach (['delivery_date', 'deliveryDate', 'expected_delivery_date'] as $dk) {
            $raw = $request->input($dk);
            if (is_string($raw) && trim($raw) !== '') {
                try {
                    $deliveryDate = Carbon::parse($raw)->toDateString();
                    break;
                } catch (\Throwable) {
                }
            }
        }
        if ($deliveryDate === null && ! $request->exists('delivery_date') && ! $request->exists('deliveryDate')) {
            $deliveryDate = $mrf->expected_delivery_date
                ? $mrf->expected_delivery_date->toDateString()
                : null;
        }

        $remarks = $request->has('remarks')
            ? $request->input('remarks')
            : $mrf->remarks;

        $isDraftUpdate = $mrf->isPoDraft();

        $this->applyRequestedCurrency($request, $mrf);

        $draftUpdate = [
            'po_number' => $poNumber ?: $mrf->po_number,
            'ship_to_address' => $shipTo,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'po_special_terms' => $mergedTerms !== '' && $mergedTerms !== null
                ? $mergedTerms
                : ($request->input('po_special_terms') ?? $mrf->po_special_terms),
            'custom_terms' => $customTerms,
            'po_terms_mode' => $termsMode,
            'po_type' => $poType,
            'po_payment_terms' => is_string($paymentTerms) ? $paymentTerms : $mrf->po_payment_terms,
            'invoice_submission_email' => $invoiceEmail,
            'invoice_submission_cc' => $invoiceCc,
            'remarks' => $remarks,
            'expected_delivery_date' => $deliveryDate,
            'procurement_manager_id' => $user->id,
            'po_draft_saved_at' => now(),
            'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
            'po_generation_error' => null,
            'po_generation_failed_at' => null,
        ];

        // Persist selected vendor when provided from the PO modal (vendor directory selection)
        if ($request->exists('selectedVendorId') && filled($request->input('selectedVendorId'))) {
            $sv = $request->input('selectedVendorId');
            $vendor = Vendor::query()->where('vendor_id', $sv)->first();
            if ($vendor) {
                $draftUpdate['selected_vendor_id'] = $vendor->id;
            }
        }

        $resolvedPoNumber = $draftUpdate['po_number'];
        if (
            filled($resolvedPoNumber)
            && ($mrf->is_po_linked || ($mrf->source ?? 'standard') === 'po_generated')
        ) {
            $draftUpdate['linked_po_id'] = $resolvedPoNumber;
        }

        $dbStarted = microtime(true);
        $existingCols = array_flip(\App\Support\TableColumnCache::columnsFor('m_r_f_s'));
        if ($existingCols !== []) {
            $draftUpdate = array_intersect_key($draftUpdate, $existingCols);
        }
        $mrf->update($draftUpdate);
        $dbMs = (int) round((microtime(true) - $dbStarted) * 1000);

        if (! $isDraftUpdate) {
            try {
                $draftRemark = 'PO draft saved';
                if ($fastTrack) {
                    $draftRemark .= ' (fast-tracked from Procurement Overview, executive review bypassed)';
                }
                MRFApprovalHistory::record(
                    $mrf,
                    'saved_po_draft',
                    'procurement',
                    $user,
                    $draftRemark
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to record PO draft approval history', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $paymentMilestones = app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('PO draft save completed', [
            'mrf_id' => $mrf->mrf_id,
            'po_number' => $draftUpdate['po_number'] ?? $mrf->po_number,
            'elapsed_ms' => $elapsedMs,
            'milestones_ms' => $milestonesMs,
            'terms_ms' => $termsMs,
            'db_write_ms' => $dbMs,
            'fields_persisted' => array_keys($draftUpdate),
        ]);

        $savedAt = isset($draftUpdate['po_draft_saved_at'])
            ? \Illuminate\Support\Carbon::parse($draftUpdate['po_draft_saved_at'])->toIso8601String()
            : now()->toIso8601String();

        return response()->json([
            'success' => true,
            'message' => 'PO draft saved',
            'data' => [
                'mrf' => array_merge(
                    [
                        'id' => $mrf->mrf_id,
                        'workflow_state' => $mrf->workflow_state,
                        'workflowState' => $mrf->workflow_state,
                        'status' => $mrf->status,
                        'fast_tracked' => $fastTrack,
                        'fastTracked' => $fastTrack,
                        'payment_milestones' => $paymentMilestones,
                        'paymentMilestones' => $paymentMilestones,
                    ],
                    $mrf->poFormApiFields(),
                    [
                        'po_draft_saved_at' => $savedAt,
                        'poDraftSavedAt' => $savedAt,
                    ]
                ),
                'fast_tracked' => $fastTrack,
                'timing_ms' => [
                    'total' => $elapsedMs,
                    'milestones' => $milestonesMs,
                    'terms' => $termsMs,
                    'db_write' => $dbMs,
                ],
            ],
        ]);
    }

    /**
     * Generate the authoritative PO number from a specific vendor object.
     * PO-DDMMYY-SupplierToken-NNNN (serial resets per supplier per day).
     * Mirrors the frontend formatter in src/utils/poNumber.ts.
     *
     * CRITICAL: Must be passed the SAME vendor object used for PDF rendering
     * to ensure PO number and displayed supplier match.
     */
    private function generatePONumber(MRF $mrf, ?Vendor $vendor = null): string
{
    // 1. Resolve the authoritative Vendor object if not explicitly provided
    if (! $vendor) {
        $lineService = app(PriceComparisonPoLineService::class);
        $rows = $lineService->selectedSupplierRows($mrf);
        $resolved = $lineService->resolveVendorFromRows($rows);

        if ($resolved && trim((string) $resolved->name) !== '') {
            $vendor = $resolved;
        } elseif ($mrf->selected_vendor_id) {
            $vendor = $mrf->selectedVendor;
        }
    }

    $supplierName = $vendor ? (string) $vendor->name : $this->resolveSupplierNameForPo($mrf);

    // 2. Wrap PO number allocation and persistence in a single DB transaction
    return DB::transaction(function () use ($mrf, $vendor, $supplierName) {
        
        // --- THIS IS THE CRITICAL CHANGE ---
        // Instead of passing only $supplierName (a string), we pass $vendor (the exact Database Model) 
        // if we have it, falling back to $supplierName only if $vendor is null.
        $poNumber = app(\App\Services\PoNumberGenerator::class)->generate($vendor ?: $supplierName);
        // -----------------------------------

        if ($vendor && ($mrf->selected_vendor_id !== $vendor->id)) {
            DB::table('m_r_f_s')
                ->where('id', $mrf->id)
                ->update(['selected_vendor_id' => $vendor->id]);

            // Keep the in-memory model consistent for callers that still hold the $mrf instance.
            $mrf->selected_vendor_id = $vendor->id;
        }

        return $poNumber;
    });
}

    /**
     * Resolve the supplier/vendor name used for the PO number token. Prefers the
     * selected price-comparison supplier, then the MRF's selected vendor.
     *
     * DEPRECATED: Use generatePONumber() with explicit vendor parameter instead.
     * This method maintains legacy behavior only.
     */
    private function resolveSupplierNameForPo(MRF $mrf): string
    {
        $lineService = app(PriceComparisonPoLineService::class);
        $rows = $lineService->selectedSupplierRows($mrf);
        $vendor = $lineService->resolveVendorFromRows($rows);

        if ($vendor && trim((string) $vendor->name) !== '') {
            return (string) $vendor->name;
        }

        if ($mrf->selected_vendor_id) {
            $selected = $mrf->selectedVendor;
            if ($selected && trim((string) $selected->name) !== '') {
                return (string) $selected->name;
            }
        }

        return 'Vendor';
    }

    /**
     * Prefer RFQ-backed PO data; when there is no RFQ, build a synthetic payload
     * only when fast_track or allow_missing_rfq is set (Procurement Overview flows).
     *
     * Returns array with 'resolved_vendor' key containing the authoritative Vendor object
     * used for both PO number generation and PDF rendering. This ensures consistency.
     *
     * @return array{success: bool, data?: array<string, mixed>, resolved_vendor?: ?Vendor, error?: string, code?: string, status?: int}
     */
    private function resolvePoGenerationPayload(MRF $mrf, ?RFQ $rfq, Request $request, bool $fastTrack, bool $allowMissingRfq): array
    {
        if ($rfq) {
            $result = $this->fetchPOData($mrf, $rfq);
            // fetchPOData already includes resolved_vendor in returned array
            return $result;
        }

        if ($fastTrack || $allowMissingRfq) {
            $result = $this->buildSyntheticPoPayload($mrf, $request);
            // buildSyntheticPoPayload already includes resolved_vendor in returned array
            return $result;
        }

        return [
            'success' => false,
            'error' => 'RFQ not found for this MRF. Create an RFQ first, or call with fast_track=true or allow_missing_rfq=true to generate from MRF / price comparison data only.',
            'code' => 'RFQ_NOT_FOUND',
            'status' => 404,
        ];
    }

    /**
     * Build PO PDF payload without an RFQ or quotation record (Procurement Overview / emergency path).
     *
     * @return array{success: bool, data?: array<string, mixed>, error?: string, code?: string, status?: int}
     */
    private function buildSyntheticPoPayload(MRF $mrf, Request $request): array
    {
        $vendor = null;
        foreach (['vendor_id', 'synthetic_vendor_id'] as $key) {
            $vid = $request->input($key);
            if ($vid !== null && $vid !== '' && is_numeric($vid)) {
                $vendor = Vendor::query()->find((int) $vid);
                if ($vendor) {
                    break;
                }
            }
        }
        if (! $vendor) {
            $ext = $request->input('vendor_uuid') ?? $request->input('vendorUuid');
            if (is_string($ext) && trim($ext) !== '') {
                $vendor = Vendor::query()->where('vendor_id', trim($ext))->first();
            }
        }
        if (! $vendor && $mrf->selected_vendor_id) {
            $vendor = Vendor::query()->find($mrf->selected_vendor_id);
        }

        $mrf->loadMissing('priceComparisons.vendor');
        $poLineService = app(PriceComparisonPoLineService::class);
        $selectedComparisonRows = $poLineService->selectedSupplierRows($mrf);

        $items = collect();
        if ($selectedComparisonRows->isNotEmpty()) {
            $comparisonVendor = $poLineService->resolveVendorFromRows($selectedComparisonRows);
            if ($comparisonVendor) {
                $vendor = $comparisonVendor;
            }
            $items = $poLineService->rowsToPoLineObjects($selectedComparisonRows);
        } else {
            $rows = $mrf->priceComparisons()->orderByDesc('is_selected')->orderBy('id')->get();
            if ($vendor && $rows->isNotEmpty()) {
                $forVendor = $rows->where('vendor_id', $vendor->id)->values();
                if ($forVendor->isNotEmpty()) {
                    $rows = $forVendor;
                }
            } elseif (! $vendor && $rows->isNotEmpty()) {
                $firstVid = $rows->first()->vendor_id;
                $vendor = Vendor::query()->find($firstVid);
                $rows = $rows->where('vendor_id', $firstVid)->values();
            }

            if ($rows->isNotEmpty()) {
                $items = $poLineService->rowsToPoLineObjects($rows);
            }
        }

        if ($items->isEmpty()) {
            $mrf->load('items');
            foreach ($mrf->items as $it) {
                $items->push((object) [
                    'item_name' => $it->item_name ?? 'Item',
                    'description' => $it->description ?? '',
                    'quantity' => max(1, (int) ($it->quantity ?? 1)),
                    'unit' => $it->unit ?? 'unit',
                    'unit_price' => (float) ($it->unit_price ?? 0),
                    'total_price' => (float) ($it->total_price ?? (($it->unit_price ?? 0) * max(1, $it->quantity ?? 1))),
                    'specifications' => $it->specifications ?? '',
                ]);
            }
        }

        if ($items->isEmpty()) {
            $qty = max(1, (int) ($mrf->quantity ?? 1));
            $est = (float) ($mrf->estimated_cost ?? 0);
            $unit = $qty > 0 ? $est / $qty : $est;
            $items->push((object) [
                'item_name' => $mrf->title ?: 'Goods / services',
                'description' => (string) ($mrf->description ?? ''),
                'quantity' => $qty,
                'unit' => 'unit',
                'unit_price' => $unit,
                'total_price' => $est,
                'specifications' => '',
            ]);
        }

        $currency = (string) ($mrf->currency ?? 'NGN');
        $total = $items->sum(static fn ($o) => (float) ($o->total_price ?? 0));

        $paymentTerms = (string) (
            $mrf->po_payment_terms
            ?? $request->input('payment_terms')
            ?? $request->input('paymentTerms')
            ?? 'Net 30 days'
        );
        $validityDays = (int) ($request->input('validity_days', $request->input('validityDays', 30)));
        $warranty = $request->input('warranty_period', $request->input('warrantyPeriod'));

        $deliveryDate = null;
        foreach (['delivery_date', 'deliveryDate'] as $dk) {
            $raw = $request->input($dk);
            if (is_string($raw) && trim($raw) !== '') {
                try {
                    $deliveryDate = Carbon::parse($raw);
                    break;
                } catch (\Throwable) {
                }
            }
        }

        $deliveryDays = $request->input('delivery_days', $request->input('deliveryDays'));

        $vendorBlock = $vendor ? [
            'name' => (string) $vendor->name,
            'contact_person' => (string) ($vendor->contact_person ?? ''),
            'email' => (string) ($vendor->email ?? ''),
            'phone' => (string) ($vendor->phone ?? ''),
            'address' => (string) ($vendor->address ?? ''),
            'tax_id' => (string) ($vendor->tax_id ?? ''),
        ] : [
            'name' => (string) ($request->input('vendor_name') ?? $request->input('supplier_name') ?? 'Supplier (pending confirmation)'),
            'contact_person' => '',
            'email' => (string) ($request->input('vendor_email') ?? ''),
            'phone' => (string) ($request->input('vendor_phone') ?? ''),
            'address' => (string) ($request->input('vendor_address') ?? ''),
            'tax_id' => '',
        ];

        $companyName = env('COMPANY_NAME', config('app.name', 'Emerald Industrial Co. FZE'));
        $companyAddress = env('COMPANY_ADDRESS', '');
        $companyPhone = env('COMPANY_PHONE', '');
        $companyEmail = env('COMPANY_EMAIL', config('mail.from.address', ''));
        $companyTaxId = env('COMPANY_TAX_ID', '');
        $companyWebsite = env('COMPANY_WEBSITE', 'https://emeraldcfze.com/');

        $formattedItems = $items->map(function ($item) {
            return [
                'name' => $item->item_name ?? 'Item',
                'item_name' => $item->item_name ?? 'Item',
                'description' => $item->description ?? '',
                'quantity' => $item->quantity ?? 1,
                'unit' => $item->unit ?? 'unit',
                'unit_price' => (float) ($item->unit_price ?? 0),
                'total_price' => (float) ($item->total_price ?? 0),
                'specifications' => $item->specifications ?? '',
            ];
        });

        Log::info('Built synthetic PO payload (no RFQ)', [
            'mrf_id' => $mrf->mrf_id,
            'vendor_resolved' => (bool) $vendor,
            'line_count' => $formattedItems->count(),
            'total' => $total,
        ]);

        $payload = [
            'success' => true,
            'data' => [
                'mrf' => $this->mrfBlockForPoPdf($mrf, $currency),
                'rfq' => [
                    'id' => $mrf->mrf_id . '-SYN-RFQ',
                    'title' => (string) ($mrf->title ?? 'Requisition'),
                ],
                'quotation' => [
                    'id' => 'SYN-QUOTE',
                    'total_amount' => $total,
                    'currency' => $currency,
                    'delivery_days' => $deliveryDays,
                    'delivery_date' => $deliveryDate,
                    'payment_terms' => $paymentTerms,
                    'validity_days' => $validityDays,
                    'warranty_period' => $warranty,
                ],
                'vendor' => $vendorBlock,
                'items' => $formattedItems->toArray(),
                'company' => [
                    'name' => $companyName,
                    'address' => $companyAddress,
                    'phone' => $companyPhone,
                    'email' => $companyEmail,
                    'tax_id' => $companyTaxId,
                    'website' => $companyWebsite,
                ],
            ],
        ];

        $payload['data'] = $this->enrichPoPayloadWithPaymentSchedule($mrf, $payload['data']);

        // Pass back the authoritative Vendor object used to build the payload
        // so callers (and tests) can generate PO numbers from the same source.
        $payload['resolved_vendor'] = $vendor;

        return $payload;
    }

    /**
     * Fetch all required data for PO generation
     */
    private function fetchPOData(MRF $mrf, RFQ $rfq): array
    {
        // Get selected quotation
        $quotation = null;
        if ($rfq->selected_quotation_id) {
            $quotation = Quotation::where('id', $rfq->selected_quotation_id)
                ->with(['vendor'])
                ->first();
        } else {
            // Fallback: find approved quotation
            $quotation = Quotation::where('rfq_id', $rfq->id)
                ->where('status', 'Approved')
                ->with(['vendor'])
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$quotation) {
            return [
                'success' => false,
                'error' => 'No vendor has been selected for this MRF',
                'code' => 'NO_SELECTED_QUOTATION',
                'status' => 400
            ];
        }

        $mrf->loadMissing('priceComparisons.vendor');
        $poLineService = app(PriceComparisonPoLineService::class);
        $selectedComparisonRows = $poLineService->selectedSupplierRows($mrf);

        $vendor = $quotation->vendor;
        if ($selectedComparisonRows->isNotEmpty()) {
            $comparisonVendor = $poLineService->resolveVendorFromRows($selectedComparisonRows);
            if ($comparisonVendor) {
                $vendor = $comparisonVendor;
            }
        }

        if (! $vendor) {
            return [
                'success' => false,
                'error' => 'Selected vendor not found',
                'code' => 'VENDOR_NOT_FOUND',
                'status' => 400,
            ];
        }

        if ($selectedComparisonRows->isNotEmpty()) {
            $items = $poLineService->rowsToPoLineObjects($selectedComparisonRows);
            $quotationItems = collect($items);
            $rfqItems = collect();
            $mrfItems = collect();
        } else {
        // Fetch items in order: quotation_items -> RFQ items -> MRF items
        $items = [];
        $quotationItems = collect();
        $rfqItems = collect();
        $mrfItems = collect();

        // 1. Check quotation_items table (use direct query with quotation auto-increment ID)
        $quotationItems = QuotationItem::where('quotation_id', $quotation->id)->get();
        \Log::info('PO Generation: Checking quotation items', [
            'quotation_id' => $quotation->id,
            'quotation_string_id' => $quotation->quotation_id,
            'quotation_items_count' => $quotationItems->count(),
        ]);

        if ($quotationItems->count() > 0) {
            $items = $quotationItems;
            \Log::info('PO Generation: Using quotation items', ['count' => $items->count()]);
        }
        // 2. Fallback to RFQ items
        else {
            $rfq->load('items');
            $rfqItems = $rfq->items;
            \Log::info('PO Generation: Checking RFQ items', [
                'rfq_id' => $rfq->id,
                'rfq_items_count' => $rfqItems->count(),
            ]);

            if ($rfqItems->count() > 0) {
                // Calculate unit price from quotation total
                $itemCount = $rfqItems->count();
                $unitPrice = $itemCount > 0 ? ($quotation->total_amount / $itemCount) : 0;

                $items = $rfqItems->map(function($item) use ($unitPrice) {
                    return (object) [
                        'item_name' => $item->item_name ?? 'Item',
                        'description' => $item->description ?? '',
                        'quantity' => $item->quantity ?? 1,
                        'unit' => $item->unit ?? 'unit',
                        'unit_price' => $unitPrice,
                        'total_price' => $unitPrice * ($item->quantity ?? 1),
                        'specifications' => $item->specifications ?? '',
                    ];
                });
                \Log::info('PO Generation: Using RFQ items', ['count' => $items->count()]);
            }
            // 3. Fallback to MRF items
            else {
                $mrf->load('items');
                $mrfItems = $mrf->items;
                \Log::info('PO Generation: Checking MRF items', [
                    'mrf_id' => $mrf->mrf_id,
                    'mrf_items_count' => $mrfItems->count(),
                ]);

                if ($mrfItems->count() > 0) {
                    // Calculate unit price from quotation total
                    $itemCount = $mrfItems->count();
                    $unitPrice = $itemCount > 0 ? ($quotation->total_amount / $itemCount) : 0;

                    $items = $mrfItems->map(function($item) use ($unitPrice) {
                        return (object) [
                            'item_name' => $item->item_name ?? 'Item',
                            'description' => $item->description ?? '',
                            'quantity' => $item->quantity ?? 1,
                            'unit' => $item->unit ?? 'unit',
                            'unit_price' => $unitPrice,
                            'total_price' => $unitPrice * ($item->quantity ?? 1),
                            'specifications' => $item->specifications ?? '',
                        ];
                    });
                    \Log::info('PO Generation: Using MRF items', ['count' => $items->count()]);
                }
            }
        }
        }

        // If no items found after all fallbacks
        if (empty($items) || (is_countable($items) && count($items) === 0)) {
            // Log detailed information for debugging
            \Log::error('PO Generation: No items found', [
                'mrf_id' => $mrf->mrf_id,
                'rfq_id' => $rfq->rfq_id,
                'quotation_id' => $quotation->id,
                'quotation_string_id' => $quotation->quotation_id,
                'vendor_id' => $vendor->id,
                'vendor_string_id' => $vendor->vendor_id,
                'quotation_items_count' => $quotationItems->count(),
                'rfq_items_count' => $rfqItems->count(),
                'mrf_items_count' => $mrfItems->count(),
            ]);

            return [
                'success' => false,
                'error' => 'Cannot create PO: no approved items linked to the selected vendor quotation. Please ensure items are added to the quotation when submitting.',
                'code' => 'ITEMS_MISSING',
                'status' => 400,
                'debug' => [
                    'quotation_id' => $quotation->id,
                    'quotation_string_id' => $quotation->quotation_id,
                    'vendor_id' => $vendor->id,
                    'vendor_string_id' => $vendor->vendor_id,
                    'quotation_items_found' => $quotationItems->count(),
                    'rfq_items_found' => $rfqItems->count(),
                    'mrf_items_found' => $mrfItems->count(),
                ]
            ];
        }

        // Additional validation: Ensure items are linked to the vendor quotation
        // This validates that the items we found are actually associated with this vendor's quotation
        $itemsLinkedToVendor = false;

        if ($quotationItems->count() > 0) {
            // Items are directly from quotation_items table, so they're linked to this vendor's quotation
            $itemsLinkedToVendor = true;
            \Log::info('PO Generation: Items are linked to vendor quotation via quotation_items', [
                'quotation_id' => $quotation->id,
                'vendor_id' => $vendor->id,
                'items_count' => $quotationItems->count(),
            ]);
        } else {
            // For fallback items (RFQ or MRF), verify they can be linked to this quotation
            // Check if any RFQ items are referenced by quotation items for this vendor
            if ($rfqItems->count() > 0) {
                $linkedRFQItems = QuotationItem::where('quotation_id', $quotation->id)
                    ->whereIn('rfq_item_id', $rfqItems->pluck('id'))
                    ->exists();

                if ($linkedRFQItems) {
                    $itemsLinkedToVendor = true;
                    \Log::info('PO Generation: RFQ items are linked to vendor quotation', [
                        'quotation_id' => $quotation->id,
                        'vendor_id' => $vendor->id,
                    ]);
                }
            }
        }

        // Hard validation: Items must be linked to the vendor quotation
        if (!$itemsLinkedToVendor && $quotationItems->count() === 0) {
            \Log::warning('PO Generation: Items found but not explicitly linked to vendor quotation', [
                'mrf_id' => $mrf->mrf_id,
                'quotation_id' => $quotation->id,
                'vendor_id' => $vendor->id,
                'using_fallback' => true,
            ]);
            // Note: We still proceed with fallback items, but log a warning
            // This allows backward compatibility while encouraging proper item linking
        }

        // Convert items to collection if needed
        if (!($items instanceof \Illuminate\Support\Collection)) {
            $items = collect($items);
        }

        // Company block on PO — prefer explicit env so APP_NAME=Laravel does not leak onto PDFs
        $companyName = env('COMPANY_NAME', config('app.name', 'Emerald Industrial Co. FZE'));
        $companyAddress = env('COMPANY_ADDRESS', '');
        $companyPhone = env('COMPANY_PHONE', '');
        $companyEmail = 'info@emeraldcfze.com';
        $companyTaxId = env('COMPANY_TAX_ID', '');
        $companyWebsite = 'https://emeraldcfze.com/';

        // Format items for PO template
        $formattedItems = $items->map(function($item) {
            return [
                'name' => $item->item_name ?? 'Item',
                'item_name' => $item->item_name ?? 'Item',
                'description' => $item->description ?? '',
                'quantity' => $item->quantity ?? 1,
                'unit' => $item->unit ?? 'unit',
                'unit_price' => (float) ($item->unit_price ?? 0),
                'total_price' => (float) ($item->total_price ?? 0),
                'specifications' => $item->specifications ?? '',
            ];
        });

        $result = [
            'success' => true,
            'data' => [
                'mrf' => $this->mrfBlockForPoPdf($mrf),
                'rfq' => [
                    'id' => $rfq->rfq_id,
                    'title' => $rfq->title,
                ],
                'quotation' => [
                    'id' => $quotation->quotation_id,
                    'total_amount' => $selectedComparisonRows->isNotEmpty()
                        ? $poLineService->subtotalForRows($selectedComparisonRows)
                        : $quotation->total_amount,
                    'currency' => PurchaseOrderCurrency::normalize($mrf->currency ?? $quotation->currency ?? 'NGN'),
                    'delivery_days' => $quotation->delivery_days,
                    'delivery_date' => $quotation->delivery_date ? ($quotation->delivery_date instanceof \DateTime || $quotation->delivery_date instanceof \Carbon\Carbon ? $quotation->delivery_date : \Carbon\Carbon::parse($quotation->delivery_date)) : null,
                    'payment_terms' => $quotation->payment_terms,
                    'validity_days' => $quotation->validity_days,
                    'warranty_period' => $quotation->warranty_period,
                ],
                'vendor' => [
                    'name' => $vendor->name,
                    'contact_person' => $vendor->contact_person,
                    'email' => $vendor->email,
                    'phone' => $vendor->phone,
                    'address' => $vendor->address,
                    'tax_id' => $vendor->tax_id,
                ],
                'items' => $formattedItems->toArray(),
                'company' => [
                    'name' => $companyName,
                    'address' => $companyAddress,
                    'phone' => $companyPhone,
                    'email' => $companyEmail,
                    'tax_id' => $companyTaxId,
                    'website' => $companyWebsite,
                ],
            ],
        ];

        $result['data'] = $this->enrichPoPayloadWithPaymentSchedule($mrf, $result['data']);

        // Include the resolved Vendor object so callers can rely on a single
        // source of truth for both PO number generation and PDF rendering.
        $result['resolved_vendor'] = $vendor;

        return $result;
    }

    /**
     * MRF fields required by {@see PurchaseOrderPdfService::htmlFromWorkflow()}.
     *
     * @return array<string, mixed>
     */
    private function mrfBlockForPoPdf(MRF $mrf, ?string $currency = null): array
    {
        return [
            'id' => $mrf->mrf_id,
            'formatted_id' => $mrf->formatted_id,
            'title' => $mrf->title,
            'description' => $mrf->description,
            'justification' => $mrf->justification,
            'requester_name' => $mrf->requester_name,
            'department' => $mrf->department,
            'category' => $mrf->category,
            'estimated_cost' => $mrf->estimated_cost,
            'currency' => $currency ?? $mrf->currency ?? 'NGN',
            'date' => $mrf->date ?? $mrf->created_at,
            'contract_type' => $mrf->contract_type,
            'po_type' => $mrf->po_type ?: 'goods',
            'po_terms_mode' => $mrf->po_terms_mode,
            'custom_terms' => $mrf->custom_terms,
            'po_payment_terms' => $mrf->po_payment_terms,
            'po_special_terms' => $mrf->po_special_terms,
            'ship_to_address' => $mrf->ship_to_address,
            'invoice_submission_email' => $mrf->invoice_submission_email,
            'invoice_submission_cc' => $mrf->invoice_submission_cc,
            'tax_rate' => $mrf->tax_rate,
        ];
    }

    /**
     * Generate PO PDF document
     */
    private function generatePOPDF(array $data, string $poNumber, $user): string
    {
        return app(PurchaseOrderPdfService::class)->renderWorkflowPdf($data, $poNumber, $user);
    }

    /**
     * How standard vs custom PO terms are combined. Frontend: radio or equivalent
     * sending terms_mode or po_terms_mode: standard | custom | both (default both).
     */
    private function normalisePOTermsMode(Request $request, ?MRF $mrf = null): string
    {
        $raw = $request->input('terms_mode');
        if ($raw === null || $raw === '') {
            $raw = $request->input('po_terms_mode');
        }
        if ($raw !== null && (string) $raw !== '') {
            $m = strtolower(trim((string) $raw));

            return in_array($m, ['standard', 'custom', 'both'], true) ? $m : 'both';
        }
        if ($mrf !== null && $mrf->po_terms_mode) {
            $m = strtolower(trim((string) $mrf->po_terms_mode));

            return in_array($m, ['standard', 'custom', 'both'], true) ? $m : 'both';
        }

        return 'both';
    }

    /**
     * @param  string|null  $standardTerms  Active template body for po_type
     * @param  string|null  $customTerms    User additional terms
     */
    private function mergePoSpecialTerms(string $mode, ?string $standardTerms, ?string $customTerms): string
    {
        $standard = trim((string) ($standardTerms ?? ''));
        $custom = trim((string) ($customTerms ?? ''));
        $mode = strtolower($mode);
        if (! in_array($mode, ['standard', 'custom', 'both'], true)) {
            $mode = 'both';
        }
        if ($mode === 'standard') {
            return $standard;
        }
        if ($mode === 'custom') {
            return $custom;
        }
        if ($standard === '') {
            return $custom;
        }
        if ($custom === '') {
            return $standard;
        }

        return $standard . "\n\n" . $custom;
    }

    /**
     * Resolve MRF for generate-po: route {id} first, then optional JSON/form body
     * identifiers (mrf_id, mrfId, formatted_id, formattedId) when the client
     * accidentally posts a stale path id while the body still carries the
     * correct MRF the user is editing.
     */
    private function findMrfForPoRequest(Request $request, string $routeId, array $with = []): ?MRF
    {
        $routeId = trim($routeId);
        $mrf = $this->findMrfByAnyId($routeId, $with);
        if ($mrf) {
            return $mrf;
        }

        $candidates = [];
        foreach (['mrf_id', 'mrfId', 'formatted_id', 'formattedId'] as $key) {
            $v = $request->input($key);
            if (is_string($v)) {
                $t = trim($v);
                if ($t !== '' && strcasecmp($t, $routeId) !== 0) {
                    $candidates[] = $t;
                }
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $found = $this->findMrfByAnyId($candidate, $with);
            if ($found) {
                Log::warning('generate-po: resolved MRF via body fallback (route id not found)', [
                    'route_id' => $routeId,
                    'resolved_from' => $candidate,
                ]);

                return $found;
            }
        }

        return null;
    }

    /**
     * Resolve route {id} to an MRF using legacy mrf_id, formatted_id, or numeric primary key.
     */
    private function findMrfByAnyId(string $id, array $with = []): ?MRF
    {
        $query = MRF::query()->where(function ($q) use ($id) {
            $q->where('mrf_id', $id)->orWhere('formatted_id', $id);
            if ($id !== '' && is_numeric((string) $id)) {
                $q->orWhere('id', (int) $id);
            }
        });

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enrichPoPayloadWithPaymentSchedule(MRF $mrf, array $data): array
    {
        $scheduleService = app(PaymentScheduleService::class);
        $schedule = $scheduleService->findForMrf($mrf);

        if (! $schedule) {
            $data['payment_schedule'] = null;
            $data['payment_milestones'] = [];

            return $data;
        }

        $quotation = $data['quotation'] ?? [];
        $items = $data['items'] ?? [];
        $currency = PurchaseOrderCurrency::normalize($mrf->currency ?? $quotation['currency'] ?? 'NGN');

        $poTotal = (float) ($quotation['total_amount'] ?? 0);
        if ($poTotal <= 0) {
            foreach ($items as $item) {
                $poTotal += (float) ($item['total_price'] ?? 0);
            }
        }
        if ($poTotal <= 0) {
            $poTotal = (float) ($mrf->estimated_cost ?? 0);
        }

        $data['payment_schedule'] = $scheduleService->toApiArray($schedule);
        $data['payment_milestones'] = $scheduleService->milestonesForPoPdf($schedule, $poTotal, $currency);

        return $data;
    }

    private function registerPoPdfInRegistry(
        MRF $mrf,
        $user,
        string $storagePath,
        string $fileUrl,
        string $fileName,
    ): void {
        try {
            app(ProcurementDocumentService::class)->registerExistingStorageFile(
                $mrf,
                ProcurementDocument::TYPE_PO_PDF,
                $storagePath,
                $fileUrl,
                $fileName,
                $user,
                app(ProcurementDocumentService::class)->resolveVendorId($mrf),
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to register PO PDF in procurement document registry', [
                'mrf_id' => $mrf->mrf_id,
                'path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function registerSignedPoInRegistry(
        MRF $mrf,
        $user,
        string $storagePath,
        string $fileUrl,
        string $fileName,
    ): void {
        try {
            app(ProcurementDocumentService::class)->registerExistingStorageFile(
                $mrf,
                ProcurementDocument::TYPE_SIGNED_PO,
                $storagePath,
                $fileUrl,
                $fileName,
                $user,
                app(ProcurementDocumentService::class)->resolveVendorId($mrf),
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to register signed PO in procurement document registry', [
                'mrf_id' => $mrf->mrf_id,
                'path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist request currency on the MRF before PO PDF generation or draft save.
     */
    private function applyRequestedCurrency(Request $request, MRF $mrf): void
    {
        if (! $request->filled('currency')) {
            return;
        }

        $currency = PurchaseOrderCurrency::normalize($request->input('currency'));
        if (PurchaseOrderCurrency::normalize($mrf->currency) !== $currency) {
            $mrf->update(['currency' => $currency]);
            $mrf->refresh();
        }
    }
}
