<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\Finance\FinanceIntegrationService;
use Illuminate\Http\Request;

class FinanceApIntegrationDocumentController extends Controller
{
    public function __construct(
        private FinanceIntegrationService $financeIntegration,
    ) {
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
