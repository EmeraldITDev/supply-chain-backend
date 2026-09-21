<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Models\User;
use App\Notifications\ProcurementActionReminderNotification;
use App\Services\MrfParallelFirstApprovalService;
use App\Services\WorkflowStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendProcurementApprovalReminders extends Command
{
    protected $signature = 'scm:send-approval-reminders
                            {--hours=24 : Hours since last update before a reminder is sent}
                            {--dry-run : List recipients without sending}';

    protected $description = 'Notify users about pending / overdue SCM procurement approvals (additive; does not change records)';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        $pendingStates = [
            MrfParallelFirstApprovalService::STATE,
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW ?? 'supply_chain_director_review',
            'executive_review',
            'procurement_review',
            'lazarus_director_approval',
            WorkflowStateService::STATE_PENDING_SCD_SIGNATURE ?? 'pending_scd_signature',
            'vendor_selection_pending',
            'pending',
        ];

        $mrfs = MRF::query()
            ->whereIn('workflow_state', array_values(array_unique(array_filter($pendingStates))))
            ->where('updated_at', '<=', $cutoff)
            ->whereNotIn('workflow_state', [WorkflowStateService::STATE_CLOSED])
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw('LOWER(status) NOT IN (?, ?, ?)', ['completed', 'rejected', 'cancelled']);
            })
            ->orderBy('updated_at')
            ->limit(200)
            ->get();

        $sent = 0;

        foreach ($mrfs as $mrf) {
            $recipients = $this->recipientsFor($mrf);
            if ($recipients->isEmpty()) {
                continue;
            }

            $hoursPending = (int) $mrf->updated_at->diffInHours(now());
            $this->line(sprintf(
                '%s [%s] → %d recipient(s), pending ~%dh',
                $mrf->mrf_id,
                $mrf->workflow_state,
                $recipients->count(),
                $hoursPending
            ));

            if ($dryRun) {
                continue;
            }

            Notification::send(
                $recipients,
                new ProcurementActionReminderNotification($mrf, $hoursPending)
            );
            $sent += $recipients->count();
        }

        $this->info($dryRun
            ? "Dry run complete. {$mrfs->count()} overdue MRF(s) found."
            : "Sent {$sent} reminder notification(s) for {$mrfs->count()} MRF(s).");

        return self::SUCCESS;
    }

    private function recipientsFor(MRF $mrf)
    {
        $state = (string) ($mrf->workflow_state ?? '');
        $roles = match (true) {
            in_array($state, [MrfParallelFirstApprovalService::STATE, 'supply_chain_director_review'], true) => [
                'supply_chain_director', 'executive', 'admin',
            ],
            $state === 'executive_review' => ['executive', 'admin'],
            $state === 'lazarus_director_approval' => ['director', 'admin'],
            in_array($state, ['procurement_review', 'executive_approved', 'supply_chain_director_approved'], true) => [
                'procurement_manager', 'procurement', 'admin',
            ],
            in_array($state, ['pending_scd_signature', 'po_generated'], true) => [
                'supply_chain_director', 'supply_chain', 'admin',
            ],
            default => ['procurement_manager', 'admin'],
        };

        return User::query()
            ->whereIn('supply_chain_role', $roles)
            ->get();
    }
}
