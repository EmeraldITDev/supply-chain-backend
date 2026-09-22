<?php

namespace App\Services;

use App\Http\Controllers\Api\MRFController;
use App\Http\Controllers\Api\MRFWorkflowController;
use App\Models\MRF;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MrfBulkActionService
{
    public function __construct(
        private PermissionService $permissionService,
    ) {
    }

    /**
     * @param  list<string|int>  $ids
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function bulkApprove(User $user, array $ids, ?string $remarks = null): array
    {
        return $this->runBulk($user, $ids, 'approve', $remarks);
    }

    /**
     * @param  list<string|int>  $ids
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function bulkReject(User $user, array $ids, ?string $remarks = null): array
    {
        return $this->runBulk($user, $ids, 'reject', $remarks);
    }

    /**
     * @param  list<string|int>  $ids
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function bulkDelete(User $user, array $ids): array
    {
        $succeeded = [];
        $failed = [];
        $legacy = app(MRFController::class);

        foreach (array_values(array_unique($ids)) as $id) {
            $mrf = $this->findMrf((string) $id);
            if (! $mrf) {
                $failed[] = [
                    'id' => (string) $id,
                    'error' => 'MRF not found',
                    'code' => 'NOT_FOUND',
                ];
                continue;
            }

            try {
                $request = $this->makeRequest($user, []);
                $response = $legacy->destroy($request, $mrf->mrf_id);
                $status = $response->getStatusCode();
                $payload = json_decode($response->getContent(), true) ?: [];

                if ($status >= 200 && $status < 300 && ($payload['success'] ?? false)) {
                    $succeeded[] = [
                        'id' => $mrf->mrf_id,
                        'message' => $payload['message'] ?? 'Deleted',
                    ];
                } else {
                    $failed[] = [
                        'id' => $mrf->mrf_id,
                        'error' => $payload['error'] ?? $payload['message'] ?? 'Delete failed',
                        'code' => $payload['code'] ?? 'DELETE_FAILED',
                        'httpStatus' => $status,
                    ];
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                    'code' => 'SERVER_ERROR',
                ];
            }
        }

        return compact('succeeded', 'failed');
    }

    /**
     * @param  list<string|int>  $ids
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    private function runBulk(User $user, array $ids, string $action, ?string $remarks): array
    {
        $succeeded = [];
        $failed = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $mrf = $this->findMrf((string) $id);
            if (! $mrf) {
                $failed[] = [
                    'id' => (string) $id,
                    'error' => 'MRF not found',
                    'code' => 'NOT_FOUND',
                ];
                continue;
            }

            if (! $this->permissionService->canApproveMRF($user, $mrf)) {
                $failed[] = [
                    'id' => $mrf->mrf_id,
                    'error' => 'Insufficient permissions or MRF not awaiting your approval',
                    'code' => 'FORBIDDEN',
                    'workflowState' => $mrf->workflow_state,
                ];
                continue;
            }

            try {
                $response = $this->dispatchStageAction($user, $mrf, $action, $remarks);
                $status = $response->getStatusCode();
                $payload = json_decode($response->getContent(), true) ?: [];

                if ($status >= 200 && $status < 300 && ($payload['success'] ?? false)) {
                    $succeeded[] = [
                        'id' => $mrf->mrf_id,
                        'workflowState' => $payload['data']['workflow_state']
                            ?? $payload['data']['workflowState']
                            ?? $mrf->fresh()?->workflow_state,
                        'message' => $payload['message'] ?? 'OK',
                    ];
                } else {
                    $failed[] = [
                        'id' => $mrf->mrf_id,
                        'error' => $payload['error'] ?? $payload['message'] ?? 'Action failed',
                        'code' => $payload['code'] ?? 'ACTION_FAILED',
                        'workflowState' => $mrf->workflow_state,
                        'httpStatus' => $status,
                    ];
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                    'code' => 'SERVER_ERROR',
                ];
            }
        }

        return compact('succeeded', 'failed');
    }

    private function dispatchStageAction(User $user, MRF $mrf, string $action, ?string $remarks): Response
    {
        $state = (string) ($mrf->workflow_state ?? '');
        $workflow = app(MRFWorkflowController::class);
        $legacy = app(MRFController::class);

        $payload = array_filter([
            'action' => $action,
            'remarks' => $remarks,
            'comments' => $remarks,
            'reason' => $remarks,
        ], fn ($v) => $v !== null && $v !== '');

        if (in_array($state, ['parallel_first_approval', 'supply_chain_director_review', 'pending'], true)
            && in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            if ($action === 'reject') {
                $request = $this->makeRequest($user, $payload);

                return $legacy->supplyChainDirectorReject($request, $mrf->mrf_id);
            }
            $request = $this->makeRequest($user, $payload);

            return $workflow->supplyChainDirectorApprove($request, $mrf->mrf_id);
        }

        if (in_array($state, ['executive_review', 'parallel_first_approval', 'pending'], true)
            && in_array($user->scmRole(), ['executive', 'admin'], true)) {
            if ($action === 'reject') {
                $request = $this->makeRequest($user, $payload);

                return $legacy->executiveReject($request, $mrf->mrf_id);
            }
            $request = $this->makeRequest($user, $payload);

            return $workflow->executiveApprove($request, $mrf->mrf_id);
        }

        if (in_array($state, ['chairman_review'], true)
            && in_array($user->scmRole(), ['chairman', 'admin'], true)) {
            if ($action === 'reject') {
                $request = $this->makeRequest($user, $payload);

                return $workflow->rejectMRF($request, $mrf->mrf_id);
            }
            $request = $this->makeRequest($user, $payload);

            return $workflow->chairmanApprove($request, $mrf->mrf_id);
        }

        if (in_array($state, [
            'executive_approved',
            'procurement_review',
            'supply_chain_director_approved',
            'lazarus_director_approval',
            'pending',
        ], true) && in_array($user->scmRole(), ['procurement_manager', 'procurement', 'admin'], true)) {
            $request = $this->makeRequest($user, array_merge($payload, ['action' => $action]));

            return $workflow->procurementApprove($request, $mrf->mrf_id);
        }

        if ($action === 'reject' && in_array($user->scmRole(), ['executive', 'chairman', 'admin'], true)) {
            $request = $this->makeRequest($user, $payload);

            return $workflow->rejectMRF($request, $mrf->mrf_id);
        }

        // Fallback: legacy approve/reject if still Pending
        if (strcasecmp((string) $mrf->status, 'Pending') === 0) {
            $request = $this->makeRequest($user, $payload);
            if ($action === 'approve') {
                return $legacy->approve($request, $mrf->mrf_id);
            }

            return $legacy->reject($request, $mrf->mrf_id);
        }

        return response()->json([
            'success' => false,
            'error' => 'No matching approval endpoint for current workflow state',
            'code' => 'INVALID_WORKFLOW_STATE',
            'workflowState' => $state,
        ], 422);
    }

    private function makeRequest(User $user, array $payload): Request
    {
        $request = Request::create('/', 'POST', $payload);
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    private function findMrf(string $id): ?MRF
    {
        return MRF::query()
            ->where(function ($q) use ($id) {
                $q->where('mrf_id', $id)->orWhere('formatted_id', $id);
                if (is_numeric($id)) {
                    $q->orWhere('id', (int) $id);
                }
            })
            ->first();
    }

    /**
     * @param  list<string|int>  $ids
     * @return list<array<string, mixed>>
     */
    public function exportRows(array $ids): array
    {
        $mrfs = MRF::query()
            ->where(function ($q) use ($ids) {
                $q->whereIn('mrf_id', $ids)->orWhereIn('formatted_id', $ids);
                $numeric = array_values(array_filter($ids, fn ($id) => is_numeric((string) $id)));
                if ($numeric !== []) {
                    $q->orWhereIn('id', array_map('intval', $numeric));
                }
            })
            ->with(['requester:id,name,email,department', 'selectedVendor:id,vendor_id,name'])
            ->orderByDesc('updated_at')
            ->get();

        return $mrfs->map(function (MRF $mrf) {
            return [
                'mrfId' => $mrf->mrf_id,
                'formattedId' => $mrf->formatted_id,
                'title' => $mrf->title,
                'status' => $mrf->status,
                'workflowState' => $mrf->workflow_state,
                'currentStage' => $mrf->current_stage,
                'department' => $mrf->department,
                'requesterName' => $mrf->requester_name ?? $mrf->requester?->name,
                'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
                'currency' => $mrf->currency,
                'contractType' => $mrf->contract_type,
                'category' => $mrf->category,
                'poNumber' => $mrf->effectivePoNumber(),
                'selectedVendor' => $mrf->selectedVendor?->name,
                'createdAt' => optional($mrf->created_at)?->toIso8601String(),
                'updatedAt' => optional($mrf->updated_at)?->toIso8601String(),
            ];
        })->values()->all();
    }
}
