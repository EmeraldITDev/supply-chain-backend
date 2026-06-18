<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Services\GrnPdfService;
use App\Support\DocumentDisplayPayload;
use App\Services\FinanceAp\FinanceApWorkflowOrchestrator;
use App\Services\NotificationService;
use App\Services\PermissionService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GRNController extends Controller
{
    public function __construct(
        protected WorkflowStateService $workflowService,
        protected PermissionService $permissionService,
        protected NotificationService $notificationService,
        protected ProcurementDocumentService $documentService,
        protected GrnPdfService $grnPdfService,
    ) {
    }

    private function findMrfByAnyId(string $id): ?MRF
    {
        return MRF::with(['items', 'priceComparisons.vendor', 'selectedVendor', 'requester'])
            ->where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('mrf_id', $id);

            if (is_numeric($id)) {
                $query->orWhere('id', (int) $id);
            }
        })->first();
    }

    /**
     * Prefill GRN form data from MRF/PO line items.
     */
    public function prefillGrn(Request $request, string $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! $this->permissionService->canGenerateGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to prefill GRN for this MRF',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->grnPdfService->buildPrefillPayload($mrf, $user),
        ]);
    }

    /**
     * Preview GRN PDF populated from MRF line items (not saved to registry).
     */
    public function previewGrn(Request $request, string $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! $this->permissionService->canGenerateGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to preview GRN for this MRF',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $resolved = $this->grnPdfService->resolveLineItems(
            $mrf,
            is_array($request->input('line_items') ?? $request->input('lineItems'))
                ? ($request->input('line_items') ?? $request->input('lineItems'))
                : null,
        );
        if (! $resolved['success']) {
            return response()->json([
                'success' => false,
                'error' => $resolved['error'] ?? 'Unable to resolve GRN line items',
                'code' => 'ITEMS_MISSING',
            ], 422);
        }

        try {
            $options = $this->grnOptionsFromRequest($request);
            $pdf = $this->grnPdfService->renderPdf($mrf, $user, $options);
            $grnNumber = (string) ($options['grn_number'] ?? $this->grnPdfService->defaultGrnNumber($mrf));
            $fileName = $grnNumber.'_preview.pdf';

            if ($request->isMethod('post') || $request->boolean('json') || $request->query('format') === 'json' || $request->wantsJson()) {
                $metadata = $this->grnPdfService->buildPersistedMetadata($mrf, $user, $options);
                $document = $this->documentService->storePreviewBinary(
                    $mrf,
                    $pdf,
                    $fileName,
                    ProcurementDocument::TYPE_GRN,
                    $user,
                    $metadata,
                );

                return response()->json([
                    'success' => true,
                    'data' => [
                        'document' => DocumentDisplayPayload::withCamelCaseAliases($document),
                        'preview' => true,
                    ],
                ]);
            }

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            ]);
        } catch (\Throwable $e) {
            Log::error('GRN preview generation failed', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate GRN preview: '.$e->getMessage(),
                'code' => 'PDF_GENERATION_FAILED',
            ], 500);
        }
    }

    /**
     * Generate GRN PDF from line items and save to procurement document registry.
     */
    public function generateGrn(Request $request, string $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId($id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! $this->permissionService->canGenerateGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to generate GRN for this MRF',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string|max:2000',
            'comments' => 'nullable|string|max:2000',
            'grn_number' => 'nullable|string|max:100',
            'grnNumber' => 'nullable|string|max:100',
            'received_at' => 'nullable|date',
            'receivedAt' => 'nullable|date',
            'date_of_receipt' => 'nullable|date',
            'delivery_note_number' => 'nullable|string|max:100',
            'deliveryNoteNumber' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'deliveryDate' => 'nullable|date',
            'carrier_name' => 'nullable|string|max:255',
            'carrierName' => 'nullable|string|max:255',
            'driver_number' => 'nullable|string|max:100',
            'driverNumber' => 'nullable|string|max:100',
            'vehicle_plate_number' => 'nullable|string|max:50',
            'vehiclePlateNumber' => 'nullable|string|max:50',
            'line_items' => 'nullable|array',
            'line_items.*.index' => 'nullable|integer|min:0',
            'line_items.*.item' => 'nullable|integer|min:1',
            'line_items.*.quantity_received' => 'nullable|numeric|min:0',
            'line_items.*.quantityReceived' => 'nullable|numeric|min:0',
            'line_items.*.unit_price' => 'nullable|numeric|min:0',
            'line_items.*.unitPrice' => 'nullable|numeric|min:0',
            'confirm' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        if (! $request->boolean('confirm', true)) {
            return response()->json([
                'success' => false,
                'error' => 'Set confirm=true after reviewing the GRN preview to save the document.',
                'code' => 'CONFIRMATION_REQUIRED',
            ], 422);
        }

        try {
            $options = $this->grnOptionsFromRequest($request);
            $pdf = $this->grnPdfService->renderPdf($mrf, $user, $options);
            $grnNumber = (string) ($options['grn_number'] ?? $this->grnPdfService->defaultGrnNumber($mrf));
            $fileName = $grnNumber . '.pdf';
            $metadata = $this->grnPdfService->buildPersistedMetadata($mrf, $user, $options);

            $document = $this->documentService->storeBinaryContent(
                $mrf,
                $pdf,
                $fileName,
                ProcurementDocument::TYPE_GRN,
                $user,
                $this->documentService->resolveVendorId($mrf),
                'procurement-documents/' . date('Y/m') . '/' . $mrf->mrf_id . '/grn',
                $metadata,
            );

            $this->documentService->syncGrnLegacyFields($mrf, $document);
            $this->transitionAfterGrnSaved($mrf, $user);

            try {
                $this->notificationService->notifyGRNCompleted($mrf, $user);
            } catch (\Exception $e) {
                Log::warning('Failed to send GRN completion notification', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $freshMrf = $mrf->fresh();

            return response()->json([
                'success' => true,
                'message' => 'GRN generated and saved to document registry',
                'data' => [
                    'mrfId' => $freshMrf->mrf_id,
                    'grnNumber' => $grnNumber,
                    'grn_number' => $grnNumber,
                    'workflowState' => $freshMrf->workflow_state,
                    'mrf_grn_url' => $document->file_url,
                    'mrfGrnUrl' => $document->file_url,
                    'document' => $this->documentService->transform($document),
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('GRN generation failed', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate GRN: ' . $e->getMessage(),
                'code' => 'GRN_GENERATION_FAILED',
            ], 500);
        }
    }

    /**
     * Finance Officer requests GRN (legacy path — retained for in-flight MRFs).
     */
    public function requestGRN(Request $request, $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId((string) $id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! $this->permissionService->canRequestGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to request GRN',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $mrf->update([
            'grn_requested' => true,
            'grn_requested_at' => now(),
            'grn_requested_by' => $user->id,
        ]);

        if ($this->workflowService->canTransition(
            $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED,
            WorkflowStateService::STATE_GRN_REQUESTED
        )) {
            $this->workflowService->transition($mrf, WorkflowStateService::STATE_GRN_REQUESTED, $user);
        }

        try {
            $this->notificationService->notifyGRNRequested($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send GRN request notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'GRN requested successfully',
            'data' => [
                'mrf_id' => $mrf->mrf_id,
                'workflow_state' => $mrf->workflow_state,
                'grn_requested' => $mrf->grn_requested,
                'grn_requested_at' => $mrf->grn_requested_at,
            ],
        ]);
    }

    /**
     * Upload GRN file (legacy endpoint — writes to registry + legacy MRF fields).
     */
    public function completeGRN(Request $request, $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId((string) $id);

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (! $this->permissionService->canCompleteGRN($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to complete GRN',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'grn' => 'required|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        try {
            $document = $this->documentService->storeUpload(
                $mrf,
                $request->file('grn'),
                ProcurementDocument::TYPE_GRN,
                $user,
                $this->documentService->resolveVendorId($mrf),
                'procurement-documents/' . date('Y/m') . '/' . $mrf->mrf_id . '/grn',
            );

            $this->documentService->syncGrnLegacyFields($mrf, $document);
            $this->transitionAfterGrnSaved($mrf, $user);

            try {
                $this->notificationService->notifyGRNCompleted($mrf, $user);
            } catch (\Exception $e) {
                Log::error('Failed to send GRN completion notification', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'GRN completed successfully',
                'data' => [
                    'mrf_id' => $mrf->mrf_id,
                    'workflow_state' => $mrf->fresh()->workflow_state,
                    'grn_completed' => true,
                    'grn_url' => $document->file_url,
                    'grn_share_url' => $document->file_url,
                    'document' => $this->documentService->transform($document),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('GRN upload failed', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to upload GRN: ' . $e->getMessage(),
                'code' => 'UPLOAD_FAILED',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function grnOptionsFromRequest(Request $request): array
    {
        $options = [
            'remarks' => $request->input('remarks'),
            'comments' => $request->input('comments'),
            'grn_number' => $request->input('grn_number') ?? $request->input('grnNumber'),
            'received_at' => $request->input('received_at') ?? $request->input('receivedAt') ?? $request->input('date_of_receipt'),
            'date_of_receipt' => $request->input('date_of_receipt') ?? $request->input('received_at') ?? $request->input('receivedAt'),
            'delivery_note_number' => $request->input('delivery_note_number') ?? $request->input('deliveryNoteNumber'),
            'delivery_date' => $request->input('delivery_date') ?? $request->input('deliveryDate'),
            'carrier_name' => $request->input('carrier_name') ?? $request->input('carrierName') ?? $request->input('carrier_driver_name'),
            'driver_number' => $request->input('driver_number') ?? $request->input('driverNumber') ?? $request->input('carrier_number'),
            'vehicle_plate_number' => $request->input('vehicle_plate_number') ?? $request->input('vehiclePlateNumber'),
            'line_items' => $request->input('line_items') ?? $request->input('lineItems'),
        ];

        return array_filter($options, fn ($value) => $value !== null && $value !== '');
    }

    private function transitionAfterGrnSaved(MRF $mrf, $user): void
    {
        if (mrfUsesFinanceAp($mrf)) {
            app(FinanceApWorkflowOrchestrator::class)->afterOperationalDocumentChanged($mrf, $user);

            return;
        }

        $currentState = $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED;

        if ($currentState === WorkflowStateService::STATE_GRN_REQUESTED
            && $this->workflowService->canTransition($currentState, WorkflowStateService::STATE_GRN_COMPLETED)) {
            $this->workflowService->transition($mrf, WorkflowStateService::STATE_GRN_COMPLETED, $user);

            return;
        }

        if ($currentState === WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING
            && $this->workflowService->canTransition(
                $currentState,
                WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE
            )) {
            $this->workflowService->transition($mrf, WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE, $user);
        }
    }
}
