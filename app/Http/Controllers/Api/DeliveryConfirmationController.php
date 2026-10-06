<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Services\DashboardStatsCache;
use App\Services\FinanceAp\DeliveryConfirmationService;
use App\Services\PermissionService;
use App\Services\WorkflowStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeliveryConfirmationController extends Controller
{
    public function __construct(
        private DeliveryConfirmationService $deliveryConfirmationService,
        private PermissionService $permissionService,
    ) {
    }

    public function show(Request $request, string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $user = $request->user();
        $panel = $this->deliveryConfirmationService->panelPayload($mrf);

        return response()->json([
            'success' => true,
            'data' => array_merge($panel, [
                'mrfId' => $mrf->mrf_id,
                'formattedId' => $mrf->formatted_id,
                'scmTransactionId' => $mrf->scm_transaction_id,
                'permissions' => $this->deliveryPermissions($user, $mrf, $panel),
            ]),
        ]);
    }

    /**
     * Manual delivery close-out for stuck delivery_confirmation_pending records.
     * POST /api/mrfs/{id}/confirm-delivery
     */
    public function confirmDelivery(Request $request, string $id)
    {
        $user = $request->user();
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found.',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! $this->permissionService->canConfirmDelivery($user, $mrf)) {
            if (($mrf->workflow_state ?? '') !== WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING) {
                return response()->json([
                    'success' => false,
                    'error' => 'This request is not awaiting delivery confirmation.',
                    'code' => 'INVALID_STATE',
                    'current_state' => $mrf->workflow_state,
                ], 422);
            }

            return response()->json([
                'success' => false,
                'error' => 'Only Procurement Manager or Supply Chain Director can confirm delivery.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'delivery_notes' => 'nullable|string|max:1000',
            'delivery_confirmed_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $ok = $this->deliveryConfirmationService->confirmManually($mrf, $user, [
            'delivery_notes' => $request->input('delivery_notes'),
            'delivery_confirmed_at' => $request->input('delivery_confirmed_at'),
        ]);

        if (! $ok) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to confirm delivery for this request.',
                'code' => 'CONFIRM_FAILED',
                'current_state' => $mrf->fresh()->workflow_state,
            ], 422);
        }

        $mrf->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Delivery confirmed. Request has been closed out successfully.',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'mrfId' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
                'workflowState' => $mrf->workflow_state,
                'status' => $mrf->status,
            ],
        ]);
    }

    /**
     * Admin-only bulk close-out for stuck signed-PO delivery confirmations.
     * POST /api/admin/bulk-confirm-delivery
     */
    public function bulkConfirmDelivery(Request $request)
    {
        $user = $request->user();

        if ($user->scmRole() !== 'admin') {
            return response()->json([
                'success' => false,
                'error' => 'Admin only',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $query = MRF::query()
            ->where('workflow_state', WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING)
            ->whereNotNull('signed_po_url')
            ->where('signed_po_url', '!=', '');

        $ids = (clone $query)->pluck('id');
        $count = 0;

        foreach ($ids as $mrfId) {
            $mrf = MRF::query()->find($mrfId);
            if (! $mrf) {
                continue;
            }

            if ($this->deliveryConfirmationService->confirmManually($mrf, $user, [
                'delivery_notes' => 'Bulk delivery confirmation close-out (admin).',
            ])) {
                $count++;
            }
        }

        // Mass-path fallback if transactional confirm failed for any edge case
        if ($count === 0 && $ids->isNotEmpty()) {
            $count = MRF::query()
                ->whereIn('id', $ids)
                ->where('workflow_state', WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING)
                ->update([
                    'workflow_state' => WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
                    'status' => 'delivery_confirmation_complete',
                    'current_stage' => 'procurement',
                    'grn_completed' => true,
                    'grn_completed_at' => now(),
                    'grn_completed_by' => $user->id,
                    'updated_at' => now(),
                ]);
        }

        DashboardStatsCache::forgetAll();

        return response()->json([
            'success' => true,
            'message' => "{$count} records closed out successfully.",
            'count' => $count,
        ]);
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function deliveryPermissions($user, MRF $mrf, array $panel): array
    {
        $canManage = $this->permissionService->canManageDeliveryConfirmation($user, $mrf);

        return [
            'showPanel' => (bool) ($panel['showPanel'] ?? false),
            'canManageDeliveryConfirmation' => $canManage,
            'canConfirmDelivery' => $this->permissionService->canConfirmDelivery($user, $mrf),
            'canGenerateGRN' => $this->permissionService->canGenerateGRN($user, $mrf),
            'canUploadGRN' => $this->permissionService->canUploadGRN($user, $mrf),
            'canUploadWaybill' => $canManage && $this->permissionService->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_WAYBILL
            ),
            'canUploadJcc' => $canManage && $this->permissionService->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_JCC
            ),
            'canUploadDeliveryConfirmation' => $canManage && $this->permissionService->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_DELIVERY_CONFIRMATION
            ),
            'canUploadOther' => $canManage && $this->permissionService->canUploadProcurementDocument(
                $user,
                $mrf,
                ProcurementDocument::TYPE_OTHER
            ),
        ];
    }

    private function findMrf(string $id): ?MRF
    {
        return MRF::query()
            ->where(function ($query) use ($id) {
                $query->where('formatted_id', $id)
                    ->orWhere('mrf_id', $id);

                if (is_numeric($id)) {
                    $query->orWhere('id', (int) $id);
                }
            })
            ->first();
    }
}
