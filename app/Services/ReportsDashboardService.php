<?php

namespace App\Services;

use App\Models\GeneratedReport;
use App\Models\Logistics\Material;
use App\Models\MRF;
use App\Models\ScheduledReport;
use App\Support\ReportCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsDashboardService
{
    public function __construct(private ProcurementReportService $procurementReportService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?Carbon $from, ?Carbon $to): array
    {
        $periodEnd = $to ?? Carbon::now()->endOfDay();
        $periodStart = $from ?? $periodEnd->copy()->subDays(30)->startOfDay();

        $cacheKey = ReportCache::key('reports_dashboard', [
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
        ]);

        return ReportCache::remember($cacheKey, function () use ($periodStart, $periodEnd) {
            return $this->buildDashboard($periodStart, $periodEnd);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboard(Carbon $periodStart, Carbon $periodEnd): array
    {
        $spanDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $previousEnd = $periodStart->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($spanDays - 1)->startOfDay();

        $current = $this->metricSnapshot($periodStart, $periodEnd);
        $previous = $this->metricSnapshot($previousStart, $previousEnd);

        return [
            'period' => [
                'from' => $periodStart->toDateString(),
                'to' => $periodEnd->toDateString(),
            ],
            'kpis' => [
                $this->kpiCard('Procurement Cycle Time', $current['procurementCycleDays'], $previous['procurementCycleDays'], 'days', true),
                $this->kpiCard('Inventory Turnover', $current['inventoryTurnover'], $previous['inventoryTurnover'], 'x', false),
                $this->kpiCard('On-Time Delivery', $current['onTimeDeliveryPct'], $previous['onTimeDeliveryPct'], '%', false),
                $this->kpiCard('Cost Savings', $current['costSavings'], $previous['costSavings'], 'NGN', false),
            ],
            'recentReports' => $this->recentReports(),
            'scheduledReports' => $this->scheduledReports(),
        ];
    }

    /**
     * @return array{procurementCycleDays: float|null, inventoryTurnover: float|null, onTimeDeliveryPct: float, costSavings: float}
     */
    private function metricSnapshot(Carbon $from, Carbon $to): array
    {
        $procurementCycleDays = $this->avgProcurementCycleDays($from, $to);

        $delivered = Material::query()
            ->where('status', 'delivered')
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        $inTransit = Material::query()
            ->whereIn('status', ['in_transit', 'pending'])
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        $avgInventory = max(1, (int) round(($delivered + $inTransit) / 2));
        $inventoryTurnover = round($delivered / $avgInventory, 1);

        $onTimeRow = DB::table('quotations')
            ->join('r_f_q_s as rfqs', 'quotations.rfq_id', '=', 'rfqs.id')
            ->where('quotations.status', 'Approved')
            ->whereNotNull('quotations.delivery_date')
            ->whereNotNull('rfqs.deadline')
            ->whereBetween('quotations.updated_at', [$from, $to])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN quotations.delivery_date <= rfqs.deadline THEN 1 ELSE 0 END) as on_time')
            ->first();

        $totalDeliveries = (int) ($onTimeRow->total ?? 0);
        $onTimeCount = (int) ($onTimeRow->on_time ?? 0);
        $onTimeDeliveryPct = $totalDeliveries > 0
            ? round(($onTimeCount / $totalDeliveries) * 100, 1)
            : 0.0;

        $costSavings = $this->procurementReportService->totalSavingsForPeriod($from, $to);

        return [
            'procurementCycleDays' => $procurementCycleDays,
            'inventoryTurnover' => $inventoryTurnover,
            'onTimeDeliveryPct' => $onTimeDeliveryPct,
            'costSavings' => $costSavings,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentReports(): array
    {
        return GeneratedReport::query()
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (GeneratedReport $report) => [
                'id' => $report->id,
                'name' => $report->name,
                'type' => $report->report_type,
                'format' => $report->format,
                'status' => $report->status,
                'date' => ($report->completed_at ?? $report->created_at)?->toDateString(),
                'size' => $this->formatFileSize($report->file_size_bytes),
                'downloadUrl' => $report->storage_path
                    ? url('/api/reports/generated/'.$report->id.'/download')
                    : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduledReports(): array
    {
        return ScheduledReport::query()
            ->where('is_active', true)
            ->orderBy('next_run_at')
            ->limit(20)
            ->get()
            ->map(fn (ScheduledReport $report) => [
                'id' => $report->id,
                'name' => $report->name,
                'type' => $report->report_type,
                'frequency' => $report->frequency,
                'nextRun' => $report->next_run_at?->toDateString() ?? '—',
                'recipients' => is_array($report->recipient_user_ids) ? count($report->recipient_user_ids) : 0,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function kpiCard(
        string $name,
        float|int|null $current,
        float|int|null $previous,
        string $unit,
        bool $lowerIsBetter,
    ): array {
        $changePct = $this->percentChange($current, $previous);
        $trend = 'flat';
        if ($changePct !== null && $changePct !== 0.0) {
            $improved = $lowerIsBetter ? $changePct < 0 : $changePct > 0;
            $trend = $improved ? 'up' : 'down';
        }

        return [
            'name' => $name,
            'value' => $this->formatKpiValue($name, $current, $unit),
            'rawValue' => $current,
            'unit' => $unit,
            'change' => $changePct !== null ? sprintf('%+.1f%%', $changePct) : '—',
            'trend' => $trend,
        ];
    }

    private function formatKpiValue(string $name, float|int|null $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($name) {
            'Cost Savings' => '₦'.number_format((float) $value, 0),
            'On-Time Delivery' => round((float) $value, 1).'%',
            'Inventory Turnover' => round((float) $value, 1).'x',
            'Procurement Cycle Time' => round((float) $value, 1).' days',
            default => (string) $value.($unit !== 'NGN' ? ' '.$unit : ''),
        };
    }

    private function percentChange(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : 100.0;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
    }

    private function avgProcurementCycleDays(Carbon $from, Carbon $to): ?float
    {
        $driver = DB::connection()->getDriverName();
        $diffExpr = match ($driver) {
            'sqlite' => 'CAST(julianday(po_signed_at) - julianday(created_at) AS REAL)',
            'pgsql' => 'EXTRACT(EPOCH FROM (po_signed_at - created_at)) / 86400',
            default => 'DATEDIFF(po_signed_at, created_at)',
        };

        $avg = MRF::query()
            ->whereNotNull('po_signed_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("AVG({$diffExpr}) as avg_days")
            ->value('avg_days');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    private function formatFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
