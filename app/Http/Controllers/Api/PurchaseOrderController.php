<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\FinanceAp\ClosureReadinessService;
use App\Services\WorkflowStateService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private ClosureReadinessService $closureReadiness,
        private WorkflowStateService $workflowStateService,
    ) {
    }

    /**
     * Close a PO-backed MRF when closure readiness passes.
     *
     * POST /api/pos/{id}/close
     */
    public function close(Request $request, string $id)
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
}
