<?php

namespace App\Services\Analytics;

use App\Models\MRF;
use App\Support\AnalyticsBaseline;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unified KPI calculation service.
 *
 * All methods document:
 *   - Population (entity, eligibility)
 *   - Timestamp used for period filter
 *   - Legacy / baseline treatment
 *   - Insufficient-data conditions
 *
 * IMPORTANT: Methods prefixed with 'operational' are NOT filtered by analytics
 * baseline — they represent real-time outstanding work visible to all roles
 * regardless of record age. Methods prefixed with 'performance' ARE filtered
 * to the new-period population and exclude unreconciled legacy records.
 */
class ScmKpiService
{
    /** Minimum eligible sample for a meaningful average metric. */
    private const MIN_SAMPLE = 3;

    public function __construct(
        private readonly AnalyticsClassificationService $classification,
    ) {
    }

    // ─── OPERATIONAL METRICS (all classes, real-time) ────────────────────────

    /**
     * Total outstanding MRFs in any active state.
     * Includes legacy carry-over items — they must remain visible.
     *
     * @return array{count: int, legacy_count: int}
     */
    public function operationalOpenWorkload(): array
    {
        $terminalStates = ['closed', 'financially_complete', 'operationally_complete'];

        $base = MRF::query()
            ->whereNotIn('workflow_state', $terminalStates)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(status) NOT IN ('completed', 'rejected', 'cancelled')");
            });

        $total  = (clone $base)->count();
        $legacy = $this->classification->countOutstandingLegacy();

        return ['count' => $total, 'legacy_count' => $legacy];
    }

    /**
     * Org-wide count of MRFs in an approval-pending workflow state.
     * Source for "MRFs awaiting approval" pipeline label (NOT the personal queue).
     */
    public function operationalMrfsAwaitingApproval(): int
    {
        $approvalLikeStates = [
            'parallel_first_approval',
            'supply_chain_director_review',
            'executive_review',
            'lazarus_director_approval',
            'procurement_review',
            'vendor_selection_pending',
            'pending',
        ];

        return MRF::query()
            ->whereIn('workflow_state', $approvalLikeStates)
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(status) NOT IN ('completed', 'rejected', 'cancelled', 'closed')");
            })
            ->count();
    }

    // ─── PERFORMANCE METRICS (new-period population) ─────────────────────────

    /**
     * Requests raised in the period.
     *
     * Population: new-period MRFs with created_at in [from, to].
     * Timestamp: created_at.
     * Legacy treatment: pre-baseline records excluded.
     *
     * @return array{count: int, insufficient_data: bool}
     */
    public function performanceRequestsRaised(Carbon $from, Carbon $to): array
    {
        $baseline = AnalyticsBaseline::effectiveAt();

        $query = MRF::query()
            ->whereBetween('created_at', [$from, $to]);

        if ($baseline !== null) {
            $this->classification->scopeNewPeriod($query, $baseline);
        }

        $count = $query->count();

        return [
            'count'            => $count,
            'insufficient_data' => false, // Counts are always valid
        ];
    }

    /**
     * Average approval cycle time in days.
     *
     * Population: new-period MRFs that completed at least one approval
     * and have both a stage-entry and approval timestamp.
     * Timestamp: director_approved_at / executive_approved_at.
     * Legacy treatment: unreconciled_legacy excluded from average.
     * Insufficient data: fewer than MIN_SAMPLE eligible approvals.
     *
     * @return array{avg_days: float|null, sample_size: int, insufficient_data: bool}
     */
    public function performanceApprovalCycleTime(Carbon $from, Carbon $to): array
    {
        $baseline = AnalyticsBaseline::effectiveAt();

        $query = MRF::query()
            ->where(function ($q): void {
                $q->whereNotNull('director_approved_at')
                    ->orWhereNotNull('executive_approved_at');
            })
            ->whereBetween('updated_at', [$from, $to]);

        if ($baseline !== null) {
            $this->classification->scopeNewPeriod($query, $baseline);
        }

        // Prefer director_approved_at (SCD path), fall back to executive_approved_at
        $rows = $query
            ->select([
                'created_at',
                DB::raw('COALESCE(director_approved_at, executive_approved_at) AS approval_ts'),
            ])
            ->get();

        if ($rows->isEmpty()) {
            return ['avg_days' => null, 'sample_size' => 0, 'insufficient_data' => true];
        }

        $days = $rows->map(function ($row): ?float {
            if (! $row->approval_ts) {
                return null;
            }
            $created  = Carbon::parse($row->created_at);
            $approved = Carbon::parse($row->approval_ts);

            return max(0.0, (float) $created->diffInMinutes($approved) / 1440);
        })->filter()->values();

        $sampleSize = $days->count();

        if ($sampleSize < self::MIN_SAMPLE) {
            return [
                'avg_days'         => $sampleSize > 0 ? round($days->avg(), 1) : null,
                'sample_size'      => $sampleSize,
                'insufficient_data' => true,
            ];
        }

        return [
            'avg_days'         => round($days->avg(), 1),
            'sample_size'      => $sampleSize,
            'insufficient_data' => false,
        ];
    }

    /**
     * Delivery on-time percentage.
     *
     * Population: new-period MRFs with a verified delivery event AND
     * an expected_delivery_date.
     * Timestamp: expected_delivery_date vs actual delivery timestamp.
     * Legacy treatment: unreconciled_legacy excluded.
     * Insufficient data: no eligible deliveries.
     *
     * @return array{on_time_pct: float|null, on_time: int, late: int, in_progress: int, overdue: int, insufficient_data: bool}
     */
    public function performanceDeliveryOnTime(Carbon $from, Carbon $to): array
    {
        $baseline = AnalyticsBaseline::effectiveAt();

        $query = MRF::query()
            ->whereNotNull('expected_delivery_date')
            ->where(function ($q): void {
                // Has a delivery-state indicator
                $q->whereNotNull('delivery_confirmed_at')
                    ->orWhereIn('workflow_state', [
                        'delivery_confirmation_pending',
                        'delivery_confirmation_complete',
                        'financially_complete',
                        'operationally_complete',
                    ]);
            })
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);

        if ($baseline !== null) {
            $this->classification->scopeNewPeriod($query, $baseline);
        }

        $rows = $query->select([
            'workflow_state',
            'expected_delivery_date',
            DB::raw('COALESCE(delivery_confirmed_at, updated_at) AS delivery_ts'),
        ])->get();

        if ($rows->isEmpty()) {
            return [
                'on_time_pct'      => null,
                'on_time'          => 0,
                'late'             => 0,
                'in_progress'      => 0,
                'overdue'          => 0,
                'insufficient_data' => true,
            ];
        }

        $onTime     = 0;
        $late       = 0;
        $inProgress = 0;
        $overdue    = 0;

        foreach ($rows as $row) {
            $expected = Carbon::parse($row->expected_delivery_date)->endOfDay();
            $state    = strtolower((string) $row->workflow_state);
            $now      = now();

            $isDelivered = in_array($state, [
                'delivery_confirmation_complete',
                'financially_complete',
                'operationally_complete',
            ]);

            if ($isDelivered) {
                $deliveredAt = Carbon::parse($row->delivery_ts);
                if ($deliveredAt->lte($expected)) {
                    $onTime++;
                } else {
                    $late++;
                }
            } elseif ($now->gt($expected)) {
                $overdue++;
            } else {
                $inProgress++;
            }
        }

        $denominator = $onTime + $late + $overdue;
        $pct         = $denominator > 0 ? round(($onTime / $denominator) * 100, 1) : null;

        return [
            'on_time_pct'      => $pct,
            'on_time'          => $onTime,
            'late'             => $late,
            'in_progress'      => $inProgress,
            'overdue'          => $overdue,
            'insufficient_data' => $denominator === 0,
        ];
    }

    /**
     * Open workload health rating.
     *
     * Thresholds (matching frontend constants):
     *   > 40 outstanding → attention
     *   else → healthy
     *
     * @return array{status: string, count: int, legacy_count: int}
     */
    public function healthOpenWorkload(): array
    {
        $workload = $this->operationalOpenWorkload();
        $count    = $workload['count'];

        $status = match (true) {
            $count > 40 => 'attention',
            default     => 'healthy',
        };

        return ['status' => $status, 'count' => $count, 'legacy_count' => $workload['legacy_count']];
    }

    /**
     * Approval performance health rating.
     *
     * SLA: 3 days (matching APPROVAL_SLA_DAYS in frontend).
     * avg > 5d → critical; avg > 3d → attention; else → healthy.
     * Insufficient data → 'insufficient_data'.
     *
     * @return array{status: string, avg_days: float|null, insufficient_data: bool}
     */
    public function healthApprovalPerformance(Carbon $from, Carbon $to): array
    {
        $result = $this->performanceApprovalCycleTime($from, $to);

        if ($result['insufficient_data']) {
            return ['status' => 'insufficient_data', 'avg_days' => $result['avg_days'], 'insufficient_data' => true];
        }

        $avg    = $result['avg_days'];
        $status = match (true) {
            $avg > 5  => 'critical',
            $avg > 3  => 'attention',
            default   => 'healthy',
        };

        return ['status' => $status, 'avg_days' => $avg, 'insufficient_data' => false];
    }

    /**
     * Full KPI payload for the analytics API response.
     *
     * @return array<string, mixed>
     */
    public function buildKpiPayload(Carbon $from, Carbon $to): array
    {
        $requestsRaised   = $this->performanceRequestsRaised($from, $to);
        $approvalHealth   = $this->healthApprovalPerformance($from, $to);
        $deliveryStats    = $this->performanceDeliveryOnTime($from, $to);
        $workloadHealth   = $this->healthOpenWorkload();
        $baseline         = AnalyticsBaseline::config();

        return [
            'baseline'                    => $baseline,
            'period'                      => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'sla_constants'               => [
                'approval_sla_days'  => 3,
                'delivery_sla_days'  => 21,
                'stall_days'         => 7,
                'high_value_ngn'     => 5_000_000,
                'exec_approval_days' => 2,
            ],
            'requests_raised'             => $requestsRaised,
            'mrfs_awaiting_approval_org'  => [
                'count'            => $this->operationalMrfsAwaitingApproval(),
                'insufficient_data' => false,
            ],
            'approval_performance'        => [
                'avg_days'         => $approvalHealth['avg_days'],
                'status'           => $approvalHealth['status'],
                'insufficient_data' => $approvalHealth['insufficient_data'],
            ],
            'delivery_performance'        => $deliveryStats,
            'open_workload'               => [
                'count'       => $workloadHealth['count'],
                'legacy_count' => $workloadHealth['legacy_count'],
                'status'      => $workloadHealth['status'],
            ],
        ];
    }
}
