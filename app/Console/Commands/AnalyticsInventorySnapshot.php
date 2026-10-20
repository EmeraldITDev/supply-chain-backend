<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Capture record counts and basic checksums before and after a deployment.
 *
 * Usage (run twice: before and after applying migrations):
 *   php artisan scm:analytics-inventory-snapshot --label=before-deploy
 *   php artisan scm:analytics-inventory-snapshot --label=after-deploy
 *
 * Results are written to storage/app/analytics-snapshots/{label}_{datetime}.json
 * and printed to stdout for quick comparison.
 *
 * Does NOT modify any records.
 */
class AnalyticsInventorySnapshot extends Command
{
    protected $signature = 'scm:analytics-inventory-snapshot
                            {--label=snapshot : Short label for the output file}
                            {--output=storage : Where to write: storage | stdout}';

    protected $description = 'Capture pre/post deployment record counts for data integrity verification';

    public function handle(): int
    {
        $snapshot = $this->buildSnapshot();
        $label    = $this->option('label');
        $ts       = now()->format('Y-m-d_His');
        $filename = "analytics-snapshots/{$label}_{$ts}.json";

        $this->info("SCM Analytics Inventory Snapshot — {$label}");
        $this->newLine();

        $this->table(
            ['Entity', 'Total records', 'Notes'],
            collect($snapshot['entities'])->map(fn ($e) => [
                $e['table'],
                number_format($e['count']),
                $e['notes'] ?? '',
            ])->all(),
        );

        if ($this->option('output') === 'stdout') {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT));
        } else {
            Storage::put($filename, json_encode($snapshot, JSON_PRETTY_PRINT));
            $this->line("Snapshot written to storage/app/{$filename}");
        }

        $this->newLine();
        $this->info('Run with --label=after-deploy after deployment and compare counts.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(): array
    {
        $entities = [];

        // Core SCM entities
        foreach ($this->tableMap() as $table => $notes) {
            if (Schema::hasTable($table)) {
                $entities[] = [
                    'table' => $table,
                    'count' => (int) DB::table($table)->count(),
                    'notes' => $notes,
                ];
            }
        }

        // Extra integrity checks
        $mrfWithPo    = Schema::hasTable('m_r_f_s')
            ? (int) DB::table('m_r_f_s')->whereNotNull('po_number')->where('po_number', '<>', '')->count()
            : 0;
        $mrfWithFiles = Schema::hasTable('m_r_f_s')
            ? (int) DB::table('m_r_f_s')->whereNotNull('unsigned_po_url')->where('unsigned_po_url', '<>', '')->count()
            : 0;

        $analyticsClass = Schema::hasColumn('m_r_f_s', 'analytics_class')
            ? DB::table('m_r_f_s')->selectRaw('analytics_class, COUNT(*) as cnt')
                ->groupBy('analytics_class')
                ->pluck('cnt', 'analytics_class')
                ->toArray()
            : ['column_not_present' => true];

        return [
            'label'            => $this->option('label'),
            'captured_at'      => now()->toIso8601String(),
            'entities'         => $entities,
            'integrity_checks' => [
                'mrfs_with_po_number'       => $mrfWithPo,
                'mrfs_with_unsigned_po_url' => $mrfWithFiles,
                'mrf_analytics_class_dist'  => $analyticsClass,
            ],
        ];
    }

    /** @return array<string, string> */
    private function tableMap(): array
    {
        return [
            'm_r_f_s'                        => 'Material Requisition Forms',
            's_r_f_s'                        => 'Stock Requisition Forms',
            'r_f_q_s'                        => 'Requests for Quotation',
            'quotations'                     => 'Quotations',
            'vendors'                        => 'Vendors',
            'vendor_registrations'           => 'Vendor registrations',
            'mrf_approval_history'           => 'MRF approval history entries',
            'mrf_items'                      => 'MRF line items',
            'r_f_q_items'                    => 'RFQ line items',
            'r_f_q_vendors'                  => 'RFQ vendor invites',
            'audit_logs'                     => 'Audit log entries',
            'notifications'                  => 'In-app notification records',
            'procurement_reminder_dispatches' => 'Reminder dispatch log',
        ];
    }
}
