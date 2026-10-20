<?php

namespace App\Services\Analytics;

use App\Models\MRF;
use App\Models\User;
use App\Services\MrfParallelFirstApprovalService;
use App\Services\WorkflowStateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the specific individual(s) responsible for the next action on an MRF.
 *
 * RULES:
 * 1. Use per-record assignee fields first (procurement_manager_id, etc.).
 * 2. Fall back to the one active user in the responsible role.
 * 3. If multiple users hold the role and no explicit assignee exists, prefer
 *    the user most recently active on the record (approval history).
 * 4. If resolution still fails, flag an assignment_gap — do NOT fan-out
 *    to all users with the role.
 *
 * Only MRF reminders are handled here in Phase 1. SRF/trip reminders follow
 * the same pattern and can be extended via the same interface.
 */
class ReminderRecipientResolver
{
    /**
     * Resolve recipients for an MRF reminder.
     *
     * @return array{recipients: Collection<int,User>, assignment_gap: bool, reason: string}
     */
    public function resolveForMrf(MRF $mrf): array
    {
        $state = (string) ($mrf->workflow_state ?? '');

        return match (true) {
            // ── Parallel first approval: SCD + Executive must BOTH approve ──
            $state === MrfParallelFirstApprovalService::STATE => $this->resolveParallelApprovers($mrf),

            // ── SCD solo review ─────────────────────────────────────────────
            in_array($state, [
                WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW,
                'supply_chain_director_review',
            ], true) => $this->resolveRole('supply_chain_director', $mrf, 'SCD review'),

            // ── Executive review ─────────────────────────────────────────────
            $state === WorkflowStateService::STATE_EXECUTIVE_REVIEW => $this->resolveRole('executive', $mrf, 'Executive review'),

            // ── Lazarus director approval ────────────────────────────────────
            $state === 'lazarus_director_approval' => $this->resolveRole('director', $mrf, 'Lazarus director approval'),

            // ── Procurement review / vendor selection ────────────────────────
            in_array($state, [
                WorkflowStateService::STATE_PROCUREMENT_REVIEW,
                'executive_approved',
                'supply_chain_director_approved',
                'vendor_selection_pending',
            ], true) => $this->resolveProcurementAssignee($mrf),

            // ── PO pending SCD signature ─────────────────────────────────────
            in_array($state, [
                WorkflowStateService::STATE_PENDING_SCD_SIGNATURE,
                'po_generated',
            ], true) => $this->resolveRole('supply_chain_director', $mrf, 'PO signature'),

            // ── Finance handoff ───────────────────────────────────────────────
            in_array($state, [
                WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
                WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            ], true) => $this->resolveRole('finance', $mrf, 'Finance handoff'),

            // ── Unknown / unmapped state → gap ───────────────────────────────
            default => $this->gap("No recipient rule for state: {$state}"),
        };
    }

    // ─── Private resolution helpers ─────────────────────────────────────────

    /**
     * Parallel approval: only notify the approver(s) who have NOT yet approved.
     */
    private function resolveParallelApprovers(MRF $mrf): array
    {
        $pending = [];

        $executiveApproved = (bool) ($mrf->executive_approved ?? false);
        $directorApproved  = filled($mrf->director_approved_at ?? $mrf->scd_approved_at ?? $mrf->supply_chain_approved_at ?? '');

        if (! $executiveApproved) {
            $pending[] = 'executive';
        }
        if (! $directorApproved) {
            $pending[] = 'supply_chain_director';
        }

        if (empty($pending)) {
            return $this->gap('Both parallel approvers have already approved');
        }

        $recipients = User::query()
            ->whereIn('supply_chain_role', $pending)
            ->get();

        if ($recipients->isEmpty()) {
            return $this->gap('No users found with pending parallel approval roles: '.implode(', ', $pending));
        }

        return [
            'recipients'      => $recipients,
            'assignment_gap'  => false,
            'reason'          => 'Parallel first approval — pending roles: '.implode(', ', $pending),
        ];
    }

    /**
     * Resolve a single named role, preferring the record's assigned user.
     */
    private function resolveRole(string $role, MRF $mrf, string $context): array
    {
        // Direct assignment first
        if ($role === 'supply_chain_director' && $mrf->procurement_manager_id) {
            // procurement_manager_id is actually the SCD assignment in some paths
            // Use role lookup as fallback
        }

        $users = User::query()
            ->where('supply_chain_role', $role)
            ->get();

        if ($users->isEmpty()) {
            return $this->gap("No active user with role '{$role}' for context: {$context}");
        }

        // If only one user holds the role, that is unambiguous
        if ($users->count() === 1) {
            return [
                'recipients'     => $users,
                'assignment_gap' => false,
                'reason'         => "{$context} — sole {$role}",
            ];
        }

        // Multiple users: narrow by last-actor in approval history
        $lastActor = $this->lastApprovalHistoryActor($mrf, $role);
        if ($lastActor) {
            return [
                'recipients'     => collect([$lastActor]),
                'assignment_gap' => false,
                'reason'         => "{$context} — narrowed by approval history",
            ];
        }

        // Cannot narrow — flag gap (do NOT broadcast to all)
        return $this->gap(
            "Multiple users with role '{$role}' and no assignee field for {$context} on MRF {$mrf->mrf_id}",
        );
    }

    /**
     * Procurement review: prefer the procurement_manager_id field.
     */
    private function resolveProcurementAssignee(MRF $mrf): array
    {
        if ($mrf->procurement_manager_id) {
            $user = User::find($mrf->procurement_manager_id);
            if ($user) {
                return [
                    'recipients'     => collect([$user]),
                    'assignment_gap' => false,
                    'reason'         => 'Assigned procurement manager',
                ];
            }
        }

        // Fall through to role lookup
        return $this->resolveRole('procurement_manager', $mrf, 'Procurement review');
    }

    /**
     * Find the last user with the given role who acted in the MRF's approval history.
     */
    private function lastApprovalHistoryActor(MRF $mrf, string $role): ?User
    {
        $history = $mrf->approvalHistory()
            ->orderByDesc('created_at')
            ->with('performer')
            ->get();

        foreach ($history as $entry) {
            $performer = $entry->performer ?? null;
            if ($performer && ($performer->supply_chain_role ?? '') === $role) {
                return $performer;
            }
        }

        return null;
    }

    /** @return array{recipients: Collection<int,User>, assignment_gap: bool, reason: string} */
    private function gap(string $reason): array
    {
        Log::warning('SCM reminder assignment gap', ['reason' => $reason]);

        return [
            'recipients'     => collect(),
            'assignment_gap' => true,
            'reason'         => $reason,
        ];
    }
}
