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

        $user = $request->user();
        $documents = $grouped['documents']->filter(fn ($document) =>
            $this->permissionService->canViewDocument($user, $mrf, $document['type'] ?? 'other')
        )->values();

        $documentsByType = [];
        foreach ($grouped['documentsByType'] as $docType => $items) {
            $filtered = collect($items)->filter(fn ($document) =>
                $this->permissionService->canViewDocument($user, $mrf, $document['type'] ?? 'other')
            )->values();

            if ($filtered->isNotEmpty()) {
                $documentsByType[$docType] = $filtered;
            }
        }

        $activeByType = [];
        foreach ($grouped['activeByType'] as $docType => $active) {
            if ($active && $this->permissionService->canViewDocument($user, $mrf, $active['type'] ?? 'other')) {
                $activeByType[$docType] = $active;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'scmTransactionId' => $mrf->scm_transaction_id,
                'documents' => $documents,
                'documentsByType' => $documentsByType,
                'activeByType' => $activeByType,
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
            'type' => ['required_without:documents', 'string', Rule::in(self::UPLOADABLE_TYPES)],
            'file' => 'required_without:documents|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
            'remarks' => 'nullable|string|max:2000',
            'documents' => 'required_without:file|array|min:1',
            'documents.*.type' => ['required_with:documents', 'string', Rule::in(self::UPLOADABLE_TYPES)],
            'documents.*.file' => 'required_with:documents|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
            'documents.*.remarks' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $documents = [];
        $uploadedDocuments = $request->file('documents', []);

        if ($request->has('documents')) {
            foreach ($request->input('documents', []) as $index => $document) {
                $documents[] = [
                    'type' => $document['type'] ?? null,
                    'file' => $uploadedDocuments[$index]['file'] ?? null,
                    'remarks' => $document['remarks'] ?? null,
                ];
            }
        } else {
            $documents[] = [
                'type' => $request->input('type'),
                'file' => $request->file('file'),
                'remarks' => $request->input('remarks'),
            ];
        }

        $user = $request->user();
        $vendorId = $this->documentService->resolveVendorId($mrf);
        $successful = [];
        $failed = [];

        foreach ($documents as $index => $documentPayload) {
            $type = (string) ($documentPayload['type'] ?? '');
            $file = $documentPayload['file'];
            $remarks = $documentPayload['remarks'] ?? null;
            $result = [
                'index' => $index,
                'type' => $type,
                'fileName' => $file instanceof \Illuminate\Http\UploadedFile ? $file->getClientOriginalName() : null,
            ];

            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                $failed[] = array_merge($result, [
                    'status' => 'failed',
                    'error' => 'File is missing or invalid',
                ]);
                continue;
            }

            if (! $this->permissionService->canUploadProcurementDocument($user, $mrf, $type)) {
                $failed[] = array_merge($result, [
                    'status' => 'failed',
                    'error' => 'You do not have permission to upload this document type at the current workflow stage.',
                ]);
                continue;
            }

            try {
                $document = $this->documentService->storeUpload(
                    $mrf,
                    $file,
                    $type,
                    $user,
                    $vendorId,
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

                $successful[] = $this->documentService->transform($document);
            } catch (\RuntimeException $e) {
                $failed[] = array_merge($result, [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                Log::error('Procurement document upload failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);

                $failed[] = array_merge($result, [
                    'status' => 'failed',
                    'error' => 'Failed to upload document',
                ]);
            }
        }

        $responseData = [
            'success' => count($successful) > 0,
            'message' => count($failed) === 0 ? 'Document uploaded successfully' : 'Some documents uploaded successfully',
            'data' => [
                'documents' => $successful,
                'failed' => $failed,
            ],
        ];

        if (count($failed) > 0 && count($successful) === 0) {
            return response()->json(array_merge($responseData, [
                'error' => 'All document uploads failed',
                'code' => 'UPLOAD_FAILED',
            ]), 422);
        }

        return response()->json($responseData, count($documents) === 1 && count($failed) === 0 ? 201 : 200);
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
