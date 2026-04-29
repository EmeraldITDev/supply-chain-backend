<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\RFQ;
use App\Models\Quotation;
use App\Models\Vendor;
use App\Models\QuotationItem;
use App\Services\NotificationService;
use App\Services\EmailService;
use App\Services\WorkflowNotificationService;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use App\Services\PurchaseOrderPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Dompdf\Dompdf;
use Dompdf\Options;

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
        if (!in_array($user->role, ['supply_chain_director', 'director', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can approve at this stage',
                'code' => 'FORBIDDEN',
                'requiredRole' => 'supply_chain_director'
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

        // Check if MRF is in pending supply chain director review
        if ($mrf->workflow_state !== 'supply_chain_director_review') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not awaiting Supply Chain Director review',
                'code' => 'INVALID_WORKFLOW_STATE',
                'currentWorkflowState' => $mrf->workflow_state,
                'expectedState' => 'supply_chain_director_review'
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
            'status' => $isApproved ? 'procurement_review' : 'rejected',
            'current_stage' => $isApproved ? 'procurement_review' : 'rejected',
            'workflow_state' => $isApproved ? 'supply_chain_director_approved' : 'supply_chain_director_rejected',
            'remarks' => $request->remarks,
            'director_approved_at' => $isApproved ? now() : null,
            'director_approved_by' => $isApproved ? $user->name : null,
            'director_remarks' => $isApproved ? $request->remarks : null,
            'procurement_review_started_at' => $isApproved ? now() : null,
            'last_action_by_role' => in_array($user->role, ['admin']) ? 'admin' : 'supply_chain_director',
        ]);

        try {
            $mrf->load('requester');
            $this->notificationService->notifyMRFApproved($mrf, $user, $request->remarks);
        } catch (\Exception $e) {
            \Log::error('Failed to send MRF approved notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        // Record approval history
        $approvalRecord = MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'stage' => 'supply_chain_director',
            'action' => $isApproved ? 'approved' : 'rejected',
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_email' => $user->email,
            'remarks' => $request->remarks,
            'performer_name' => $user->name,
            'performer_role' => $user->role,
            'performed_by' => $user->id
        ]);

        // Log activity
        try {
            Activity::create([
                'type' => 'mrf_approved',
                'title' => $isApproved ? 'MRF Approved by Supply Chain Director' : 'MRF Rejected by Supply Chain Director',
                'description' => $isApproved ?
                    "MRF {$mrf->mrf_id} was approved by Supply Chain Director {$user->name} and forwarded to Procurement Manager review" :
                    "MRF {$mrf->mrf_id} was rejected by Supply Chain Director {$user->name}",
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
                'MRF approved and forwarded to Procurement Manager' :
                'MRF rejected',
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
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can approve at this stage',
                'code' => 'FORBIDDEN',
                'requiredRole' => 'procurement_manager'
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

        $isEmeraldContract = strtolower(trim((string) $mrf->contract_type)) === 'emerald';

        // Contract-type driven initial approval gate:
        // - Emerald: procurement can only proceed after executive approval
        // - Non-Emerald: procurement can only proceed after initial SCD approval
        $validStates = $isEmeraldContract
            ? ['executive_approved', 'procurement_review']
            : ['supply_chain_director_approved', 'procurement_review'];

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

        // Update RFQ workflow state to supply_chain_review
        if ($rfq) {
            $rfq->update([
                'workflow_state' => 'supply_chain_review',
                'selected_vendor_id' => $vendor->id,
                'selected_quotation_id' => $quotation->id,
            ]);
            Log::info('RFQ workflow state updated to supply_chain_review', ['rfq_id' => $rfq->rfq_id, 'mrf_id' => $mrf->mrf_id]);
        }

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'vendor_selected', 'procurement', $user,
            "Vendor {$vendor->name} selected and sent for Supply Chain Director approval. " . ($request->remarks ?? ''));

        // Prepare complete data for notification and response
        // Include ALL quotation details - nothing should be hidden or summarized
        $completeQuotationData = [
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
                'attachments' => $quotation->attachments ?? [], // All uploaded documents
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
                'estimatedCost' => (float) $mrf->estimated_cost,
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
            $scdUsers = \App\Models\User::whereIn('role', ['supply_chain_director', 'supply_chain', 'admin'])->get();
            foreach ($scdUsers as $scdUser) {
                $scdUser->notify(new \App\Notifications\SystemAnnouncementNotification(
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

        // Notify Procurement that vendor is approved and PO upload is required
        // Provide clear actionable guidance: "Upload PO" is the next step
        try {
            $procurementUsers = \App\Models\User::whereIn('role', ['procurement', 'procurement_manager', 'admin'])->get();
            foreach ($procurementUsers as $procUser) {
                $procUser->notify(new \App\Notifications\SystemAnnouncementNotification(
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

        if (strtolower(trim((string) $mrf->contract_type)) !== 'emerald') {
            return response()->json([
                'success' => false,
                'error' => 'Executive approval is only valid for Emerald contract MRFs.',
                'code' => 'INVALID_CONTRACT_WORKFLOW',
            ], 422);
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
            'last_action_by_role' => in_array($user->role, ['admin']) ? 'admin' : 'executive',
        ]);

        try {
            $mrf->load('requester');
            $this->notificationService->notifyMRFApproved($mrf, $user, $request->remarks);
        } catch (\Exception $e) {
            \Log::error('Failed to send MRF approved notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

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
            'procurement_review_started_at' => now(),
            'last_action_by_role' => in_array($user->role, ['admin']) ? 'admin' : 'executive',
        ]);

        // Record in approval history
        MRFApprovalHistory::record($mrf, 'approved', 'executive_review', $user, $request->remarks ?? '');

        // Log activity
        try {
            Activity::create([
                'type' => 'mrf_approved',
                'title' => 'MRF Approved by Executive',
                'description' => "MRF {$mrf->mrf_id} was approved by {$user->name}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'mrf',
                'entity_id' => $mrf->mrf_id,
                'status' => 'approved',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log MRF approval activity', ['error' => $e->getMessage()]);
        }

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

        // Check permissions using PermissionService (includes RFQ approval check)
        if (!$this->permissionService->canGeneratePO($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'PO generation not allowed at this stage. RFQ must be approved by Supply Chain Director first.',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Verify RFQ is approved - load with items relationship
        $rfq = \App\Models\RFQ::where('mrf_id', $mrf->id)->with('items')->first();
        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found for this MRF. Please create an RFQ first.',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if ($rfq->workflow_state !== 'approved') {
            return response()->json([
                'success' => false,
                'error' => 'RFQ must be approved by Supply Chain Director before generating PO. Current RFQ status: ' . ($rfq->workflow_state ?? 'unknown'),
                'code' => 'RFQ_NOT_APPROVED',
                'data' => [
                    'rfq_id' => $rfq->rfq_id,
                    'rfq_workflow_state' => $rfq->workflow_state,
                ]
            ], 400);
        }

        // Check if MRF is in procurement status (allow both 'procurement', 'pending_po_upload', and rejected PO statuses)
        // Use case-insensitive comparison
        $statusLower = strtolower(trim($mrf->status ?? ''));
        $allowedStatuses = ['procurement', 'pending_po_upload', 'po rejected', 'pending']; // Allow pending_po_upload for PO upload after SCD approval

        // Special case: If MRF is in pending_po_upload status, this is the expected flow after SCD approval
        if ($statusLower === 'pending_po_upload') {
            // Verify RFQ is approved (SCD must have approved)
            if ($rfq && $rfq->workflow_state !== 'approved') {
                return response()->json([
                    'success' => false,
                    'error' => 'RFQ must be approved by Supply Chain Director before PO can be generated. Current RFQ status: ' . ($rfq->workflow_state ?? 'unknown'),
                    'code' => 'RFQ_NOT_APPROVED',
                ], 400);
            }
        }

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

        // Check if this is a file upload (Mode 1) or auto-generation (Mode 2)
        $isFileUpload = $request->hasFile('unsigned_po');
        $isAutoGeneration = $request->has('po_number') && !$isFileUpload;

        if ($isFileUpload) {
            // Mode 1: File Upload (Existing behavior)
        $validator = Validator::make($request->all(), [
                'po_number' => 'nullable|string|max:255',
                'unsigned_po' => 'required|file|mimes:pdf,doc,docx|max:20480',
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

            // Use provided PO number or auto-generate
            $poNumber = $request->po_number ?? $this->generatePONumber($mrf);
        } else {
            // Mode 2: Auto-Generation (JSON body)
            $validator = Validator::make($request->all(), [
                'po_number' => 'nullable|string|max:255',
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

            // Use provided PO number or auto-generate
            $poNumber = $request->po_number ?? $this->generatePONumber($mrf);
        }

        // Check if PO number already exists (for uniqueness)
        if (MRF::where('po_number', $poNumber)->where('id', '!=', $mrf->id)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'PO number already exists. Please use a different PO number.',
                'code' => 'DUPLICATE_PO_NUMBER'
            ], 422);
        }

        // Handle file upload mode (Mode 1)
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
            // Mode 2: Auto-Generation
            // Fetch all required data for PO generation
            $poData = $this->fetchPOData($mrf, $rfq);

            if (!$poData['success']) {
            return response()->json([
                'success' => false,
                    'error' => $poData['error'],
                    'code' => $poData['code']
                ], $poData['status'] ?? 400);
            }

            // Generate PO PDF document
            try {
                $pdfContent = $this->generatePOPDF($poData['data'], $poNumber, $user);

                // Save PDF to storage (S3)
                $poUrl = null;
                $poShareUrl = null;

                // Delete old PO file if regenerating
                if ($isRegeneration && $mrf->unsigned_po_url) {
                    try {
                    $disk = $this->getStorageDisk();
                    // Try to extract file path from URL
                    $urlPath = parse_url($mrf->unsigned_po_url, PHP_URL_PATH);
                    if ($urlPath) {
                        // Extract path from S3 URL or local URL
                        $baseUrl = Storage::disk($disk)->url('');
                        $oldPath = str_replace($baseUrl, '', $mrf->unsigned_po_url);
                        $oldPath = ltrim(str_replace('/storage/', '', $oldPath), '/');

                        // Try multiple path formats
                        $possiblePaths = [
                            $oldPath,
                            ltrim($urlPath, '/'),
                            str_replace('/storage/', '', $urlPath),
                        ];

                        foreach ($possiblePaths as $path) {
                            if (!empty($path) && Storage::disk($disk)->exists($path)) {
                                Storage::disk($disk)->delete($path);
                                Log::info('Deleted old PO file for regeneration', ['old_path' => $path]);
                                break;
                            }
                        }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete old PO file', ['error' => $e->getMessage()]);
                    }
                }

            // Upload to S3 storage
            $disk = $this->getStorageDisk();

                if (!config("filesystems.disks.{$disk}")) {
                throw new \Exception("Storage disk '{$disk}' is not configured.");
                }

            $poFileName = "po_{$poNumber}_" . time() . ".pdf";
            $poPath = "purchase-orders/" . date('Y/m') . "/{$poFileName}";

            // Ensure directory structure exists (for S3, this is just the path)
                    $directory = dirname($poPath);
            if ($disk !== 's3' && !Storage::disk($disk)->exists($directory)) {
                Storage::disk($disk)->makeDirectory($directory, 0755, true);
            }

            // Store PDF
            Storage::disk($disk)->put($poPath, $pdfContent);

            // Get URL (temporary signed URL for S3, public URL for local)
            $poUrl = $this->getFileUrl($poPath, $disk);
            $poShareUrl = $poUrl;

            Log::info('PO PDF generated and stored', [
                    'mrf_id' => $id,
                    'po_number' => $poNumber,
                    'file_name' => $poFileName,
                'stored_path' => $poPath,
                    'url' => $poUrl,
                'disk' => $disk
                ]);

            if (empty($poUrl)) {
                    throw new \Exception('PO PDF generation failed - no URL was generated');
            }

        } catch (\Exception $e) {
                Log::error('PO PDF generation failed', [
                'mrf_id' => $id,
                'po_number' => $poNumber,
                'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                    'error' => 'Failed to generate PO document: ' . $e->getMessage(),
                    'code' => 'PDF_GENERATION_FAILED'
            ], 500);
            }
        }

        // Calculate tax if tax_rate is provided
        $taxRate = $request->tax_rate ?? 0;
        $taxAmount = 0;

        // Calculate subtotal from items
        $subtotal = 0;
        if (isset($poData['data']['items'])) {
            foreach ($poData['data']['items'] as $item) {
                $unitPrice = $item['unit_price'] ?? ($item['total_price'] ?? 0) / ($item['quantity'] ?? 1);
                $subtotal += ($unitPrice * ($item['quantity'] ?? 1));
            }
        }

        // Calculate tax amount if tax_rate is provided
        if ($taxRate > 0) {
            $taxAmount = ($subtotal * $taxRate) / 100;
        } elseif ($request->has('tax_amount')) {
            $taxAmount = $request->tax_amount;
        }

        // Update MRF - set workflow state to finance after PO generation
        $updateData = [
            'po_number' => $poNumber,
            'unsigned_po_url' => $poUrl,
            'po_generated_at' => now(),
            'workflow_state' => WorkflowStateService::STATE_PO_GENERATED,
            'status' => 'finance', // Changed to finance as per requirements
            'current_stage' => 'finance', // Changed to finance as per requirements
            'rejection_reason' => null, // Clear rejection reason if regenerating
            // PO Details
            'ship_to_address' => $request->ship_to_address ?? null,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'po_special_terms' => $request->po_special_terms ?? null,
            'invoice_submission_email' => $request->invoice_submission_email ?? null,
            'invoice_submission_cc' => $request->invoice_submission_cc ?? null,
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

        // Log activity
        try {
            Activity::create([
                'type' => 'po_generated',
                'title' => 'PO Generated',
                'description' => "Purchase Order {$poNumber} was generated for MRF {$mrf->mrf_id}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'mrf',
                'entity_id' => $mrf->mrf_id,
                'status' => 'finance',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log PO generation activity', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber,
                'error' => $e->getMessage()
            ]);
        }

        // Notify Finance team about PO generation
        try {
            $financeTeam = \App\Models\User::whereIn('role', ['finance', 'admin'])->get();

            foreach ($financeTeam as $finance) {
                $finance->notify(new \App\Notifications\SystemAnnouncementNotification(
                    'PO Generated',
                    "Purchase Order {$poNumber} for MRF {$mrf->mrf_id} has been generated and is ready for review.",
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('PO generation notification sent to Finance team', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send PO generation notification to Finance team', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber,
                'error' => $e->getMessage()
            ]);
        }

        // Also notify Supply Chain Director (for signature workflow)
        $this->notificationService->notifyPOReadyForSignature($mrf);
        try {
            $mrf->loadMissing(['requester', 'selectedVendor']);
            $this->workflowNotificationService->notifyPOGenerated($mrf);
        } catch (\Exception $e) {
            Log::error('Failed to send PO generated email notifications', [
                'event' => 'po_generated',
                'recipient' => null,
                'model_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Refresh MRF to get updated values
        $mrf->refresh();

        $poStreamUrl = $mrf->freshUnsignedPoStreamUrl() ?? $mrf->unsigned_po_url;

        return response()->json([
            'success' => true,
            'message' => 'PO generated successfully',
            'data' => [
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
                ],
                'po_url' => $poStreamUrl,
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

        // Get vendor
        $vendor = $quotation->vendor;
        if (!$vendor) {
            return [
                'success' => false,
                'error' => 'Selected vendor not found',
                'code' => 'VENDOR_NOT_FOUND',
                'status' => 400
            ];
        }

        // Validate: Ensure quotation belongs to the selected vendor
        // This ensures we're using the correct vendor's quotation
        if ($quotation->vendor_id != $vendor->id) {
            return [
                'success' => false,
                'error' => 'Quotation vendor mismatch. The selected quotation does not belong to the expected vendor.',
                'code' => 'VENDOR_MISMATCH',
                'status' => 400
            ];
        }

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
        $companyEmail = env('COMPANY_EMAIL', config('mail.from.address', ''));
        $companyTaxId = env('COMPANY_TAX_ID', '');
        $companyWebsite = env('COMPANY_WEBSITE', 'https://emeraldcfze.com/');

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

        return [
            'success' => true,
            'data' => [
                'mrf' => [
                    'id' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'description' => $mrf->description,
                    'justification' => $mrf->justification,
                    'requester_name' => $mrf->requester_name,
                    'department' => $mrf->department,
                    'estimated_cost' => $mrf->estimated_cost,
                    'currency' => $mrf->currency ?? 'NGN',
                    'date' => $mrf->date ?? $mrf->created_at,
                ],
                'rfq' => [
                    'id' => $rfq->rfq_id,
                    'title' => $rfq->title,
                ],
                'quotation' => [
                    'id' => $quotation->quotation_id,
                    'total_amount' => $quotation->total_amount,
                    'currency' => $quotation->currency ?? 'NGN',
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
            ]
        ];
    }

    /**
     * Generate PO PDF document
     */
    private function generatePOPDF(array $data, string $poNumber, $user): string
    {
        $html = app(PurchaseOrderPdfService::class)->htmlFromWorkflow($data, $poNumber, $user);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
