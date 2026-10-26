<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingEngineService;
use App\Support\ScmReportViewerRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingEngineController extends Controller
{
    public function __construct(private ReportingEngineService $engine)
    {
    }

    public function procurementRecords(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => $this->engine->procurementRecords($request),
        ]);
    }

    public function procurementRecordDetail(Request $request, int $id): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => $this->engine->procurementRecordDetail($id),
        ]);
    }

    public function poRegister(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }
        $this->validatePoRegisterRequest($request);

        return response()->json([
            'success' => true,
            'data' => $this->engine->poRegister($request),
        ]);
    }

    public function exportProcurementRecords(Request $request): StreamedResponse|JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid export format. Use csv, xlsx, or pdf.',
            ], 422);
        }

        return $this->engine->exportProcurementRecords($request, $format);
    }

    public function exportPoRegister(Request $request): StreamedResponse|JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }
        $this->validatePoRegisterRequest($request);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid export format. Use xlsx or pdf.',
            ], 422);
        }

        return $this->engine->exportPoRegister($request, $format);
    }

    private function authorizeReport(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! ScmReportViewerRoles::allows($user->scmRole())) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return null;
    }

    private function validatePoRegisterRequest(Request $request): void
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'date_basis' => ['sometimes', 'in:created_at,po_generated_at,po_signed_at'],
            'po_status' => ['sometimes', 'in:generated,signed,unsigned,completed'],
            'signing_status' => ['sometimes', 'in:signed,unsigned'],
            'grn_status' => ['sometimes', 'in:completed,pending'],
            'payment_status' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'vendor' => ['sometimes', 'string', 'max:255'],
            'vendor_id' => ['sometimes', 'integer', 'min:1'],
            'contract_type' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'procurement_manager' => ['sometimes', 'string', 'max:255'],
            'procurement_manager_id' => ['sometimes', 'integer', 'min:1'],
            'po_value_min' => ['sometimes', 'numeric', 'min:0'],
            'po_value_max' => ['sometimes', 'numeric', 'min:0', 'gte:po_value_min'],
            'po_number' => ['sometimes', 'string', 'max:255'],
            'mrf_number' => ['sometimes', 'string', 'max:255'],
            'search' => ['sometimes', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'sort_by' => ['sometimes', 'in:po_generated_at,po_signed_at,estimated_cost,po_number,mrf_id,department,contract_type,created_at'],
            'sort_direction' => ['sometimes', 'in:asc,desc'],
            'limit' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
    }
}
