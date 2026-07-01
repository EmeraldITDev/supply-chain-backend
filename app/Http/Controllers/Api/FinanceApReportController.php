<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceAp\FinanceApReportingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceApReportController extends Controller
{
    private const ALLOWED_ROLES = [
        'finance', 'finance_officer', 'procurement_manager', 'procurement',
        'supply_chain_director', 'supply_chain', 'admin',
    ];

    public function __construct(
        private FinanceApReportingService $reporting,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        [$from, $to] = $this->parsePeriod($request);

        return response()->json([
            'success' => true,
            'data' => $this->reporting->summary($from, $to),
        ]);
    }

    public function outstandingMilestones(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        [$from, $to] = $this->parsePeriod($request);
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        return response()->json([
            'success' => true,
            'data' => $this->reporting->outstandingMilestones($from, $to, $limit),
        ]);
    }

    public function advanceDeliveryRisk(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        return response()->json([
            'success' => true,
            'data' => $this->reporting->advanceDeliveryRisk($limit),
        ]);
    }

    public function cycleTimes(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        [$from, $to] = $this->parsePeriod($request);

        return response()->json([
            'success' => true,
            'data' => $this->reporting->cycleTimes($from, $to),
        ]);
    }

    public function syncEvents(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $status = $request->filled('status') ? (string) $request->query('status') : null;
        $eventType = $request->filled('event_type') ? (string) $request->query('event_type') : null;

        return response()->json([
            'success' => true,
            'data' => $this->reporting->syncEvents($limit, $status, $eventType),
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

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function parsePeriod(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;

        return [$from, $to];
    }
}
