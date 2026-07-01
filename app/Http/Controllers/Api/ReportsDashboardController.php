<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportsDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsDashboardController extends Controller
{
    private const ALLOWED_ROLES = [
        'procurement_manager', 'procurement', 'supply_chain_director', 'supply_chain',
        'admin', 'finance', 'finance_officer', 'logistics_manager', 'logistics_officer',
    ];

    public function __construct(private ReportsDashboardService $dashboardService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;

        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->dashboard($from, $to),
        ]);
    }

    private function authorizeReport(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->scmRole(), self::ALLOWED_ROLES, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return null;
    }
}
