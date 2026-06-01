<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Services\FinanceAp\DeliveryConfirmationService;
use App\Services\PermissionService;
use Illuminate\Http\Request;

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
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function deliveryPermissions($user, MRF $mrf, array $panel): array
    {
        $canManage = $this->permissionService->canManageDeliveryConfirmation($user, $mrf);

        return [
            'showPanel' => (bool) ($panel['showPanel'] ?? false),
            'canManageDeliveryConfirmation' => $canManage,
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
