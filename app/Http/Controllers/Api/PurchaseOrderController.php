<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StorePurchaseOrderRequest;
use App\Http\Requests\Procurement\UnlockPurchaseOrderForEditRequest;
use App\Http\Requests\Procurement\UpdatePurchaseOrderRequest;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\ProcurementDocument;
use App\Services\FinanceAp\ClosureReadinessService;
use App\Services\ProcurementDocumentService;
use App\Services\PurchaseOrderRevisionService;
use App\Services\PurchaseOrderService;
use App\Services\ScmAuditService;
use App\Services\VendorFulfilmentService;
use App\Services\WorkflowStateService;
use App\Support\RequestLineItemParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    use ResolvesPaginatedLists;

    public function __construct(
        private PurchaseOrderService $purchaseOrders,
        private PurchaseOrderRevisionService $revisions,
        private ClosureReadinessService $closureReadiness,
        private WorkflowStateService $workflowStateService,
    ) {
    }

    /**
     * GET /api/pos — paginated PO list (MRF-backed).
     */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->ensurePoAccess($request)) {
            return $denied;
        }

        $query = $this->purchaseOrders->listQuery($request)
            ->with(['selectedVendor:id,vendor_id,name']);

        $paginator = $this->paginateWithCachedCount($query, $request, 'mrf_po');

        $items = collect($paginator->items())
            ->map(fn (MRF $mrf) => $this->purchaseOrders->mapListRow($mrf))
            ->values()
            ->all();

        return response()->json(array_merge(
            $this->paginatedJsonResponse($paginator, $items),
            ['pos' => $items],
        ));
    }

    /**
     * GET /api/pos/{id} — lightweight edit-modal payload.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->ensurePoAccess($request)) {
            return $denied;
        }

        $mrf = $this->purchaseOrders->findForEdit($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'Purchase order not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->purchaseOrders->mapEditPayload($mrf),
        ]);
    }

    /**
     * POST /api/pos — create PO shell (no PDF, no notifications).
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['items'] = RequestLineItemParser::resolve($request);

        $mrf = $this->purchaseOrders->createDraft($request->user(), $validated);

        if ($request->hasFile('documents')) {
            $this->attachDocumentsToMrf($request, $mrf);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase order created',
            'data' => $this->purchaseOrders->mapEditPayload(
                $this->purchaseOrders->findForEdit($mrf->mrf_id) ?? $mrf
            ),
        ], 201);
    }

    /**
     * PUT /api/pos/{id} — update draft or pending_revision PO fields.
     */
    public function update(UpdatePurchaseOrderRequest $request, string $id): JsonResponse
    {
        $mrf = $this->findMrfByPoReference($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'Purchase order not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validated = $request->validated();
        $validated['items'] = RequestLineItemParser::resolve($request);

        $payload = null;

        try {
            if ($this->revisions->isPendingRevision($mrf)) {
                $payload = $this->revisions->updatePendingRevision($mrf, $validated);
                $updated = $this->purchaseOrders->findForEdit($mrf->mrf_id) ?? $mrf->fresh() ?? $mrf;
            } elseif ($this->revisions->isDraftEditable($mrf)) {
                $updated = $this->purchaseOrders->updateDraft($mrf, $validated);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'This purchase order cannot be edited in its current status. Unlock a signed PO first.',
                    'code' => 'INVALID_STATUS',
                    'current_status' => $mrf->status,
                    'current_workflow_state' => $mrf->workflow_state,
                ], 422);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Update blocked',
                'errors' => $e->errors(),
                'code' => 'INVALID_STATUS',
            ], 422);
        }

        if ($request->hasFile('documents')) {
            $this->attachDocumentsToMrf($request, $updated);
        }

        $fresh = $this->purchaseOrders->findForEdit($updated->mrf_id) ?? $updated;

        return response()->json([
            'success' => true,
            'message' => 'Purchase order updated',
            'data' => $payload['data'] ?? $this->purchaseOrders->mapEditPayload($fresh),
        ]);
    }

    /**
     * POST /api/pos/{id}/unlock-for-edit
     */
    public function unlockForEdit(UnlockPurchaseOrderForEditRequest $request, string $id): JsonResponse
    {
        $mrf = $this->findMrfByPoReference($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'Purchase order not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $reason = trim((string) ($request->input('reason') ?: $request->input('unlock_reason', '')));

        try {
            $payload = $this->revisions->unlockForEdit($mrf, $request->user(), $reason);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Unable to unlock purchase order',
                'errors' => $e->errors(),
                'code' => 'INVALID_STATUS',
            ], 422);
        }

        return response()->json($payload);
    }

    /**
     * POST /api/pos/{id}/submit-for-resign
     */
    public function submitForResign(Request $request, string $id): JsonResponse
    {
        if (! PurchaseOrderRevisionService::userCanRevise($request->user())) {
            return response()->json([
                'success' => false,
                'error' => 'Only Procurement Managers and Admins can submit a revised PO for re-signing.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $mrf = $this->findMrfByPoReference($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'Purchase order not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        try {
            $payload = $this->revisions->submitForResign($mrf, $request->user());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Unable to submit for re-signing',
                'errors' => $e->errors(),
                'code' => 'INVALID_STATUS',
            ], 422);
        }

        return response()->json($payload);
    }

    /**
     * POST /api/pos/{id}/close
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $allowedRoles = [
            'procurement_manager',
            'procurement',
            'finance',
            'finance_officer',
            'supply_chain_director',
            'supply_chain',
            'admin',
        ];

        $hasAllowedRole =
            ($user->scmRole() !== null && in_array($user->scmRole(), $allowedRoles, true))
            || (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (! $hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $mrf = $this->findMrfByPoReference($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'Purchase order not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (($mrf->workflow_state ?? null) === WorkflowStateService::STATE_CLOSED) {
            return response()->json([
                'success' => true,
                'message' => 'Purchase order is already closed',
                'data' => [
                    'mrfId' => $mrf->mrf_id,
                    'poNumber' => $mrf->po_number,
                    'workflowState' => $mrf->workflow_state,
                ],
            ]);
        }

        $readiness = $this->closureReadiness->evaluate($mrf);

        if (! $readiness['can_close']) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot close purchase order yet',
                'code' => 'CLOSURE_BLOCKED',
                'blockers' => $readiness['blockers'],
                'missing_documents' => $readiness['missing_documents'] ?? [],
            ], 422);
        }

        if (! $this->workflowStateService->transition($mrf, WorkflowStateService::STATE_CLOSED, $user)) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to transition purchase order to closed',
                'code' => 'TRANSITION_FAILED',
                'blockers' => $readiness['blockers'],
                'missing_documents' => $readiness['missing_documents'] ?? [],
            ], 422);
        }

        $mrf->refresh();

        // If GRN already updated fulfilment, skip; otherwise update vendor counters on close.
        if ($mrf->grn_completed) {
            // GRN path already called recordCycleCompleted
        } else {
            try {
                app(\App\Services\VendorFulfilmentService::class)->recordCycleCompleted($mrf, false);
            } catch (\Throwable $e) {
                \Log::warning('Vendor fulfilment update on PO close failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase order closed successfully',
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'poNumber' => $mrf->po_number,
                'workflowState' => $mrf->workflow_state,
            ],
        ]);
    }

    /**
     * POST /api/pos/{id}/force-close
     *
     * Exception path when Finance AP has not updated/closed the record.
     * Does not replace the normal close endpoint. Admin + Procurement Manager only.
     * Label for UI: "Force Close — Finance AP Status Not Updated"
     *
     * Accepts multipart: reason (required), waybill / waybill_file (optional).
     * Close + history + metadata run in one DB transaction so a history failure
     * cannot leave the MRF closed while the client sees an error.
     */
    public function forceClose(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $allowedRoles = ['admin', 'procurement_manager'];

        $hasAllowedRole =
            ($user->scmRole() !== null && in_array($user->scmRole(), $allowedRoles, true))
            || (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (! $hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Only Admin or Procurement Manager may force-close',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $mrf = $this->findMrfByPoReference($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'Purchase order not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|min:10|max:5000',
            'force_close_reason' => 'nullable|string|min:10|max:5000',
            'waybill' => 'nullable|file|max:20480',
            'waybill_file' => 'nullable|file|max:20480',
            'document' => 'nullable|file|max:20480',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid force-close payload',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $reason = trim((string) ($request->input('reason') ?? $request->input('force_close_reason') ?? ''));
        $waybillFile = $request->file('waybill')
            ?? $request->file('waybill_file')
            ?? $request->file('document');

        // Already closed: optionally backfill force-close metadata / waybill (retry after partial failure).
        if (($mrf->workflow_state ?? null) === WorkflowStateService::STATE_CLOSED) {
            if ($reason !== '' && empty($mrf->force_close_reason)) {
                $mrf->forceFill([
                    'force_closed_at' => $mrf->force_closed_at ?? now(),
                    'force_closed_by' => $mrf->force_closed_by ?? $user->id,
                    'force_close_reason' => $reason,
                ])->save();
            }

            $waybillDoc = null;
            if ($waybillFile instanceof UploadedFile) {
                try {
                    $waybillDoc = app(ProcurementDocumentService::class)->storeUpload(
                        $mrf,
                        $waybillFile,
                        ProcurementDocument::TYPE_WAYBILL,
                        $user
                    );
                } catch (\Throwable $e) {
                    Log::warning('Waybill upload on already-closed force-close retry failed', [
                        'mrf_id' => $mrf->mrf_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase order is already closed',
                'data' => [
                    'mrfId' => $mrf->mrf_id,
                    'poNumber' => $mrf->po_number,
                    'workflowState' => $mrf->workflow_state,
                    'status' => $mrf->status,
                    'forceClosedAt' => optional($mrf->force_closed_at)?->toIso8601String(),
                    'forceCloseReason' => $mrf->force_close_reason,
                    'waybillDocumentId' => $waybillDoc?->id,
                ],
            ]);
        }

        if ($reason === '' || strlen($reason) < 10) {
            return response()->json([
                'success' => false,
                'error' => 'A mandatory reason is required for force close (min 10 characters)',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        if (! $this->isForceCloseEligible($mrf)) {
            $readiness = $this->closureReadiness->evaluate($mrf);

            return response()->json([
                'success' => false,
                'error' => 'Force close is only available when payment/completion conditions are met but Finance AP has not closed the record',
                'code' => 'FORCE_CLOSE_NOT_ELIGIBLE',
                'blockers' => $readiness['blockers'] ?? [],
                'eligibilityHint' => 'Require paid/complete milestones, financially_complete, or legacy paid/completed status.',
            ], 422);
        }

        $previousStatus = $mrf->status;
        $previousWorkflow = $mrf->workflow_state;

        try {
            DB::transaction(function () use (
                $request,
                $mrf,
                $user,
                $reason,
                $previousStatus,
                $previousWorkflow
            ) {
                if (! $this->workflowStateService->forceClose($mrf, $user)) {
                    throw ValidationException::withMessages([
                        'workflow' => ['Unable to force-close purchase order'],
                    ]);
                }

                $mrf->forceFill([
                    'force_closed_at' => now(),
                    'force_closed_by' => $user->id,
                    'force_close_reason' => $reason,
                    'force_close_previous_status' => $previousStatus,
                    'force_close_previous_workflow_state' => $previousWorkflow,
                ])->save();

                // Same MRF row backs the PO — status/workflow already synced to completed/closed.
                MRFApprovalHistory::record(
                    $mrf,
                    'force_closed',
                    'finance_ap_bypass',
                    $user,
                    $reason
                );

                app(ScmAuditService::class)->record(
                    'force_close',
                    'MRF',
                    $mrf->mrf_id,
                    $user,
                    'Force Close — Finance AP Status Not Updated',
                    [
                        'previous_status' => $previousStatus,
                        'previous_workflow_state' => $previousWorkflow,
                        'new_status' => $mrf->fresh()->status,
                        'new_workflow_state' => WorkflowStateService::STATE_CLOSED,
                        'reason' => $reason,
                        'po_number' => $mrf->po_number,
                    ],
                    $request
                );
            });
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to force-close purchase order',
                'code' => 'TRANSITION_FAILED',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Force close failed', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Force close failed: '.$e->getMessage(),
                'code' => 'FORCE_CLOSE_FAILED',
            ], 500);
        }

        $mrf->refresh();

        $waybillDoc = null;
        $waybillError = null;
        if ($waybillFile instanceof UploadedFile) {
            try {
                $waybillDoc = app(ProcurementDocumentService::class)->storeUpload(
                    $mrf,
                    $waybillFile,
                    ProcurementDocument::TYPE_WAYBILL,
                    $user
                );
            } catch (\Throwable $e) {
                $waybillError = $e->getMessage();
                Log::warning('Waybill upload after force close failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $waybillError,
                ]);
            }
        }

        if (! $mrf->vendor_fulfilment_recorded_at && ! $mrf->grn_completed) {
            try {
                app(VendorFulfilmentService::class)->recordCycleCompleted($mrf, false);
            } catch (\Throwable $e) {
                Log::warning('Vendor fulfilment update on force close failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif (! $mrf->vendor_fulfilment_recorded_at && $mrf->grn_completed) {
            $mrf->forceFill(['vendor_fulfilment_recorded_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'message' => $waybillError
                ? 'Purchase order and associated MRF force-closed, but waybill upload failed'
                : 'Purchase order and associated MRF force-closed (Finance AP status not updated)',
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'poNumber' => $mrf->po_number,
                'workflowState' => $mrf->workflow_state,
                'status' => $mrf->status,
                'forceClosedAt' => optional($mrf->force_closed_at)?->toIso8601String(),
                'forceCloseReason' => $mrf->force_close_reason,
                'previousStatus' => $previousStatus,
                'previousWorkflowState' => $previousWorkflow,
                'waybillDocumentId' => $waybillDoc?->id,
                'waybillError' => $waybillError,
                'invalidate' => ['pos', 'mrfs', 'mrf_po', 'warehouse.inventory'],
            ],
        ]);
    }

    /**
     * Eligible when payment/completion is effectively done but normal can_close may still be blocked
     * (e.g. Finance AP never flipped case_closed / operational docs stuck).
     */
    private function isForceCloseEligible(MRF $mrf): bool
    {
        $readiness = $this->closureReadiness->evaluate($mrf);

        if ($readiness['financially_complete'] ?? false) {
            return true;
        }

        $status = strtolower(trim((string) ($mrf->status ?? '')));
        if (in_array($status, ['paid', 'completed', 'finance'], true)) {
            return true;
        }

        $state = (string) ($mrf->workflow_state ?? '');
        if (in_array($state, [
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_PAYMENT_PROCESSED,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
        ], true)) {
            // Allow finance-stage force close when at least one milestone is paid/complete.
            $schedule = app(\App\Services\PaymentScheduleService::class)->findForMrf($mrf);
            if ($schedule) {
                $schedule->loadMissing('milestones');
                $anyPaid = $schedule->milestones->contains(function ($m) {
                    return in_array($m->status, [
                        \App\Models\PaymentMilestone::STATUS_PAID,
                        \App\Models\PaymentMilestone::STATUS_COMPLETE,
                    ], true);
                });
                if ($anyPaid) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findMrfByPoReference(string $id): ?MRF
    {
        return MRF::query()
            ->where(function ($query) use ($id) {
                $query->where('formatted_id', $id)
                    ->orWhere('mrf_id', $id)
                    ->orWhere('po_number', $id)
                    ->orWhere('linked_po_id', $id);

                if (is_numeric($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->first();
    }

    private function attachDocumentsToMrf(Request $request, MRF $mrf): void
    {
        $documentService = app(ProcurementDocumentService::class);
        $user = $request->user();
        $vendorId = $documentService->resolveVendorId($mrf);
        $documents = $request->input('documents', []);
        $files = $request->file('documents', []);

        if (! is_array($documents) || ! is_array($files)) {
            return;
        }

        foreach ($documents as $index => $docMeta) {
            if (! isset($files[$index]['file'])) {
                continue;
            }

            $file = $files[$index]['file'];
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $type = (string) ($docMeta['type'] ?? ProcurementDocument::TYPE_OTHER);
            if (! in_array($type, [
                ProcurementDocument::TYPE_GRN,
                ProcurementDocument::TYPE_WAYBILL,
                ProcurementDocument::TYPE_JCC,
                ProcurementDocument::TYPE_PFI,
                ProcurementDocument::TYPE_DELIVERY_CONFIRMATION,
                ProcurementDocument::TYPE_OTHER,
            ], true)) {
                $type = ProcurementDocument::TYPE_OTHER;
            }

            try {
                $documentService->storeUpload(
                    $mrf,
                    $file,
                    $type,
                    $user,
                    $vendorId,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to attach document during PO creation', [
                    'mrf_id' => $mrf->mrf_id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }


    private function ensurePoAccess(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $allowed = ['procurement_manager', 'procurement', 'supply_chain_director', 'supply_chain', 'finance', 'finance_officer', 'admin'];

        if (! $user || ! in_array($user->scmRole(), $allowed, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return null;
    }
}
