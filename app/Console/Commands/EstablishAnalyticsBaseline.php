<?php

namespace App\Console\Commands;

use App\Services\ScmAuditService;
use App\Support\AnalyticsBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Establish the one-time analytics baseline timestamp.
 *
 * Run ONCE at the approved production cutover:
 *   php artisan scm:establish-analytics-baseline
 *
 * Refuses a second run unless --force is passed (requires business approval).
 * Every invocation (initial or forced) is recorded in audit_logs.
 *
 * The baseline affects PERFORMANCE METRIC POPULATIONS ONLY.
 * It never deletes, hides, or auto-completes any SCM record.
 */
class EstablishAnalyticsBaseline extends Command
{
    protected $signature = 'scm:establish-analytics-baseline
                            {--at= : ISO-8601 effective timestamp (defaults to now)}
                            {--timezone=UTC : IANA timezone for the timestamp}
                            {--version= : Deployment version / git SHA for traceability}
                            {--force : Overwrite an existing baseline (requires approval)}';

    protected $description = 'Record the one-time analytics baseline cutover timestamp (performance metrics only)';

    public function handle(ScmAuditService $audit): int
    {
        $existing = AnalyticsBaseline::effectiveAt();

        if ($existing && ! $this->option('force')) {
            $this->error('Analytics baseline already established at: '.$existing->toIso8601String());
            $this->line('Pass --force to overwrite (record the business authorisation before proceeding).');

            return self::FAILURE;
        }

        if ($existing && $this->option('force')) {
            $this->warn('--force specified. Overwriting existing baseline: '.$existing->toIso8601String());
        }

        $tz      = $this->option('timezone') ?: 'UTC';
        $rawAt   = $this->option('at') ?: now()->toIso8601String();
        $version = $this->option('version') ?: (trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null')) ?: 'unknown');

        try {
            $effectiveAt = \Carbon\Carbon::parse($rawAt, $tz)->toIso8601String();
        } catch (\Exception $e) {
            $this->error('Invalid --at value: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->confirm(
            "Establish analytics baseline at {$effectiveAt} (TZ: {$tz})? This is permanent unless --force is used later.",
        )) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $establishedAt = now()->toIso8601String();
        $establishedBy = $this->getActorName();

        DB::transaction(function () use ($effectiveAt, $tz, $version, $establishedAt, $establishedBy): void {
            $this->upsertSetting('effective_at', $effectiveAt);
            $this->upsertSetting('timezone', $tz);
            $this->upsertSetting('version_ref', $version);
            $this->upsertSetting('established_by', $establishedBy);
            $this->upsertSetting('established_at', $establishedAt);
        });

        $audit->record(
            action: 'analytics_baseline_established',
            entityType: 'analytics_baseline',
            entityId: 'effective_at',
            actor: null,
            description: "Analytics baseline established at {$effectiveAt} by {$establishedBy}",
            payload: [
                'effective_at'    => $effectiveAt,
                'timezone'        => $tz,
                'version_ref'     => $version,
                'established_by'  => $establishedBy,
                'established_at'  => $establishedAt,
                'forced'          => (bool) $this->option('force'),
            ],
        );

        $this->info('Analytics baseline established.');
        $this->table(
            ['Key', 'Value'],
            [
                ['effective_at', $effectiveAt],
                ['timezone', $tz],
                ['version_ref', $version],
                ['established_by', $establishedBy],
                ['established_at', $establishedAt],
            ],
        );

        return self::SUCCESS;
    }

    private function upsertSetting(string $key, string $value): void
    {
        DB::table('system_settings')
            ->updateOrInsert(
                ['category' => 'analytics_baseline', 'key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
    }

    private function getActorName(): string
    {
        // In CI/automation contexts there is no interactive user; use the host identity.
        return trim((string) shell_exec('whoami 2>/dev/null')) ?: 'deployment-process';
    }
}
