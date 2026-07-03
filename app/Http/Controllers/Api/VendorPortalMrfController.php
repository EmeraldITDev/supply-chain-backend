<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinanceAp\VendorInvoiceGateService;
use App\Services\FinanceAp\VendorInvoiceSubmissionService;
use App\Services\NotificationService;
use App\Services\ProcurementDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VendorPortalMrfController extends Controller
{
    use ResolvesPaginatedLists;

    public function __construct(
        private VendorInvoiceSubmissionService $submissionService,
        private VendorInvoiceGateService $gateService,
        private ProcurementDocumentService $documentService,
        private NotificationService $notificationService,
    ) {
    }

    public function index(Request $request)
    {
        $vendor = $this->resolvePortalVendor($request);

        if ($vendor instanceof \Illuminate\Http\JsonResponse) {
            return $vendor;
        }

        $perPage = $this->resolvePerPage($request, 25, 50);

        $paginator = MRF::query()
            ->select([
                'id',
                'mrf_id',
                'formatted_id',
                'title',
                'workflow_state',
                'po_number',
                'contract_type',
                'finance_ap_case_id',
                'updated_at',
            ])
            ->where('selected_vendor_id', $vendor->id)
            ->whereNotNull('workflow_state')
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $mrfIds = collect($paginator->items())->pluck('id')->all();
        $submittedMrfIds = $mrfIds === []
            ? []
            : ProcurementDocument::query()
                ->whereIn('mrf_id', $mrfIds)
                ->where('type', ProcurementDocument::TYPE_VENDOR_INVOICE)
                ->where('is_active', true)
                ->where('vendor_id', $vendor->id)
                ->pluck('mrf_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();

        $items = collect($paginator->items())
            ->map(function (MRF $mrf) use ($vendor, $submittedMrfIds) {
                $gate = $this->gateService->status($mrf);
                $submitted = isset($submittedMrfIds[(int) $mrf->id]);

                return [
                    'mrfId' => $mrf->mrf_id,
                    'formattedId' => $mrf->formatted_id,
                    'title' => $mrf->title,
                    'workflowState' => $mrf->workflow_state,
                    'poNumber' => $mrf->po_number,
                    'usesFinanceAp' => mrfUsesFinanceAp($mrf),
                    'vendorInvoiceGate' => [
                        'canSubmit' => $gate['canSubmit'] && ! $submitted,
                        'reason' => $submitted
                            ? 'Your invoice has already been submitted for this MRF.'
                            : $gate['reason'],
                        'gateType' => $gate['gateType'],
                    ],
                    'invoiceSubmitted' => $submitted,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'mrfs' => $items,
                'pagination' => $this->paginationPayload($paginator),
            ],
        ]);
    }

    public function showInvoiceStatus(Request $request, string $mrfId)
    {
        $vendor = $this->resolvePortalVendor($request);

        if ($vendor instanceof \Illuminate\Http\JsonResponse) {
            return $vendor;
        }

        $mrf = $this->findMrf($mrfId);

        if (! $mrf) {
            return $this->errorResponse('MRF not found', 'NOT_FOUND', 404);
        }

        try {
            $status = $this->submissionService->statusForVendor($mrf, $vendor);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 'FORBIDDEN', 403);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'mrfId' => $mrf->mrf_id,
                'formattedId' => $mrf->formatted_id,
                'title' => $mrf->title,
                'workflowState' => $mrf->workflow_state,
                'usesFinanceAp' => mrfUsesFinanceAp($mrf),
            ], $status),
        ]);
    }

    public function submitInvoice(Request $request, string $mrfId)
    {
        $vendor = $this->resolvePortalVendor($request);

        if ($vendor instanceof \Illuminate\Http\JsonResponse) {
            return $vendor;
        }

        $mrf = $this->findMrf($mrfId);

        if (! $mrf) {
            return $this->errorResponse('MRF not found', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'invoice' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
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
            $document = $this->submissionService->submit(
                $mrf,
                $vendor,
                $request->user(),
                $request->file('invoice'),
            );

            $this->notificationService->notifyVendorInvoiceSubmitted($mrf->fresh(), $document);

            return response()->json([
                'success' => true,
                'message' => 'Vendor invoice submitted successfully.',
                'data' => [
                    'mrfId' => $mrf->mrf_id,
                    'document' => $this->documentService->transform($document),
                    'submitted' => true,
                    'canSubmit' => false,
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $code = str_contains(strtolower($message), 'already exists')
                ? 'INVOICE_ALREADY_SUBMITTED'
                : (str_contains(strtolower($message), 'not open') || str_contains(strtolower($message), 'waiting')
                    ? 'INVOICE_GATE_CLOSED'
                    : 'SUBMISSION_FAILED');

            $status = in_array($code, ['INVOICE_ALREADY_SUBMITTED', 'INVOICE_GATE_CLOSED'], true) ? 422 : 403;

            return $this->errorResponse($message, $code, $status);
        } catch (\Exception $e) {
            Log::error('Vendor invoice upload failed', [
                'mrf_id' => $mrfId,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Invoice upload failed. Please try again.', 'UPLOAD_FAILED', 500);
        }
    }

    private function resolvePortalVendor(Request $request): Vendor|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (! $this->vendorUserActsAsVendor($user)) {
            return $this->errorResponse('Only vendors can access this endpoint', 'FORBIDDEN', 403);
        }

        $vendor = Vendor::forPortalUser($user);

        if (! $vendor) {
            return $this->errorResponse(
                'Vendor profile not found. Please ensure your account is linked to a vendor.',
                'NOT_FOUND',
                404
            );
        }

        return $vendor;
    }

    private function vendorUserActsAsVendor(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->scmRole() === 'vendor') {
            return true;
        }

        return $user->hasRole('vendor');
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

    private function errorResponse(string $message, string $code, int $status)
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], $status);
    }
}
