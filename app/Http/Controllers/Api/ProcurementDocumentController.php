<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\ProcurementDocumentService;
use Illuminate\Http\Request;

class ProcurementDocumentController extends Controller
{
    public function __construct(
        private ProcurementDocumentService $documentService,
    ) {
    }

    public function index(Request $request, string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $type = $request->query('type');
        $activeOnly = ! $request->boolean('include_inactive', false);

        $documents = $this->documentService
            ->listForMrf($mrf, is_string($type) ? $type : null, $activeOnly)
            ->map(fn ($doc) => $this->documentService->transform($doc))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'scmTransactionId' => $mrf->scm_transaction_id,
                'documents' => $documents,
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
