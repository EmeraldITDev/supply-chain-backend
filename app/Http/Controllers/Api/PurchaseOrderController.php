<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\StorePurchaseOrderRequest;
use App\Http\Requests\Procurement\UpdatePurchaseOrderRequest;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Services\FinanceAp\ClosureReadinessService;
use App\Services\ProcurementDocumentService;
use App\Services\PurchaseOrderService;
use App\Services\WorkflowStateService;
use App\Support\RequestLineItemParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class PurchaseOrderController extends Controller
{
    use ResolvesPaginatedLists;

    public function __construct(
        private PurchaseOrderService $purchaseOrders,
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
     * PUT /api/pos/{id} — update draft PO fields.
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

        $updated = $this->purchaseOrders->updateDraft($mrf, $validated);

        if ($request->hasFile('documents')) {
            $this->attachDocumentsToMrf($request, $updated);
        }

        $fresh = $this->purchaseOrders->findForEdit($updated->mrf_id) ?? $updated;

        return response()->json([
            'success' => true,
            'message' => 'Purchase order updated',
            'data' => $this->purchaseOrders->mapEditPayload($fresh),
        ]);
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

    private function findMrfByPoReference(string $id): ?MRF
    {
        return MRF::query()
            ->where(function ($query) use ($id) {
                $query->where('formatted_id', $id)
                    ->orWhere('mrf_id', $id)
                    ->orWhere('po_number', $id);

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
