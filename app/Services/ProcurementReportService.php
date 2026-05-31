<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\MRFItem;
use App\Models\PriceComparison;
use App\Models\SRF;
use App\Models\SRFItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProcurementReportService
{
    public function __construct(private LineItemBudgetService $budgetService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(?Carbon $from, ?Carbon $to): array
    {
        $mrfQuery = MRF::query();
        $srfQuery = SRF::query();

        if ($from) {
            $mrfQuery->where('created_at', '>=', $from);
            $srfQuery->where('created_at', '>=', $from);
        }
        if ($to) {
            $mrfQuery->where('created_at', '<=', $to);
            $srfQuery->where('created_at', '<=', $to);
        }

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

        $mrfIds = (clone $mrfQuery)->pluck('id');
        $srfIds = (clone $srfQuery)->pluck('id');

        $savingsLoss = $this->aggregateSavingsLoss($mrfIds, $srfIds);
        $priceComparisons = $this->priceComparisonSummaries($mrfIds, $from, $to);

        return [
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
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
     * @param  \Illuminate\Support\Collection<int, int>  $mrfIds
     * @param  \Illuminate\Support\Collection<int, int>  $srfIds
     * @return array{totalSavings: float, totalLoss: float, netVariance: float, lineCount: int}
     */
    private function aggregateSavingsLoss($mrfIds, $srfIds): array
    {
        $totalSavings = 0.0;
        $totalLoss = 0.0;
        $lineCount = 0;

        // Use chunking to avoid loading all records into memory at once
        MRF::with('items')->whereIn('id', $mrfIds)->chunk(100, function ($mrfs) use (&$totalSavings, &$totalLoss, &$lineCount) {
            foreach ($mrfs as $mrf) {
                $pl = $this->budgetService->mrfProfitAndLoss($mrf);
                $totalSavings += (float) $pl['summary']['totalSavings'];
                $totalLoss += (float) $pl['summary']['totalLoss'];
                $lineCount += (int) $pl['summary']['lineCount'];
            }
        });

        SRF::with('items')->whereIn('id', $srfIds)->chunk(100, function ($srfs) use (&$totalSavings, &$totalLoss, &$lineCount) {
            foreach ($srfs as $srf) {
                $pl = $this->budgetService->srfProfitAndLoss($srf);
                $totalSavings += (float) $pl['summary']['totalSavings'];
                $totalLoss += (float) $pl['summary']['totalLoss'];
                $lineCount += (int) $pl['summary']['lineCount'];
            }
        });

        return [
            'totalSavings' => round($totalSavings, 2),
            'totalLoss' => round($totalLoss, 2),
            'netVariance' => round($totalSavings - $totalLoss, 2),
            'lineCount' => $lineCount,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $mrfIds
     * @return array<int, array<string, mixed>>
     */
    private function priceComparisonSummaries($mrfIds, ?Carbon $from, ?Carbon $to): array
    {
        return PriceComparison::query()
            ->whereIn('purchase_order_id', $mrfIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
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
}
