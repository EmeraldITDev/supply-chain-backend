<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceApVendorSyncService;
use Illuminate\Console\Command;

class SyncVendorsToFinanceApCommand extends Command
{
    protected $signature = 'finance-ap:sync-vendors
                            {--dry-run : Count vendors that would sync without calling Finance AP}
                            {--force : Push all active vendors to Finance AP}';

    protected $description = 'Push SCM vendor directory snapshots to Finance AP (Pattern A automatic sync)';

    public function handle(FinanceApVendorSyncService $syncService): int
    {
        if (! $syncService->isEnabled()) {
            $this->error('Finance AP vendor sync is disabled or integration is not configured.');
            $this->line('Set FINANCE_AP_BASE_URL, FINANCE_AP_API_KEY, and FINANCE_AP_VENDOR_SYNC_ENABLED=true');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('force');

        if ($dryRun) {
            $this->warn('Dry run — pass --force to push vendors to Finance AP.');
        }

        $stats = $syncService->pushAllActiveVendors($dryRun, forceResync: ! $dryRun);

        $this->table(
            ['Synced', 'Skipped', 'Failed'],
            [[$stats['synced'], $stats['skipped'], $stats['failed']]]
        );

        if ($dryRun) {
            $this->line('Run: php artisan finance-ap:sync-vendors --force');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
