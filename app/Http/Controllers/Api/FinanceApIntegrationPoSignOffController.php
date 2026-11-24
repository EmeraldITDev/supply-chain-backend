<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\Finance\FinancePackageBuilder;

/**
 * Read-only SCM Director PO sign-off status for Finance AP.
 * Does not mutate workflow, packages, or payment obligations.
 */
class FinanceApIntegrationPoSignOffController extends Controller
{
    public function __construct(
        private FinancePackageBuilder $packageBuilder,
    ) {
    }

    /**
     * GET /api/v1/integrations/scm/transactions/{scm_transaction_id}/po-sign-off
     */
    public function show(string $scmTransactionId)
    {
        $mrf = MRF::query()->where('scm_transaction_id', $scmTransactionId)->first();

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found for scm_transaction_id',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'scm_transaction_id' => $scmTransactionId,
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $mrf->po_number,
                'poSignOff' => $this->packageBuilder->buildPoSignOffSummary($mrf),
            ],
        ]);
    }
}
