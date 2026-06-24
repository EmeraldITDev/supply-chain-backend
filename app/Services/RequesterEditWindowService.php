<?php

namespace App\Services;

use App\Models\Logistics\Trip;
use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\SRF;
use App\Models\User;
use App\Support\DepartmentMatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class RequesterEditWindowService
{
    public const WINDOW_SECONDS = 172800;

    public const CODE_WINDOW_EXPIRED = 'REQUESTER_EDIT_WINDOW_EXPIRED';

    public const CODE_NOT_AUTHORIZED = 'REQUESTER_EDIT_NOT_AUTHORIZED';

    public const CODE_WORKFLOW_LOCKED = 'REQUESTER_EDIT_WORKFLOW_LOCKED';

    /**
     * @return array{can_requester_edit: bool, requester_edit_expires_at: ?string}
     */
    public function metaForMrf(?User $user, MRF $mrf): array
    {
        return $this->buildMeta($user, $mrf->created_at, fn () => $this->canRequesterEditMrf($user, $mrf));
    }

    /**
     * @return array{can_requester_edit: bool, requester_edit_expires_at: ?string}
     */
    public function metaForSrf(?User $user, SRF $srf): array
    {
        return $this->buildMeta($user, $srf->created_at, fn () => $this->canRequesterEditSrf($user, $srf));
    }

    /**
     * @return array{can_requester_edit: bool, requester_edit_expires_at: ?string}
     */
    public function metaForTrip(?User $user, Trip $trip): array
    {
        return $this->buildMeta($user, $trip->created_at, fn () => $this->canRequesterEditTrip($user, $trip));
    }

    public function canRequesterEditMrf(?User $user, MRF $mrf): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isAuthorizedMrfEditor($user, $mrf)
            && $this->isWithinWindow($mrf->created_at)
            && $this->isMrfWorkflowEditable($mrf);
    }

    public function canRequesterEditSrf(?User $user, SRF $srf): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isAuthorizedSrfEditor($user, $srf)
            && $this->isWithinWindow($srf->created_at)
            && $this->isSrfWorkflowEditable($srf);
    }

    public function canRequesterEditTrip(?User $user, Trip $trip): bool
    {
        if (! $user) {
            return false;
        }

        if (! str_starts_with((string) $trip->trip_code, 'TRQ-')) {
            return false;
        }

        return $this->isAuthorizedTripEditor($user, $trip)
            && $this->isWithinWindow($trip->created_at)
            && $this->isTripWorkflowEditable($trip);
    }

    /**
     * @return array{allowed: bool, code: ?string, message: ?string}
     */
    public function evaluateMrfEdit(User $user, MRF $mrf): array
    {
        return $this->evaluate(
            authorized: $this->isAuthorizedMrfEditor($user, $mrf),
            withinWindow: $this->isWithinWindow($mrf->created_at),
            workflowEditable: $this->isMrfWorkflowEditable($mrf),
            entityLabel: 'MRF'
        );
    }

    /**
     * @return array{allowed: bool, code: ?string, message: ?string}
     */
    public function evaluateSrfEdit(User $user, SRF $srf): array
    {
        return $this->evaluate(
            authorized: $this->isAuthorizedSrfEditor($user, $srf),
            withinWindow: $this->isWithinWindow($srf->created_at),
            workflowEditable: $this->isSrfWorkflowEditable($srf),
            entityLabel: 'SRF'
        );
    }

    /**
     * @return array{allowed: bool, code: ?string, message: ?string}
     */
    public function evaluateTripEdit(User $user, Trip $trip): array
    {
        if (! str_starts_with((string) $trip->trip_code, 'TRQ-')) {
            return [
                'allowed' => false,
                'code' => self::CODE_WORKFLOW_LOCKED,
                'message' => 'This trip request cannot be edited.',
            ];
        }

        return $this->evaluate(
            authorized: $this->isAuthorizedTripEditor($user, $trip),
            withinWindow: $this->isWithinWindow($trip->created_at),
            workflowEditable: $this->isTripWorkflowEditable($trip),
            entityLabel: 'trip request'
        );
    }

    public function expiresAt(?CarbonInterface $createdAt): ?string
    {
        if (! $createdAt) {
            return null;
        }

        return Carbon::parse($createdAt)->addSeconds(self::WINDOW_SECONDS)->toIso8601String();
    }

    public function isAuthorizedMrfEditor(User $user, MRF $mrf): bool
    {
        return $this->isRequesterOrDepartmentCreator($user, (int) $mrf->requester_id, $mrf->department);
    }

    public function isAuthorizedSrfEditor(User $user, SRF $srf): bool
    {
        return $this->isRequesterOrDepartmentCreator($user, (int) $srf->requester_id, $srf->department);
    }

    public function isAuthorizedTripEditor(User $user, Trip $trip): bool
    {
        return (int) $trip->created_by === (int) $user->id;
    }

    public function isMrfWorkflowEditable(MRF $mrf): bool
    {
        $status = strtolower(trim((string) ($mrf->status ?? '')));

        if (in_array($status, ['rejected', 'completed', 'cancelled', 'closed'], true)) {
            return false;
        }

        if (in_array($status, ['awaiting_scd_signature'], true)) {
            return false;
        }

        if ($mrf->grn_requested || $mrf->grn_completed) {
            return false;
        }

        if ($mrf->po_generated_at !== null) {
            return false;
        }

        $workflow = strtolower(trim((string) ($mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED)));

        $lockedStates = [
            WorkflowStateService::STATE_PO_GENERATED,
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_CLOSED,
            WorkflowStateService::STATE_PAYMENT_PROCESSED,
            WorkflowStateService::STATE_GRN_REQUESTED,
            WorkflowStateService::STATE_GRN_COMPLETED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REJECTED,
            WorkflowStateService::STATE_EXECUTIVE_REJECTED,
        ];

        return ! in_array($workflow, $lockedStates, true);
    }

    public function isSrfWorkflowEditable(SRF $srf): bool
    {
        $status = strtolower(trim((string) ($srf->status ?? '')));

        if (in_array($status, ['rejected', 'completed', 'cancelled', 'closed'], true)) {
            return false;
        }

        $stage = strtolower(trim((string) ($srf->current_stage ?? '')));

        $lockedStages = [
            'po_generated',
            'closed',
            'rejected',
            'grn_requested',
            'grn_completed',
            'completed',
        ];

        return ! in_array($stage, $lockedStages, true);
    }

    public function isTripWorkflowEditable(Trip $trip): bool
    {
        $status = strtolower(trim((string) ($trip->status ?? '')));

        if (in_array($status, [Trip::STATUS_CANCELLED, Trip::STATUS_COMPLETED, Trip::STATUS_CLOSED], true)) {
            return false;
        }

        if ($trip->logisticsTripIdFromMetadata() !== null) {
            return false;
        }

        $metadata = is_array($trip->metadata) ? $trip->metadata : [];

        return empty($metadata['logistics_trip_id']) && empty($metadata['logisticsTripId']);
    }

    public function isWithinWindow(?CarbonInterface $createdAt): bool
    {
        if (! $createdAt) {
            return false;
        }

        return Carbon::parse($createdAt)->addSeconds(self::WINDOW_SECONDS)->isFuture();
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function recordMrfEdit(MRF $mrf, User $user, ?string $remarks = null, array $changedFields = []): void
    {
        $entry = $this->historyEntry($user, $remarks, $changedFields);
        $history = is_array($mrf->approval_history) ? $mrf->approval_history : [];
        $history[] = $entry;
        $mrf->approval_history = $history;
        $mrf->save();

        MRFApprovalHistory::record(
            $mrf,
            'requester_edit',
            (string) ($mrf->current_stage ?? $mrf->workflow_state ?? 'requester_edit'),
            $user,
            $remarks ?? $this->summarizeChangedFields($changedFields)
        );
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function recordSrfEdit(SRF $srf, User $user, ?string $remarks = null, array $changedFields = []): void
    {
        $entry = $this->historyEntry($user, $remarks, $changedFields);
        $history = is_array($srf->approval_history) ? $srf->approval_history : [];
        $history[] = $entry;
        $srf->approval_history = $history;
        $srf->save();
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function recordTripEdit(Trip $trip, User $user, ?string $remarks = null, array $changedFields = []): void
    {
        $entry = $this->historyEntry($user, $remarks, $changedFields);
        $metadata = is_array($trip->metadata) ? $trip->metadata : [];
        $history = is_array($metadata['requester_edit_history'] ?? null) ? $metadata['requester_edit_history'] : [];
        $history[] = $entry;
        $metadata['requester_edit_history'] = $history;
        $trip->metadata = $metadata;
        $trip->save();
    }

    /**
     * @param  list<string>  $changedFields
     * @return array{action: string, user_id: int, user_name: string, timestamp: string, remarks: ?string}
     */
    public function historyEntry(User $user, ?string $remarks = null, array $changedFields = []): array
    {
        return [
            'action' => 'requester_edit',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'timestamp' => now()->toIso8601String(),
            'remarks' => $remarks ?? $this->summarizeChangedFields($changedFields),
        ];
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function summarizeChangedFields(array $changedFields): ?string
    {
        $changedFields = array_values(array_filter($changedFields, static fn ($f) => is_string($f) && $f !== ''));

        if ($changedFields === []) {
            return null;
        }

        return 'Updated ' . implode(', ', $changedFields);
    }

    /**
     * @return list<string>
     */
    public function detectChangedFieldLabels(array $before, array $after, array $fieldMap): array
    {
        $changed = [];

        foreach ($fieldMap as $label => $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old != $new) {
                $changed[] = $label;
            }
        }

        return $changed;
    }

    private function isRequesterOrDepartmentCreator(User $user, int $requesterId, ?string $department): bool
    {
        if ($requesterId > 0 && (int) $user->id === $requesterId) {
            return true;
        }

        if (($user->designated_requisition_creator ?? false) !== true) {
            return false;
        }

        return DepartmentMatcher::matches($user->department, $department);
    }

    /**
     * @return array{can_requester_edit: bool, requester_edit_expires_at: ?string}
     */
    private function buildMeta(?User $user, ?CarbonInterface $createdAt, callable $canEdit): array
    {
        return [
            'can_requester_edit' => $user ? (bool) $canEdit() : false,
            'requester_edit_expires_at' => $this->expiresAt($createdAt),
        ];
    }

    /**
     * @return array{allowed: bool, code: ?string, message: ?string}
     */
    private function evaluate(bool $authorized, bool $withinWindow, bool $workflowEditable, string $entityLabel): array
    {
        if (! $authorized) {
            return [
                'allowed' => false,
                'code' => self::CODE_NOT_AUTHORIZED,
                'message' => "You are not authorized to edit this {$entityLabel}.",
            ];
        }

        if (! $workflowEditable) {
            return [
                'allowed' => false,
                'code' => self::CODE_WORKFLOW_LOCKED,
                'message' => "This {$entityLabel} can no longer be edited in its current workflow state.",
            ];
        }

        if (! $withinWindow) {
            return [
                'allowed' => false,
                'code' => self::CODE_WINDOW_EXPIRED,
                'message' => 'The 48-hour requester edit window has expired.',
            ];
        }

        return [
            'allowed' => true,
            'code' => null,
            'message' => null,
        ];
    }
}
