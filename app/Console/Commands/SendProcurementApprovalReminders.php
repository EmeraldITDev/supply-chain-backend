<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Models\ProcurementReminderDispatch;
use App\Notifications\ProcurementActionReminderNotification;
use App\Services\Analytics\ReminderRecipientResolver;
use App\Services\MrfParallelFirstApprovalService;
use App\Services\WorkflowStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Send assignment-aware procurement approval reminders.
 *
 * Improvements over original:
 *   1. Recipient resolver finds the SPECIFIC individual responsible — not all
 *      users in a role. Assignment gaps are flagged, not broadcast.
 *   2. Deduplication via procurement_reminder_dispatches: one reminder per
 *      (user + record + state) per 24h window. Period_key = 'YYYY-MM-DD'.
 *   3. Deep links use config('app.frontend_url') + record route.
 *   4. Keeps existing 24h stale threshold and hourly schedule unchanged.
 *   5. Does NOT delete, update, or auto-complete any SCM records.
 */
class SendProcurementApprovalReminders extends Command
{
    protected $signature = 'scm:send-approval-reminders
                            {--hours=24 : Hours since last update before a reminder is sent}
                            {--dry-run : List recipients without sending}';

    protected $description = 'Notify users about pending / overdue SCM procurement approvals (additive; does not change records)';

    public function handle(ReminderRecipientResolver $resolver): int
    {
        $hours   = max(1, (int) $this->option('hours'));
        $cutoff  = now()->subHours($hours);
        $dryRun  = (bool) $this->option('dry-run');
        $periodKey = now()->format('Y-m-d'); // 24h dedup window

        $pendingStates = array_values(array_unique(array_filter([
            MrfParallelFirstApprovalService::STATE,
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW ?? 'supply_chain_director_review',
            WorkflowStateService::STATE_EXECUTIVE_REVIEW ?? 'executive_review',
            WorkflowStateService::STATE_PROCUREMENT_REVIEW ?? 'procurement_review',
            'lazarus_director_approval',
            WorkflowStateService::STATE_PENDING_SCD_SIGNATURE ?? 'pending_scd_signature',
            'vendor_selection_pending',
            'pending',
        ])));

        $mrfs = MRF::query()
            ->whereIn('workflow_state', $pendingStates)
            ->where('updated_at', '<=', $cutoff)
            ->whereNotIn('workflow_state', [WorkflowStateService::STATE_CLOSED ?? 'closed'])
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereRaw('LOWER(status) NOT IN (?, ?, ?)', ['completed', 'rejected', 'cancelled']);
            })
            ->orderBy('updated_at')
            ->limit(200)
            ->get();

        $sent  = 0;
        $gaps  = 0;
        $dedup = 0;

        foreach ($mrfs as $mrf) {
            $state        = (string) ($mrf->workflow_state ?? '');
            $hoursPending = (int) $mrf->updated_at->diffInHours(now());

            $resolution = $resolver->resolveForMrf($mrf);

            if ($resolution['assignment_gap']) {
                $gaps++;
                $this->warn(sprintf(
                    'ASSIGNMENT_GAP  %s [%s] — %s',
                    $mrf->mrf_id,
                    $state,
                    $resolution['reason'],
                ));
                if (! $dryRun) {
                    ProcurementReminderDispatch::markAssignmentGap(
                        MRF::class,
                        $mrf->id,
                        $state,
                        $periodKey,
                    );
                }

                continue;
            }

            foreach ($resolution['recipients'] as $recipient) {
                // Deduplication check
                if (ProcurementReminderDispatch::alreadySent(
                    $recipient->id,
                    MRF::class,
                    $mrf->id,
                    $state,
                    $periodKey,
                )) {
                    $dedup++;
                    if ($dryRun) {
                        $this->line(sprintf(
                            'DEDUP_SKIP  %s → %s (%s)',
                            $mrf->mrf_id,
                            $recipient->email,
                            $periodKey,
                        ));
                    }

                    continue;
                }

                $this->line(sprintf(
                    '%s [%s] → %s <%s>, pending ~%dh',
                    $mrf->mrf_id,
                    $state,
                    $recipient->name,
                    $recipient->email,
                    $hoursPending,
                ));

                if ($dryRun) {
                    continue;
                }

                try {
                    Notification::send(
                        collect([$recipient]),
                        new ProcurementActionReminderNotification($mrf, $hoursPending),
                    );

                    ProcurementReminderDispatch::markSent(
                        $recipient->id,
                        MRF::class,
                        $mrf->id,
                        $state,
                        $periodKey,
                    );
                    $sent++;
                } catch (\Throwable $e) {
                    Log::error('Failed to send procurement reminder', [
                        'mrf_id'     => $mrf->mrf_id,
                        'recipient'  => $recipient->email,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        $summary = $dryRun
            ? "Dry run: {$mrfs->count()} MRF(s) found. Gaps: {$gaps}. Would dedup: {$dedup}."
            : "Sent {$sent} reminder(s) for {$mrfs->count()} MRF(s). Gaps: {$gaps}. Deduped: {$dedup}.";

        $this->info($summary);

        return self::SUCCESS;
    }
}
