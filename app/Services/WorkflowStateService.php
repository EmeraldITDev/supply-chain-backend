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
    const STATE_EXECUTIVE_REVIEW = 'executive_review';
    const STATE_EXECUTIVE_APPROVED = 'executive_approved';
    const STATE_EXECUTIVE_REJECTED = 'executive_rejected';
    const STATE_PROCUREMENT_REVIEW = 'procurement_review';
    const STATE_VENDOR_SELECTED = 'vendor_selected';
    const STATE_INVOICE_RECEIVED = 'invoice_received';
    const STATE_INVOICE_APPROVED = 'invoice_approved';
    const STATE_PO_GENERATED = 'po_generated';
    const STATE_PO_SIGNED = 'po_signed';
    const STATE_PAYMENT_PROCESSED = 'payment_processed';
    const STATE_GRN_REQUESTED = 'grn_requested';
    const STATE_GRN_COMPLETED = 'grn_completed';
    const STATE_CLOSED = 'closed';

    /**
     * Valid state transitions
     */
    private array $validTransitions = [
        self::STATE_MRF_CREATED => [self::STATE_EXECUTIVE_REVIEW],
        self::STATE_EXECUTIVE_REVIEW => [self::STATE_EXECUTIVE_APPROVED, self::STATE_EXECUTIVE_REJECTED],
        self::STATE_EXECUTIVE_APPROVED => [self::STATE_PROCUREMENT_REVIEW],
        self::STATE_EXECUTIVE_REJECTED => [], // Terminal state (can resubmit by creating new MRF)
        self::STATE_PROCUREMENT_REVIEW => [self::STATE_VENDOR_SELECTED],
        self::STATE_VENDOR_SELECTED => [self::STATE_INVOICE_RECEIVED],
        self::STATE_INVOICE_RECEIVED => [self::STATE_INVOICE_APPROVED],
        self::STATE_INVOICE_APPROVED => [self::STATE_PO_GENERATED],
        self::STATE_PO_GENERATED => [self::STATE_PO_SIGNED],
        self::STATE_PO_SIGNED => [self::STATE_PAYMENT_PROCESSED],
        self::STATE_PAYMENT_PROCESSED => [self::STATE_GRN_REQUESTED],
        self::STATE_GRN_REQUESTED => [self::STATE_GRN_COMPLETED],
        self::STATE_GRN_COMPLETED => [self::STATE_CLOSED],
        self::STATE_CLOSED => [], // Terminal state
    ];

    /**
     * Role permissions for state transitions
     */
    private array $rolePermissions = [
        'staff' => [
            self::STATE_MRF_CREATED => ['create'],
        ],
        'executive' => [
            self::STATE_EXECUTIVE_REVIEW => ['approve', 'reject'],
        ],
        'procurement' => [
            self::STATE_EXECUTIVE_APPROVED => ['select_vendors'],
            self::STATE_INVOICE_RECEIVED => ['approve_invoice'],
            self::STATE_INVOICE_APPROVED => ['generate_po'],
            self::STATE_GRN_REQUESTED => ['upload_grn'],
        ],
        'supply_chain_director' => [
            self::STATE_VENDOR_SELECTED => ['approve_vendor'],
        ],
        'finance' => [
            self::STATE_PO_SIGNED => ['process_payment'],
            self::STATE_PAYMENT_PROCESSED => ['request_grn'],
            self::STATE_GRN_COMPLETED => ['review_grn'],
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
            self::STATE_MRF_CREATED . '->' . self::STATE_EXECUTIVE_REVIEW => 'staff',
            self::STATE_EXECUTIVE_REVIEW . '->' . self::STATE_EXECUTIVE_APPROVED => 'executive',
            self::STATE_EXECUTIVE_REVIEW . '->' . self::STATE_EXECUTIVE_REJECTED => 'executive',
            self::STATE_EXECUTIVE_APPROVED . '->' . self::STATE_PROCUREMENT_REVIEW => 'system',
            self::STATE_PROCUREMENT_REVIEW . '->' . self::STATE_VENDOR_SELECTED => 'procurement',
            self::STATE_VENDOR_SELECTED . '->' . self::STATE_INVOICE_RECEIVED => 'vendor',
            self::STATE_INVOICE_RECEIVED . '->' . self::STATE_INVOICE_APPROVED => 'procurement',
            self::STATE_INVOICE_APPROVED . '->' . self::STATE_PO_GENERATED => 'procurement',
            self::STATE_PO_GENERATED . '->' . self::STATE_PO_SIGNED => 'system',
            self::STATE_PO_SIGNED . '->' . self::STATE_PAYMENT_PROCESSED => 'finance',
            self::STATE_PAYMENT_PROCESSED . '->' . self::STATE_GRN_REQUESTED => 'finance',
            self::STATE_GRN_REQUESTED . '->' . self::STATE_GRN_COMPLETED => 'procurement',
            self::STATE_GRN_COMPLETED . '->' . self::STATE_CLOSED => 'finance',
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
            self::STATE_EXECUTIVE_REVIEW => 'Executive Review',
            self::STATE_EXECUTIVE_APPROVED => 'Executive Approved',
            self::STATE_EXECUTIVE_REJECTED => 'Executive Rejected',
            self::STATE_PROCUREMENT_REVIEW => 'Procurement Review',
            self::STATE_VENDOR_SELECTED => 'Vendor Selected',
            self::STATE_INVOICE_RECEIVED => 'Invoice Received',
            self::STATE_INVOICE_APPROVED => 'Invoice Approved',
            self::STATE_PO_GENERATED => 'PO Generated',
            self::STATE_PO_SIGNED => 'PO Signed',
            self::STATE_PAYMENT_PROCESSED => 'Payment Processed',
            self::STATE_GRN_REQUESTED => 'GRN Requested',
            self::STATE_GRN_COMPLETED => 'GRN Completed',
            self::STATE_CLOSED => 'Closed',
        ];

        return $displayNames[$state] ?? $state;
    }
}
