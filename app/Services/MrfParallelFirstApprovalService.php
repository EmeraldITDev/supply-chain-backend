<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MrfParallelFirstApprovalService
{
    public const STATE = 'parallel_first_approval';

    public const ROLE_EXECUTIVE = 'executive';

    public const ROLE_SUPPLY_CHAIN_DIRECTOR = 'supply_chain_director';

    /**
     * @var list<string>
     */
    private const POST_FIRST_APPROVAL_STATES = [
        'executive_approved',
        'supply_chain_director_approved',
        'lazarus_director_approval',
        'procurement_review',
        'procurement_approved',
        'rfq_issued',
        'quotations_received',
        'quotations_evaluated',
        'vendor_selected',
        'invoice_received',
        'invoice_approved',
        'po_generated',
        'po_signed',
        'delivery_confirmation_pending',
        'delivery_confirmation_complete',
        'finance_handoff_pending',
        'finance_in_review',
        'milestone_payment_in_progress',
        'financially_complete',
        'operationally_complete',
        'closed',
        'payment_processed',
        'grn_requested',
        'grn_completed',
    ];

    public function isParallelPending(MRF $mrf): bool
    {
        return ($mrf->workflow_state ?? '') === self::STATE;
    }

    public function hasBothParallelApprovals(MRF $mrf): bool
    {
        $executiveApproved = (bool) ($mrf->executive_approved ?? false);
        $directorApproved = filled($mrf->director_approved_at ?? $mrf->scd_approved_at ?? $mrf->supply_chain_approved_at);

        return $executiveApproved && $directorApproved;
    }

    public function isAlreadyApproved(MRF $mrf): bool
    {
        if ($this->isParallelPending($mrf)) {
            return false;
        }

        if (filled($mrf->first_approval_by_role)) {
            return true;
        }

        return in_array($mrf->workflow_state, self::POST_FIRST_APPROVAL_STATES, true);
    }

    public function canConsumeFirstApproval(MRF $mrf, string $role): bool
    {
        if ($this->isAlreadyApproved($mrf)) {
            return false;
        }

        if ($this->isParallelPending($mrf)) {
            if ($this->hasBothParallelApprovals($mrf)) {
                return false;
            }

            return match ($role) {
                self::ROLE_EXECUTIVE => ! (bool) ($mrf->executive_approved ?? false),
                self::ROLE_SUPPLY_CHAIN_DIRECTOR => ! filled($mrf->director_approved_at ?? $mrf->scd_approved_at ?? $mrf->supply_chain_approved_at),
                default => false,
            };
        }

        return in_array($mrf->workflow_state, ['supply_chain_director_review', 'executive_review'], true)
            && in_array($role, [self::ROLE_EXECUTIVE, self::ROLE_SUPPLY_CHAIN_DIRECTOR], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveApprovalTransition(MRF $mrf, string $role, bool $approved): array
    {
        if (! $approved) {
            return [
                'status' => 'rejected',
                'current_stage' => 'rejected',
                'workflow_state' => $role === self::ROLE_EXECUTIVE
                    ? WorkflowStateService::STATE_EXECUTIVE_REJECTED
                    : WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REJECTED,
                'first_approval_by_role' => null,
            ];
        }

        if ($this->isParallelPending($mrf)) {
            // First-one-wins: whichever of SCD or Executive approves first
            // immediately moves the MRF to procurement review.
            // The other approver's action is no longer required.
            return [
                'status' => 'procurement_review',
                'current_stage' => 'procurement_review',
                'workflow_state' => WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
                'first_approval_by_role' => filled($mrf->first_approval_by_role)
                    ? $mrf->first_approval_by_role
                    : $role,
            ];
        }

        return [
            'status' => 'procurement_review',
            'current_stage' => 'procurement',
            'workflow_state' => $role === self::ROLE_EXECUTIVE
                ? WorkflowStateService::STATE_EXECUTIVE_APPROVED
                : WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
            'first_approval_by_role' => $role,
        ];
    }

    public function persistPartialApproval(MRF $mrf, string $role, array $attributes, ?User $user = null, ?string $remarks = null): MRF
    {
        $mrf->forceFill($attributes);
        $mrf->save();

        DB::table('mrf_approval_history')->insert([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'parallel_first_approval',
            'performed_by' => $user?->id ?? 0,
            'performer_name' => $user?->name ?? 'System',
            'performer_role' => $role,
            'remarks' => $remarks ?? 'Parallel approval recorded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $mrf;
    }

    public static function statusLabelForRole(?string $role): ?string
    {
        return match ($role) {
            self::ROLE_EXECUTIVE => 'Approved by Executive',
            self::ROLE_SUPPLY_CHAIN_DIRECTOR => 'Approved by Supply Chain Director',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function apiFields(MRF $mrf): array
    {
        $label = self::statusLabelForRole($mrf->first_approval_by_role);

        return [
            'first_approval_by_role' => $mrf->first_approval_by_role,
            'firstApprovalByRole' => $mrf->first_approval_by_role,
            'first_approval_status_label' => $label,
            'firstApprovalStatusLabel' => $label,
        ];
    }
}
