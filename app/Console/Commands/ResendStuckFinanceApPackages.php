<?php

namespace App\Console\Commands;

use App\Models\FinanceSyncEvent;
use App\Models\MRF;
use App\Services\Finance\FinanceIntegrationService;
use App\Services\WorkflowStateService;
use Illuminate\Console\Command;

/**
 * Re-send Finance AP packages for MRFs whose first push was rejected and that
 * are still waiting at finance handoff. A successful push moves them to
 * finance review, as it would have done originally.
 *
 * Finance AP saved those cases at package version 1 before rejecting the push and
 * refuses a different package at the same version, so this sends the next one.
 *
 * Usage:
 *   php artisan finance-ap:resend-stuck-packages            (preview)
 *   php artisan finance-ap:resend-stuck-packages --execute --limit=5
 */
class ResendStuckFinanceApPackages extends Command
{
    protected $signature = 'finance-ap:resend-stuck-packages
                            {--execute : Send the packages (without it, only lists them)}
                            {--limit=50 : Max MRFs to process}
                            {--finance-version=1 : Package version Finance AP currently holds for these cases}';

    protected $description = 'Re-send Finance AP packages for MRFs stuck at finance handoff after a failed push';

    public function handle(FinanceIntegrationService $integration): int
    {
        $nextVersion = max(1, (int) $this->option('finance-version')) + 1;

        $mrfs = MRF::query()
            ->where('workflow_state', WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING)
            ->where(fn ($q) => $q->whereNull('finance_ap_case_id')->orWhere('finance_ap_case_id', ''))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->filter(fn (MRF $mrf) => mrfUsesFinanceAp($mrf))
            ->values();

        if ($mrfs->isEmpty()) {
            $this->info('No MRFs are waiting at finance handoff without a Finance AP case.');

            return self::SUCCESS;
        }

        $this->table(
            ['MRF', 'Title', 'PO number', 'Last push error'],
            $mrfs->map(fn (MRF $mrf) => [
                $mrf->formatted_id ?: $mrf->mrf_id,
                mb_strimwidth((string) $mrf->title, 0, 40, '…'),
                $mrf->po_number,
                mb_strimwidth((string) $this->lastPushError($mrf), 0, 60, '…'),
            ])->all()
        );

        if (! $this->option('execute')) {
            $this->info("Preview only: {$mrfs->count()} MRF(s) would be re-sent at package version {$nextVersion}. Add --execute to send them.");

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($mrfs as $mrf) {
            $integration->pushPackage($mrf, null, null, $nextVersion);
            $mrf->refresh();

            if (filled($mrf->finance_ap_case_id)) {
                $this->line("Sent {$mrf->mrf_id}: Finance AP case {$mrf->finance_ap_case_id}, now {$mrf->workflow_state}.");
            } else {
                $failed++;
                $this->error("Failed {$mrf->mrf_id}: ".($this->lastPushError($mrf) ?? 'see finance_sync_events'));
            }
        }

        $this->info(($mrfs->count() - $failed)." sent, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function lastPushError(MRF $mrf): ?string
    {
        return FinanceSyncEvent::query()
            ->where('mrf_id', $mrf->id)
            ->where('event_type', 'package_push')
            ->latest('id')
            ->value('error_message');
    }
}
