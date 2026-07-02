<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceAp\FinanceApReportingService;
use App\Support\ReportCache;
use App\Support\ScmReportViewerRoles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceApReportController extends Controller
{
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

        $cacheKey = ReportCache::key('finance_ap_summary', [
            $from?->toDateString() ?? 'all',
            $to?->toDateString() ?? 'all',
        ]);

        return response()->json([
            'success' => true,
            'data' => ReportCache::remember($cacheKey, fn () => $this->reporting->summary($from, $to)),
        ]);
    }

    public function outstandingMilestones(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        [$from, $to] = $this->parsePeriod($request);
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $cacheKey = ReportCache::key('finance_ap_outstanding', [
            $from?->toDateString() ?? 'all',
            $to?->toDateString() ?? 'all',
            $limit,
        ]);

        return response()->json([
            'success' => true,
            'data' => ReportCache::remember(
                $cacheKey,
                fn () => $this->reporting->outstandingMilestones($from, $to, $limit),
            ),
        ]);
    }

    public function advanceDeliveryRisk(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $cacheKey = ReportCache::key('finance_ap_advance_risk', [$limit]);

        return response()->json([
            'success' => true,
            'data' => ReportCache::remember(
                $cacheKey,
                fn () => $this->reporting->advanceDeliveryRisk($limit),
            ),
        ]);
    }

    public function cycleTimes(Request $request): JsonResponse
    {
        if ($response = $this->authorizeReport($request)) {
            return $response;
        }

        [$from, $to] = $this->parsePeriod($request);

        $cacheKey = ReportCache::key('finance_ap_cycle_times', [
            $from?->toDateString() ?? 'all',
            $to?->toDateString() ?? 'all',
        ]);

        return response()->json([
            'success' => true,
            'data' => ReportCache::remember(
                $cacheKey,
                fn () => $this->reporting->cycleTimes($from, $to),
            ),
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

        if (! $user || ! ScmReportViewerRoles::allows($user->scmRole())) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function parsePeriod(Request $request): array
    {
        $periodEnd = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::now()->endOfDay();
        $periodStart = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : $periodEnd->copy()->subDays(30)->startOfDay();

        return [$periodStart, $periodEnd];
    }
}
