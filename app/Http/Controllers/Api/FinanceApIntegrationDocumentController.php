<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\Finance\FinanceIntegrationService;
use App\Services\Finance\FinancePackageBuilder;
use Illuminate\Http\Request;

class FinanceApIntegrationDocumentController extends Controller
{
    public function __construct(
        private FinanceIntegrationService $financeIntegration,
    ) {
    }

    /**
     * GET /api/v1/integrations/scm/documents/{scm_transaction_id}
     *
     * Lists the MRF's documents whatever its workflow state, so Finance AP can
     * offer them on any PO it holds (closed MRFs included).
     */
    public function index(string $scmTransactionId, FinancePackageBuilder $packageBuilder)
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
                'documents' => $packageBuilder->buildDocumentList($mrf),
            ],
        ]);
    }

    public function show(Request $request, string $scmTransactionId, string $documentId)
    {
        $mrf = MRF::query()->where('scm_transaction_id', $scmTransactionId)->first();

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found for scm_transaction_id',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! is_numeric($documentId)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid document_id',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $manifest = $this->financeIntegration->refreshDocument($mrf, (int) $documentId);

        if (! $manifest) {
            return response()->json([
                'success' => false,
                'error' => 'Document not found or inactive',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $manifest,
        ]);
    }
}
