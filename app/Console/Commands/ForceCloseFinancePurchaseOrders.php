<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\User;
use App\Services\ScmAuditService;
use App\Services\VendorFulfilmentService;
use App\Services\WorkflowStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bulk force-close purchase orders stuck in the Finance stage (and their MRF rows).
 *
 * Matches the Procurement PO list badge "Finance" — typically status/current_stage = finance
 * on MRF-backed POs (same database row).
 *
 * Usage:
 *   php artisan scm:force-close-finance-pos --dry-run
 *   php artisan scm:force-close-finance-pos --force --reason="Bulk close: Finance AP not updated"
 */
class ForceCloseFinancePurchaseOrders extends Command
{
    protected $signature = 'scm:force-close-finance-pos
                            {--dry-run : List matching Finance-stage POs without changing them}
                            {--force : Skip interactive confirmation}
                            {--reason=Bulk force close — Finance stage (Artisan) : Mandatory audit reason}
                            {--user-id= : Actor user id (defaults to first admin)}
                            {--limit=500 : Max records to process}';

    protected $description = 'Force-close all Finance-stage purchase orders and their associated MRFs';

    public function handle(WorkflowStateService $workflowStateService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $reason = trim((string) $this->option('reason'));
        $limit = max(1, min(5000, (int) $this->option('limit')));

        if (strlen($reason) < 10) {
            $this->error('Reason must be at least 10 characters (audit trail).');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if (! $actor) {
            $this->error('No actor user found. Pass --user-id= with an admin/procurement_manager id.');

            return self::FAILURE;
        }

        $query = $this->financeStagePoQuery()->orderByDesc('updated_at')->limit($limit);
        $mrfs = $query->get();

        if ($mrfs->isEmpty()) {
            $this->info('No Finance-stage purchase orders found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d Finance-stage PO/MRF record(s)%s (actor: %s #%d).',
            $mrfs->count(),
            $dryRun ? ' [DRY RUN]' : '',
            $actor->name ?? $actor->email ?? 'user',
            $actor->id
        ));

        $rows = $mrfs->map(fn (MRF $mrf) => [
            $mrf->effectivePoNumber() ?: ($mrf->po_number ?: '—'),
            $mrf->mrf_id,
            $mrf->title,
            $mrf->status,
            $mrf->current_stage,
            $mrf->workflow_state,
        ])->all();

        $this->table(
            ['PO', 'MRF', 'Title', 'Status', 'Stage', 'Workflow'],
            $rows
        );

        if ($dryRun) {
            $this->warn('Dry run only — no records were changed.');
            $this->line('Re-run without --dry-run (add --force to skip confirmation) to apply.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm(sprintf(
            'Force-close these %d Finance-stage PO(s) and associated MRF(s)?',
            $mrfs->count()
        ), false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $closed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($mrfs as $mrf) {
            $label = sprintf(
                '%s / %s',
                $mrf->effectivePoNumber() ?: ($mrf->po_number ?: 'no-po'),
                $mrf->mrf_id
            );

            if (($mrf->workflow_state ?? null) === WorkflowStateService::STATE_CLOSED) {
                $this->line("  skip (already closed): {$label}");
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use (
                    $mrf,
                    $actor,
                    $reason,
                    $workflowStateService
                ) {
                    $previousStatus = $mrf->status;
                    $previousWorkflow = $mrf->workflow_state;

                    if (! $workflowStateService->forceClose($mrf, $actor)) {
                        throw new \RuntimeException('Workflow transition to closed failed');
                    }

                    $mrf->forceFill([
                        'force_closed_at' => now(),
                        'force_closed_by' => $actor->id,
                        'force_close_reason' => $reason,
                        'force_close_previous_status' => $previousStatus,
                        'force_close_previous_workflow_state' => $previousWorkflow,
                    ])->save();

                    MRFApprovalHistory::record(
                        $mrf,
                        'force_closed',
                        'finance_ap_bypass',
                        $actor,
                        $reason
                    );

                    app(ScmAuditService::class)->record(
                        'force_close',
                        'MRF',
                        $mrf->mrf_id,
                        $actor,
                        'Bulk Force Close — Finance stage (Artisan)',
                        [
                            'previous_status' => $previousStatus,
                            'previous_workflow_state' => $previousWorkflow,
                            'new_status' => $mrf->fresh()->status,
                            'new_workflow_state' => WorkflowStateService::STATE_CLOSED,
                            'reason' => $reason,
                            'po_number' => $mrf->po_number,
                            'source' => 'artisan:scm:force-close-finance-pos',
                        ]
                    );
                });

                $mrf->refresh();

                if (! $mrf->vendor_fulfilment_recorded_at && ! $mrf->grn_completed) {
                    try {
                        app(VendorFulfilmentService::class)->recordCycleCompleted($mrf, false);
                    } catch (\Throwable $e) {
                        Log::warning('Vendor fulfilment update on bulk force close failed', [
                            'mrf_id' => $mrf->mrf_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $this->info("  closed: {$label}");
                $closed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  failed: {$label} — {$e->getMessage()}");
                Log::error('Bulk finance force-close failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Done. Closed: {$closed}, skipped: {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * PO list rows that show Status "Finance" in the Procurement UI:
     * MRF-backed POs where status and/or current_stage is finance,
     * and the record is not already closed/completed.
     */
    private function financeStagePoQuery()
    {
        return MRF::query()
            ->forPoList()
            ->where(function ($q) {
                $q->whereRaw('LOWER(TRIM(COALESCE(status, \'\'))) = ?', ['finance'])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(current_stage, \'\'))) = ?', ['finance']);
            })
            ->where(function ($q) {
                $q->whereNull('workflow_state')
                    ->orWhere('workflow_state', '!=', WorkflowStateService::STATE_CLOSED);
            })
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw('LOWER(TRIM(status)) NOT IN (?, ?)', ['completed', 'cancelled']);
            });
    }

    private function resolveActor(): ?User
    {
        $userId = $this->option('user-id');
        if ($userId !== null && $userId !== '') {
            return User::query()->find((int) $userId);
        }

        $admin = User::query()
            ->where(function ($q) {
                $q->where('is_admin', true)
                    ->orWhereRaw('LOWER(COALESCE(supply_chain_role, \'\')) IN (?, ?)', [
                        'admin',
                        'procurement_manager',
                    ]);
            })
            ->orderBy('id')
            ->first();

        return $admin;
    }
}
