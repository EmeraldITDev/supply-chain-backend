<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProcurementReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementReportController extends Controller
{
    public function __construct(private ProcurementReportService $reportService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $allowed = ['procurement_manager', 'procurement', 'supply_chain_director', 'supply_chain', 'admin', 'finance', 'finance_officer'];
        if (!$user || !in_array($user->role, $allowed, true)) {
            return response()->json(['success' => false, 'error' => 'Insufficient permissions', 'code' => 'FORBIDDEN'], 403);
        }

        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;

        return response()->json([
            'success' => true,
            'data' => $this->reportService->buildReport($from, $to),
        ]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $allowed = ['procurement_manager', 'procurement', 'supply_chain_director', 'supply_chain', 'admin', 'finance', 'finance_officer'];
        if (!$user || !in_array($user->role, $allowed, true)) {
            return response()->json(['success' => false, 'error' => 'Insufficient permissions', 'code' => 'FORBIDDEN'], 403);
        }

        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;
        $rows = $this->reportService->exportRows($from, $to);

        $filename = 'procurement-report-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['metric', 'value']);
            foreach ($rows as $row) {
                fputcsv($handle, [$row['metric'], $row['value']]);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
