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
}
