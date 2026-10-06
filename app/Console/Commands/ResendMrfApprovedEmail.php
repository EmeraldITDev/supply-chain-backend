<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkflowNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification as DatabaseNotifications;

class ResendMrfApprovedEmail extends Command
{
    protected $signature = 'mrf:resend-approved-email
                            {mrf_id : MRF id or formatted id (e.g. MRF-EMERALD-IT-EQP-2026-181)}
                            {--dry-run : Only verify approval + recipient; do not send}
                            {--sync : Send immediately (bypass queue)}';

    protected $description = 'Verify SCD/executive MRF approval and resend the requester "MRF Approved" email';

    public function handle(
        NotificationService $notificationService,
        WorkflowNotificationService $workflowNotificationService,
    ): int {
        $id = trim((string) $this->argument('mrf_id'));
        $mrf = MRF::query()
            ->where('mrf_id', $id)
            ->orWhere('formatted_id', $id)
            ->with(['requester:id,name,email,supply_chain_role'])
            ->first();

        if (! $mrf) {
            $this->error("MRF not found: {$id}");

            return self::FAILURE;
        }

        $scdAt = $mrf->scd_approved_at ?? $mrf->director_approved_at ?? $mrf->supply_chain_approved_at;
        $scdBy = $mrf->director_approved_by
            ?? $mrf->scd_approved_by
            ?? $mrf->supply_chain_approved_by
            ?? null;
        $approved = (bool) $scdAt
            || (bool) $mrf->executive_approved
            || in_array((string) $mrf->workflow_state, [
                'supply_chain_director_approved',
                'procurement_review',
                'procurement_approved',
                'executive_approved',
                'rfq_issued',
            ], true);

        $this->info('MRF found');
        $this->table(
            ['Field', 'Value'],
            [
                ['mrf_id', $mrf->mrf_id],
                ['formatted_id', $mrf->formatted_id],
                ['title', $mrf->title],
                ['status', $mrf->status],
                ['workflow_state', $mrf->workflow_state],
                ['current_stage', $mrf->current_stage],
                ['SCD approved at', $scdAt?->toDateTimeString() ?? '—'],
                ['SCD / director approved by', $scdBy ?? '—'],
                ['executive_approved', $mrf->executive_approved ? 'yes' : 'no'],
                ['requester_name', $mrf->requester_name ?? '—'],
                ['requester user', $mrf->requester?->name ?? '—'],
                ['requester email', $mrf->requester?->email ?? 'MISSING'],
                ['email subject', 'MRF Approved - '.$mrf->mrf_id],
            ]
        );

        if (! $approved && ! $scdAt) {
            $this->error('This MRF does not look SCD/executive-approved. Refusing to send.');

            return self::FAILURE;
        }

        if (! $mrf->requester || ! filter_var($mrf->requester->email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Requester has no valid email — cannot send approval notification.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run only — no email sent.');

            return self::SUCCESS;
        }

        $approver = $this->resolveApprover($scdBy);
        $remarks = 'Resent approval notification';

        if ($this->option('sync')) {
            // Force synchronous delivery for the Laravel Notification mail channel.
            DatabaseNotifications::sendNow(
                $mrf->requester,
                new \App\Notifications\MRFApprovedNotification($mrf, $approver->name, $remarks)
            );
            $this->info('Sent MRFApprovedNotification (sync) to '.$mrf->requester->email);
        } else {
            $notificationService->notifyMRFApproved($mrf, $approver, $remarks);
            $this->info('Queued/sent MRFApprovedNotification to '.$mrf->requester->email);
        }

        // Also fire the dedicated mailable path used by some workflows.
        try {
            $workflowNotificationService->notifyMRFApproved($mrf);
            $this->info('Queued/sent MRFApprovedMail to '.$mrf->requester->email);
        } catch (\Throwable $e) {
            $this->warn('Workflow mailable path failed: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('Done. Ask the requester to check inbox + spam for:');
        $this->line('  Subject: MRF Approved - '.$mrf->mrf_id);

        return self::SUCCESS;
    }

    private function resolveApprover(?string $approvedByName): User
    {
        if ($approvedByName) {
            $byName = User::query()
                ->whereRaw('LOWER(name) = ?', [strtolower(trim($approvedByName))])
                ->first();
            if ($byName) {
                return $byName;
            }
        }

        return User::query()
            ->whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain', 'admin'])
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first()
            ?? new User(['name' => $approvedByName ?: 'Supply Chain Director']);
    }
}
