<?php

namespace App\Services;

use App\Models\MRF;
use Illuminate\Support\Facades\Log;

class WorkflowStateService
{
    /**
     * Valid workflow states
     */
    const STATE_MRF_CREATED = 'mrf_created';
    const STATE_MRF_APPROVED = 'mrf_approved';
    const STATE_MRF_REJECTED = 'mrf_rejected';
    const STATE_PO_GENERATED = 'po_generated';
    const STATE_PO_REVIEWED = 'po_reviewed';
    const STATE_PO_SIGNED = 'po_signed';
    const STATE_PO_REJECTED = 'po_rejected';
    const STATE_PAYMENT_PROCESSED = 'payment_processed';
    const STATE_GRN_REQUESTED = 'grn_requested';
    const STATE_GRN_COMPLETED = 'grn_completed';

    /**
     * Valid state transitions
     */
    private array $validTransitions = [
        self::STATE_MRF_CREATED => [self::STATE_MRF_APPROVED, self::STATE_MRF_REJECTED],
        self::STATE_MRF_APPROVED => [self::STATE_PO_GENERATED],
        self::STATE_MRF_REJECTED => [self::STATE_MRF_CREATED], // Resubmission
        self::STATE_PO_GENERATED => [self::STATE_PO_REVIEWED, self::STATE_PO_REJECTED],
        self::STATE_PO_REVIEWED => [self::STATE_PO_SIGNED, self::STATE_PO_REJECTED],
        self::STATE_PO_SIGNED => [self::STATE_PAYMENT_PROCESSED],
        self::STATE_PO_REJECTED => [self::STATE_PO_GENERATED], // Regeneration
        self::STATE_PAYMENT_PROCESSED => [self::STATE_GRN_REQUESTED],
        self::STATE_GRN_REQUESTED => [self::STATE_GRN_COMPLETED],
        self::STATE_GRN_COMPLETED => [], // Terminal state
    ];

    /**
     * Role permissions for state transitions
     */
    private array $rolePermissions = [
        'employee' => [
            self::STATE_MRF_CREATED => ['create'],
        ],
        'executive' => [
            self::STATE_MRF_CREATED => ['approve', 'reject'],
        ],
        'procurement' => [
            self::STATE_MRF_APPROVED => ['generate_po'],
            self::STATE_GRN_REQUESTED => ['complete_grn'],
        ],
        'supply_chain_director' => [
            self::STATE_PO_GENERATED => ['review', 'reject'],
            self::STATE_PO_REVIEWED => ['sign'],
        ],
        'finance' => [
            self::STATE_PO_SIGNED => ['process_payment'],
            self::STATE_PAYMENT_PROCESSED => ['request_grn'],
        ],
    ];

    /**
     * Check if a state transition is valid
     */
    public function canTransition(string $currentState, string $newState): bool
    {
        if (!isset($this->validTransitions[$currentState])) {
            return false;
        }

        return in_array($newState, $this->validTransitions[$currentState]);
    }

    /**
     * Check if a role can perform an action on a state
     */
    public function canPerformAction(string $role, string $state, string $action): bool
    {
        // Normalize role name
        $role = $this->normalizeRole($role);

        if (!isset($this->rolePermissions[$role])) {
            return false;
        }

        if (!isset($this->rolePermissions[$role][$state])) {
            return false;
        }

        return in_array($action, $this->rolePermissions[$role][$state]);
    }

    /**
     * Get valid next states for current state
     */
    public function getValidNextStates(string $currentState): array
    {
        return $this->validTransitions[$currentState] ?? [];
    }

    /**
     * Get required role for a state transition
     */
    public function getRequiredRoleForTransition(string $fromState, string $toState): ?string
    {
        $transitionMap = [
            self::STATE_MRF_CREATED . '->' . self::STATE_MRF_APPROVED => 'executive',
            self::STATE_MRF_CREATED . '->' . self::STATE_MRF_REJECTED => 'executive',
            self::STATE_MRF_APPROVED . '->' . self::STATE_PO_GENERATED => 'procurement',
            self::STATE_PO_GENERATED . '->' . self::STATE_PO_REVIEWED => 'supply_chain_director',
            self::STATE_PO_GENERATED . '->' . self::STATE_PO_REJECTED => 'supply_chain_director',
            self::STATE_PO_REVIEWED . '->' . self::STATE_PO_SIGNED => 'supply_chain_director',
            self::STATE_PO_REVIEWED . '->' . self::STATE_PO_REJECTED => 'supply_chain_director',
            self::STATE_PO_SIGNED . '->' . self::STATE_PAYMENT_PROCESSED => 'finance',
            self::STATE_PAYMENT_PROCESSED . '->' . self::STATE_GRN_REQUESTED => 'finance',
            self::STATE_GRN_REQUESTED . '->' . self::STATE_GRN_COMPLETED => 'procurement',
        ];

        $key = $fromState . '->' . $toState;
        return $transitionMap[$key] ?? null;
    }

    /**
     * Transition MRF to new state
     */
    public function transition(MRF $mrf, string $newState, $user): bool
    {
        $currentState = $mrf->workflow_state ?? self::STATE_MRF_CREATED;

        if (!$this->canTransition($currentState, $newState)) {
            Log::warning('Invalid state transition attempted', [
                'mrf_id' => $mrf->mrf_id,
                'current_state' => $currentState,
                'attempted_state' => $newState,
                'user_id' => $user->id,
            ]);
            return false;
        }

        $mrf->workflow_state = $newState;
        $mrf->save();

        Log::info('MRF state transitioned', [
            'mrf_id' => $mrf->mrf_id,
            'from_state' => $currentState,
            'to_state' => $newState,
            'user_id' => $user->id,
        ]);

        return true;
    }

    /**
     * Normalize role name
     */
    private function normalizeRole(string $role): string
    {
        $normalized = strtolower($role);
        
        // Map variations to standard roles
        $roleMap = [
            'procurement_manager' => 'procurement',
            'procurement manager' => 'procurement',
            'supply_chain' => 'supply_chain_director',
            'supply chain' => 'supply_chain_director',
            'supply chain director' => 'supply_chain_director',
            'finance_officer' => 'finance',
            'finance officer' => 'finance',
        ];

        return $roleMap[$normalized] ?? $normalized;
    }

    /**
     * Get state display name
     */
    public function getStateDisplayName(string $state): string
    {
        $displayNames = [
            self::STATE_MRF_CREATED => 'MRF Created',
            self::STATE_MRF_APPROVED => 'MRF Approved',
            self::STATE_MRF_REJECTED => 'MRF Rejected',
            self::STATE_PO_GENERATED => 'PO Generated',
            self::STATE_PO_REVIEWED => 'PO Reviewed',
            self::STATE_PO_SIGNED => 'PO Signed',
            self::STATE_PO_REJECTED => 'PO Rejected',
            self::STATE_PAYMENT_PROCESSED => 'Payment Processed',
            self::STATE_GRN_REQUESTED => 'GRN Requested',
            self::STATE_GRN_COMPLETED => 'GRN Completed',
        ];

        return $displayNames[$state] ?? $state;
    }
}
