<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\FinanceAp\ClosureReadinessService;
use App\Services\FinanceAp\DeliveryConfirmationService;
use App\Services\FinanceAp\VendorInvoiceGateService;
use Illuminate\Http\Request;

class WorkflowGateController extends Controller
{
    public function __construct(
        private VendorInvoiceGateService $vendorInvoiceGate,
        private DeliveryConfirmationService $deliveryConfirmation,
        private ClosureReadinessService $closureReadiness,
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

        $vendorInvoiceGate = $this->vendorInvoiceGate->status($mrf);
        $deliveryConfirmation = $this->deliveryConfirmation->evaluate($mrf);
        $closureReadiness = $this->closureReadiness->evaluate($mrf);

        return response()->json([
            'success' => true,
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'scmTransactionId' => $mrf->scm_transaction_id,
                'usesFinanceAp' => mrfUsesFinanceAp($mrf),
                'workflowState' => $mrf->workflow_state,
                'vendorInvoiceGate' => [
                    'canSubmit' => $vendorInvoiceGate['canSubmit'],
                    'reason' => $vendorInvoiceGate['reason'],
                    'gateType' => $vendorInvoiceGate['gateType'],
                ],
                'deliveryConfirmation' => [
                    'required' => $deliveryConfirmation['required'],
                    'satisfied' => $deliveryConfirmation['satisfied'],
                    'currentMilestone' => $deliveryConfirmation['currentMilestone'],
                    'requiredDocuments' => $deliveryConfirmation['requiredDocuments'],
                    'missingDocuments' => $deliveryConfirmation['missingDocuments'],
                    'uploadedDocuments' => $deliveryConfirmation['uploadedDocuments'],
                ],
                'closureReadiness' => [
                    'financiallyComplete' => $closureReadiness['financially_complete'],
                    'operationallyComplete' => $closureReadiness['operationally_complete'],
                    'canClose' => $closureReadiness['can_close'],
                    'blockers' => $closureReadiness['blockers'],
                    'milestoneSummary' => $closureReadiness['milestoneSummary'],
                ],
            ],
        ]);
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
