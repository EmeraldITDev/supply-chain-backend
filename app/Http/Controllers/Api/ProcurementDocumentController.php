<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Services\FinanceAp\FinanceApWorkflowOrchestrator;
use App\Services\PermissionService;
use App\Services\ProcurementDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProcurementDocumentController extends Controller
{
    private const UPLOADABLE_TYPES = [
        ProcurementDocument::TYPE_GRN,
        ProcurementDocument::TYPE_WAYBILL,
        ProcurementDocument::TYPE_JCC,
        ProcurementDocument::TYPE_PFI,
        ProcurementDocument::TYPE_DELIVERY_CONFIRMATION,
        ProcurementDocument::TYPE_OTHER,
    ];

    public function __construct(
        private ProcurementDocumentService $documentService,
        private PermissionService $permissionService,
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
        if (is_string($type) && $type === ProcurementDocument::TYPE_GRN) {
            $activeOnly = false;
        }
        $grouped = $this->documentService->listGroupedForMrf(
            $mrf,
            is_string($type) ? $type : null,
            $activeOnly,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'scmTransactionId' => $mrf->scm_transaction_id,
                'documents' => $grouped['documents']->values(),
                'documentsByType' => $grouped['documentsByType'],
                'activeByType' => $grouped['activeByType'],
            ],
        ]);
    }

    public function store(Request $request, string $id)
    {
        $mrf = $this->findMrf($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in(self::UPLOADABLE_TYPES)],
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
            'remarks' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $type = (string) $request->input('type');
        $user = $request->user();

        if (! $this->permissionService->canUploadProcurementDocument($user, $mrf, $type)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to upload this document type at the current workflow stage.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        try {
            $document = $this->documentService->storeUpload(
                $mrf,
                $request->file('file'),
                $type,
                $user,
                $this->documentService->resolveVendorId($mrf),
            );

            if ($type === ProcurementDocument::TYPE_GRN) {
                $this->documentService->syncGrnLegacyFields($mrf, $document);
            }

            if (in_array($type, [
                ProcurementDocument::TYPE_GRN,
                ProcurementDocument::TYPE_WAYBILL,
                ProcurementDocument::TYPE_JCC,
                ProcurementDocument::TYPE_DELIVERY_CONFIRMATION,
            ], true)) {
                app(FinanceApWorkflowOrchestrator::class)->afterOperationalDocumentChanged($mrf, $user);
            }
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'UPLOAD_FAILED',
            ], 422);
        } catch (\Exception $e) {
            Log::error('Procurement document upload failed', [
                'mrf_id' => $mrf->mrf_id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to upload document',
                'code' => 'UPLOAD_FAILED',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded successfully',
            'data' => $this->documentService->transform($document),
        ], 201);
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
