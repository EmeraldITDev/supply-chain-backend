<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\PriceComparison;
use App\Models\SRF;
use App\Models\SRFItem;
use App\Support\ReportCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProcurementReportService
{
    /**
     * @return array<string, mixed>
     */
    public function buildReport(?Carbon $from, ?Carbon $to): array
    {
        [$from, $to] = $this->resolveReportPeriod($from, $to);

        $cacheKey = ReportCache::key('procurement_report', [$from->toDateString(), $to->toDateString()]);

        return ReportCache::remember($cacheKey, fn () => $this->buildReportUncached($from, $to));
    }

    public function totalSavingsForPeriod(Carbon $from, Carbon $to): float
    {
        [$from, $to] = $this->resolveReportPeriod($from, $to);

        $cacheKey = ReportCache::key('procurement_savings', [$from->toDateString(), $to->toDateString()]);

        return ReportCache::remember($cacheKey, function () use ($from, $to) {
            return $this->aggregateSavingsLoss($from, $to)['totalSavings'];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportUncached(Carbon $from, Carbon $to): array
    {
        $mrfQuery = MRF::query()->whereBetween('created_at', [$from, $to]);
        $srfQuery = SRF::query()->whereBetween('created_at', [$from, $to]);

        $poGenerated = (clone $mrfQuery)->whereNotNull('po_number')->count();
        $mrfsApproved = (clone $mrfQuery)->where(function ($q): void {
            $q->where('executive_approved', true)
                ->orWhereNotNull('director_approved_at')
                ->orWhereIn('workflow_state', ['procurement_review', 'vendor_selection', 'po_generation', 'po_signed', 'closed']);
        })->count();

        $srfsApproved = (clone $srfQuery)->where('status', 'Approved')->count();

        $priceComparisonCount = PriceComparison::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->distinct('purchase_order_id')
            ->count('purchase_order_id');

        $savingsLoss = $this->aggregateSavingsLoss($from, $to);
        $priceComparisons = $this->priceComparisonSummaries($from, $to);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => [
                'totalSavings' => $savingsLoss['totalSavings'],
                'totalLoss' => $savingsLoss['totalLoss'],
                'netVariance' => $savingsLoss['netVariance'],
                'lineItemsWithBudget' => $savingsLoss['lineCount'],
                'posGenerated' => $poGenerated,
                'mrfsApproved' => $mrfsApproved,
                'srfsApproved' => $srfsApproved,
                'priceComparisonMrfs' => $priceComparisonCount,
            ],
            'priceComparisonSummaries' => $priceComparisons,
        ];
    }

    /**
     * @return array{totalSavings: float, totalLoss: float, netVariance: float, lineCount: int}
     */
    private function aggregateSavingsLoss(Carbon $from, Carbon $to): array
    {
        $mrfPeriodSub = $this->mrfPeriodSubquery($from, $to);
        $srfPeriodSub = $this->srfPeriodSubquery($from, $to);

        $mrfTable = (new MRFItem())->getTable();
        $srfTable = (new SRFItem())->getTable();

        $heavyMrfIds = MRF::query()
            ->whereIn('id', $mrfPeriodSub)
            ->where(function ($q) use ($mrfTable) {
                $q->whereDoesntHave('items')
                    ->orWhereExists(function ($sub) use ($mrfTable) {
                        $sub->select(DB::raw(1))
                            ->from($mrfTable)
                            ->whereColumn($mrfTable.'.mrf_id', 'm_r_f_s.id')
                            ->where(function ($w) {
                                $w->whereNull('quoted_amount')
                                    ->orWhere('quoted_amount', 0);
                            })
                            ->where('budget_amount', '>', 0);
                    });
            })
            ->pluck('id');

        $heavySrfIds = SRF::query()
            ->whereIn('id', $srfPeriodSub)
            ->where(function ($q) use ($srfTable) {
                $q->whereDoesntHave('items')
                    ->orWhereExists(function ($sub) use ($srfTable) {
                        $sub->select(DB::raw(1))
                            ->from($srfTable)
                            ->whereColumn($srfTable.'.srf_id', 's_r_f_s.id')
                            ->where(function ($w) {
                                $w->whereNull('quoted_amount')
                                    ->orWhere('quoted_amount', 0);
                            })
                            ->where('budget_amount', '>', 0);
                    });
            })
            ->pluck('id');

        $mrfLine = DB::table($mrfTable)
            ->whereIn('mrf_id', $mrfPeriodSub)
            ->when($heavyMrfIds->isNotEmpty(), fn ($q) => $q->whereNotIn('mrf_id', $heavyMrfIds))
            ->whereNotNull('quoted_amount')
            ->selectRaw('
                SUM(CASE WHEN COALESCE(budget_amount, 0) > COALESCE(quoted_amount, 0)
                    THEN COALESCE(budget_amount, 0) - COALESCE(quoted_amount, 0) ELSE 0 END) as savings,
                SUM(CASE WHEN COALESCE(quoted_amount, 0) > COALESCE(budget_amount, 0)
                    THEN COALESCE(quoted_amount, 0) - COALESCE(budget_amount, 0) ELSE 0 END) as loss,
                COUNT(*) as line_count
            ')
            ->first();

        $srfLine = DB::table($srfTable)
            ->whereIn('srf_id', $srfPeriodSub)
            ->when($heavySrfIds->isNotEmpty(), fn ($q) => $q->whereNotIn('srf_id', $heavySrfIds))
            ->whereNotNull('quoted_amount')
            ->selectRaw('
                SUM(CASE WHEN COALESCE(budget_amount, 0) > COALESCE(quoted_amount, 0)
                    THEN COALESCE(budget_amount, 0) - COALESCE(quoted_amount, 0) ELSE 0 END) as savings,
                SUM(CASE WHEN COALESCE(quoted_amount, 0) > COALESCE(budget_amount, 0)
                    THEN COALESCE(quoted_amount, 0) - COALESCE(budget_amount, 0) ELSE 0 END) as loss,
                COUNT(*) as line_count
            ')
            ->first();

        $totalSavings = (float) ($mrfLine->savings ?? 0) + (float) ($srfLine->savings ?? 0);
        $totalLoss = (float) ($mrfLine->loss ?? 0) + (float) ($srfLine->loss ?? 0);
        $lineCount = (int) ($mrfLine->line_count ?? 0) + (int) ($srfLine->line_count ?? 0);

        $mrfHeader = $this->aggregateMrfHeaderSavingsLoss($heavyMrfIds);
        $srfHeader = $this->aggregateSrfHeaderSavingsLoss($heavySrfIds);

        $totalSavings += $mrfHeader['savings'] + $srfHeader['savings'];
        $totalLoss += $mrfHeader['loss'] + $srfHeader['loss'];
        $lineCount += $mrfHeader['lineCount'] + $srfHeader['lineCount'];

        return [
            'totalSavings' => round($totalSavings, 2),
            'totalLoss' => round($totalLoss, 2),
            'netVariance' => round($totalSavings - $totalLoss, 2),
            'lineCount' => $lineCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function priceComparisonSummaries(Carbon $from, Carbon $to): array
    {
        return PriceComparison::query()
            ->whereIn('purchase_order_id', $this->mrfPeriodSubquery($from, $to))
            ->whereBetween('created_at', [$from, $to])
            ->select('purchase_order_id', DB::raw('COUNT(*) as comparison_count'), DB::raw('MIN(unit_price) as lowest_unit_price'), DB::raw('MAX(unit_price) as highest_unit_price'))
            ->groupBy('purchase_order_id')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'mrfId' => (int) $row->purchase_order_id,
                'comparisonCount' => (int) $row->comparison_count,
                'lowestUnitPrice' => (float) $row->lowest_unit_price,
                'highestUnitPrice' => (float) $row->highest_unit_price,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string|float|int>>
     */
    public function exportRows(?Carbon $from, ?Carbon $to): array
    {
        $report = $this->buildReport($from, $to);
        $rows = [
            ['metric' => 'Total Savings', 'value' => $report['totals']['totalSavings']],
            ['metric' => 'Total Loss', 'value' => $report['totals']['totalLoss']],
            ['metric' => 'Net Variance', 'value' => $report['totals']['netVariance']],
            ['metric' => 'POs Generated', 'value' => $report['totals']['posGenerated']],
            ['metric' => 'MRFs Approved', 'value' => $report['totals']['mrfsApproved']],
            ['metric' => 'SRFs Approved', 'value' => $report['totals']['srfsApproved']],
            ['metric' => 'MRFs With Price Comparisons', 'value' => $report['totals']['priceComparisonMrfs']],
        ];

        return $rows;
    }

    /**
     * Default reporting window — avoids full-table scans when the client omits dates.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveReportPeriod(?Carbon $from, ?Carbon $to): array
    {
        $periodEnd = $to ?? Carbon::now()->endOfDay();
        $periodStart = $from ?? $periodEnd->copy()->subDays(30)->startOfDay();

        return [$periodStart, $periodEnd];
    }

    /**
     * @return Builder<MRF>
     */
    private function mrfPeriodSubquery(Carbon $from, Carbon $to): Builder
    {
        return MRF::query()->whereBetween('created_at', [$from, $to])->select('id');
    }

    /**
     * @return Builder<SRF>
     */
    private function srfPeriodSubquery(Carbon $from, Carbon $to): Builder
    {
        return SRF::query()->whereBetween('created_at', [$from, $to])->select('id');
    }

    /**
     * Header-level MRF variance using SQL (no per-MRF quotation N+1).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $heavyMrfIds
     * @return array{savings: float, loss: float, lineCount: int}
     */
    private function aggregateMrfHeaderSavingsLoss($heavyMrfIds): array
    {
        $ids = $heavyMrfIds->values()->all();
        if ($ids === []) {
            return ['savings' => 0.0, 'loss' => 0.0, 'lineCount' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $row = DB::selectOne("
            SELECT
                COALESCE(SUM(GREATEST(budget - quoted, 0)), 0) AS savings,
                COALESCE(SUM(GREATEST(quoted - budget, 0)), 0) AS loss,
                COUNT(*) AS line_count
            FROM (
                SELECT
                    m.id,
                    COALESCE(m.estimated_cost, 0) AS budget,
                    COALESCE((
                        SELECT COALESCE(qu.total_amount, qu.price, 0)
                        FROM r_f_q_s r
                        INNER JOIN quotations qu ON qu.rfq_id = r.id
                        WHERE r.mrf_id = m.id
                        ORDER BY
                            CASE WHEN m.selected_vendor_id IS NOT NULL AND qu.vendor_id = m.selected_vendor_id THEN 0 ELSE 1 END,
                            CASE WHEN qu.status = 'Approved' THEN 0 ELSE 1 END,
                            COALESCE(qu.total_amount, qu.price) ASC NULLS LAST
                        LIMIT 1
                    ), 0) AS quoted
                FROM m_r_f_s m
                WHERE m.id IN ({$placeholders})
            ) hdr
            WHERE budget > 0 OR quoted > 0
        ", $ids);

        return [
            'savings' => (float) ($row->savings ?? 0),
            'loss' => (float) ($row->loss ?? 0),
            'lineCount' => (int) ($row->line_count ?? 0),
        ];
    }

    /**
     * Header-level SRF variance from SRF columns (no per-SRF PHP loop).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $heavySrfIds
     * @return array{savings: float, loss: float, lineCount: int}
     */
    private function aggregateSrfHeaderSavingsLoss($heavySrfIds): array
    {
        if ($heavySrfIds->isEmpty()) {
            return ['savings' => 0.0, 'loss' => 0.0, 'lineCount' => 0];
        }

        $srfTable = (new SRFItem())->getTable();

        $lineRow = DB::table($srfTable)
            ->whereIn('srf_id', $heavySrfIds)
            ->where(function ($w) {
                $w->whereNull('quoted_amount')->orWhere('quoted_amount', 0);
            })
            ->where('budget_amount', '>', 0)
            ->selectRaw('
                SUM(CASE WHEN COALESCE(budget_amount, 0) > 0 THEN COALESCE(budget_amount, 0) ELSE 0 END) AS savings,
                0 AS loss,
                COUNT(*) AS line_count
            ')
            ->first();

        $headerRow = DB::table('s_r_f_s as s')
            ->whereIn('s.id', $heavySrfIds)
            ->whereNotExists(function ($q) use ($srfTable) {
                $q->select(DB::raw(1))
                    ->from($srfTable)
                    ->whereColumn("{$srfTable}.srf_id", 's.id');
            })
            ->where('s.estimated_cost', '>', 0)
            ->selectRaw('
                SUM(COALESCE(s.estimated_cost, 0)) AS savings,
                0 AS loss,
                COUNT(*) AS line_count
            ')
            ->first();

        return [
            'savings' => (float) ($lineRow->savings ?? 0) + (float) ($headerRow->savings ?? 0),
            'loss' => (float) ($lineRow->loss ?? 0) + (float) ($headerRow->loss ?? 0),
            'lineCount' => (int) ($lineRow->line_count ?? 0) + (int) ($headerRow->line_count ?? 0),
        ];
    }
}
