<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\Quotation;
use App\Models\RFQ;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Support\ProcurementOverviewAccess;
use App\Support\UserRoleNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcurementDashboardController extends Controller
{
    /**
     * Procurement intelligence stats with period-over-period comparison.
     *
     * GET /api/dashboard/procurement
     * GET /api/procurement/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedRoles = array_merge(
            ProcurementOverviewAccess::MANAGEMENT_ROLES,
            ProcurementOverviewAccess::OVERVIEW_ROLES,
            ['logistics_officer'],
        );

        $hasAllowedRole =
            (UserRoleNormalizer::supplyChainRole($user) !== null && in_array($user->scmRole(), $allowedRoles, true)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (! $hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $periodDays = max(1, min(365, (int) $request->get('period_days', 30)));
        $cacheKey = "procurement_dashboard_stats_{$periodDays}";

        $payload = Cache::remember($cacheKey, 300, function () use ($periodDays) {
            return $this->computePeriodStats($periodDays);
        });

        $dataCapture = \App\Support\DashboardDataCapture::snapshot();

        return response()->json([
            'success' => true,
            'period_days' => $periodDays,
            'stats' => $payload,
            'data_capture' => $dataCapture,
            'dataCapture' => $dataCapture,
        ]);
    }

    /**
     * Pipeline stage volume + average days.
     *
     * GET /api/procurement/pipeline-stats
     */
    public function pipelineStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedRoles = array_merge(
            ProcurementOverviewAccess::MANAGEMENT_ROLES,
            ProcurementOverviewAccess::OVERVIEW_ROLES,
            ['logistics_officer'],
        );

        $hasAllowedRole =
            (UserRoleNormalizer::supplyChainRole($user) !== null && in_array($user->scmRole(), $allowedRoles, true)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (! $hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $periodDays = max(1, min(365, (int) $request->get('period_days', 30)));
        $cacheKey = "pipeline_stats_{$periodDays}";

        $data = Cache::remember($cacheKey, 300, function () use ($periodDays) {
            $since = now()->subDays($periodDays);
            $mrfs = DB::table('m_r_f_s')
                ->where('created_at', '>=', $since)
                ->select([
                    'created_at',
                    'director_approved_at',
                    'rfq_issued_at',
                    'quotation_received_at',
                    'po_generated_at',
                    'po_signed_at',
                    'grn_completed_at',
                ])
                ->get();

            $stages = [
                [
                    'name' => 'MRF to Approval',
                    'avg_days' => $this->avgDaysBetween($mrfs, 'created_at', 'director_approved_at'),
                    'volume' => $mrfs->whereNotNull('director_approved_at')->count(),
                    'is_slow' => false,
                ],
                [
                    'name' => 'Approval to RFQ',
                    'avg_days' => $this->avgDaysBetween($mrfs, 'director_approved_at', 'rfq_issued_at'),
                    'volume' => $mrfs->whereNotNull('rfq_issued_at')->count(),
                    'is_slow' => false,
                ],
                [
                    'name' => 'RFQ to Quotation',
                    'avg_days' => $this->avgDaysBetween($mrfs, 'rfq_issued_at', 'quotation_received_at'),
                    'volume' => $mrfs->whereNotNull('quotation_received_at')->count(),
                    'is_slow' => false,
                ],
                [
                    'name' => 'Quotation to PO',
                    'avg_days' => $this->avgDaysBetween($mrfs, 'quotation_received_at', 'po_generated_at'),
                    'volume' => $mrfs->whereNotNull('po_generated_at')->count(),
                    'is_slow' => false,
                ],
                [
                    'name' => 'PO to Delivery',
                    'avg_days' => $this->avgDaysBetween($mrfs, 'po_generated_at', 'grn_completed_at'),
                    'volume' => $mrfs->whereNotNull('grn_completed_at')->count(),
                    'is_slow' => false,
                ],
            ];

            $avgValues = collect($stages)
                ->pluck('avg_days')
                ->filter(fn ($v) => $v !== null)
                ->values();
            $overallAvg = $avgValues->count() > 0 ? $avgValues->average() : null;

            if ($overallAvg !== null) {
                foreach ($stages as &$stage) {
                    $stage['is_slow'] = $stage['avg_days'] !== null
                        && $stage['avg_days'] > ($overallAvg * 1.5);
                }
                unset($stage);
            }

            return [
                'period_days' => $periodDays,
                'stages' => $stages,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function computePeriodStats(int $periodDays): array
    {
        $currentStart = now()->subDays($periodDays);
        $previousStart = now()->subDays($periodDays * 2);
        $previousEnd = $currentStart;

        $metricDefs = [
            'pending_mrfs' => fn (Carbon $from, Carbon $to) => MRF::where('status', 'pending')
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'approved_mrfs' => fn (Carbon $from, Carbon $to) => MRF::whereNotNull('director_approved_at')
                ->whereBetween('director_approved_at', [$from, $to])
                ->count(),
            'rejected_mrfs' => fn (Carbon $from, Carbon $to) => MRF::whereNotNull('rejected_at')
                ->whereBetween('rejected_at', [$from, $to])
                ->count(),
            'pos_generated' => fn (Carbon $from, Carbon $to) => MRF::whereNotNull('po_generated_at')
                ->whereBetween('po_generated_at', [$from, $to])
                ->count(),
            'pos_signed' => fn (Carbon $from, Carbon $to) => MRF::whereNotNull('po_signed_at')
                ->whereBetween('po_signed_at', [$from, $to])
                ->count(),
            'vendor_registrations' => fn (Carbon $from, Carbon $to) => VendorRegistration::whereBetween('created_at', [$from, $to])
                ->count(),
            'rfqs_issued' => fn (Carbon $from, Carbon $to) => MRF::whereNotNull('rfq_issued_at')
                ->whereBetween('rfq_issued_at', [$from, $to])
                ->count(),
            'quotations_received' => fn (Carbon $from, Carbon $to) => MRF::whereNotNull('quotation_received_at')
                ->whereBetween('quotation_received_at', [$from, $to])
                ->count(),
        ];

        $stats = [];
        foreach ($metricDefs as $key => $counter) {
            $current = (int) $counter($currentStart, now());
            $previous = (int) $counter($previousStart, $previousEnd);
            $change = $current - $previous;
            $changePct = $previous > 0
                ? round(($change / $previous) * 100, 1)
                : ($current > 0 ? 100.0 : 0.0);

            $stats[$key] = $current;
            $stats["{$key}_previous"] = $previous;
            $stats["{$key}_change"] = $change;
            $stats["{$key}_change_pct"] = $changePct;

            // Camel aliases for existing frontend conventions
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $stats[$camel] = $current;
            $stats["{$camel}_previous"] = $previous;
            $stats["{$camel}_change"] = $change;
            $stats["{$camel}_change_pct"] = $changePct;
        }

        $avgCycleCurrent = $this->averageCycleTimeDays($currentStart, now());
        $avgCyclePrevious = $this->averageCycleTimeDays($previousStart, $previousEnd);
        $avgChange = ($avgCycleCurrent ?? 0) - ($avgCyclePrevious ?? 0);
        $avgChangePct = ($avgCyclePrevious !== null && $avgCyclePrevious > 0)
            ? round(($avgChange / $avgCyclePrevious) * 100, 1)
            : (($avgCycleCurrent ?? 0) > 0 ? 100.0 : 0.0);

        $stats['average_cycle_time'] = $avgCycleCurrent;
        $stats['average_cycle_time_previous'] = $avgCyclePrevious;
        $stats['average_cycle_time_change'] = round($avgChange, 1);
        $stats['average_cycle_time_change_pct'] = $avgChangePct;
        $stats['averageCycleTime'] = $avgCycleCurrent;
        $stats['averageCycleTime_previous'] = $avgCyclePrevious;
        $stats['averageCycleTime_change'] = round($avgChange, 1);
        $stats['averageCycleTime_change_pct'] = $avgChangePct;

        $onTimeCurrent = $this->onTimeDeliveryRate($currentStart, now());
        $onTimePrevious = $this->onTimeDeliveryRate($previousStart, $previousEnd);
        $onTimeChange = round($onTimeCurrent - $onTimePrevious, 1);
        $onTimeChangePct = $onTimePrevious > 0
            ? round(($onTimeChange / $onTimePrevious) * 100, 1)
            : ($onTimeCurrent > 0 ? 100.0 : 0.0);

        $stats['on_time_delivery_rate'] = $onTimeCurrent;
        $stats['on_time_delivery_rate_previous'] = $onTimePrevious;
        $stats['on_time_delivery_rate_change'] = $onTimeChange;
        $stats['on_time_delivery_rate_change_pct'] = $onTimeChangePct;
        $stats['onTimeDelivery'] = $onTimeCurrent;
        $stats['onTimeDelivery_previous'] = $onTimePrevious;
        $stats['onTimeDelivery_change'] = $onTimeChange;
        $stats['onTimeDelivery_change_pct'] = $onTimeChangePct;

        // Snapshot counts retained for existing dashboard cards
        $stats['pendingRegistrations'] = VendorRegistration::where('status', 'Pending')->count();
        $stats['pendingMRFs'] = MRF::where('status', 'pending')->count();
        $stats['pendingQuotations'] = Quotation::where('status', 'Pending')->count();
        $stats['totalVendors'] = Vendor::where('status', 'Active')->count();
        $stats['rfqsOpen'] = RFQ::where('status', 'Open')->count();

        $stats['data_capture'] = \App\Support\DashboardDataCapture::snapshot();
        $stats['dataCapture'] = $stats['data_capture'];

        return $stats;
    }

    private function averageCycleTimeDays(Carbon $from, Carbon $to): ?float
    {
        $row = DB::table('m_r_f_s')
            ->whereNotNull('grn_completed_at')
            ->whereBetween('grn_completed_at', [$from, $to])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (grn_completed_at - created_at)) / 86400.0) as avg_days')
            ->first();

        return $row?->avg_days !== null ? round((float) $row->avg_days, 1) : null;
    }

    private function onTimeDeliveryRate(Carbon $from, Carbon $to): float
    {
        $total = DB::table('m_r_f_s')
            ->whereNotNull('grn_completed_at')
            ->whereNotNull('expected_delivery_date')
            ->whereBetween('grn_completed_at', [$from, $to])
            ->count();

        if ($total === 0) {
            return 0.0;
        }

        $onTime = DB::table('m_r_f_s')
            ->whereNotNull('grn_completed_at')
            ->whereNotNull('expected_delivery_date')
            ->whereBetween('grn_completed_at', [$from, $to])
            ->whereRaw('DATE(grn_completed_at) <= expected_delivery_date')
            ->count();

        return round(($onTime / $total) * 100, 1);
    }

    /**
     * @param  Collection<int, object>  $collection
     */
    private function avgDaysBetween(Collection $collection, string $start, string $end): ?float
    {
        $pairs = $collection
            ->whereNotNull($start)
            ->whereNotNull($end)
            ->map(fn ($row) => Carbon::parse($row->$end)
                ->diffInHours(Carbon::parse($row->$start)) / 24);

        return $pairs->count() > 0
            ? round($pairs->average(), 1)
            : null;
    }
}
