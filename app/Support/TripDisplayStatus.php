<?php

namespace App\Support;

use App\Models\Logistics\Trip;

final class TripDisplayStatus
{
    public static function resolve(Trip $trip, ?Trip $linkedTrip = null): string
    {
        if (strtolower((string) $trip->status) === Trip::STATUS_CANCELLED) {
            return 'rejected';
        }

        $approvalStatus = strtolower((string) ($trip->approval_status ?? ''));
        if ($approvalStatus === 'revision_required') {
            return 'revision_required';
        }

        if ($trip->workflow_stage === Trip::WORKFLOW_CHANGES_REQUESTED) {
            return 'changes_requested';
        }

        if ($trip->workflow_stage === Trip::WORKFLOW_DIRECTOR_REVIEW) {
            return 'under_review';
        }

        if ($trip->workflow_stage === Trip::WORKFLOW_DIRECTOR_APPROVED) {
            return 'approved';
        }

        if ($linkedTrip) {
            return match (strtolower((string) $linkedTrip->status)) {
                Trip::STATUS_COMPLETED, Trip::STATUS_CLOSED => 'completed',
                Trip::STATUS_IN_PROGRESS => 'in_progress',
                Trip::STATUS_CANCELLED => 'rejected',
                default => 'approved',
            };
        }

        if (self::isTripRequestDraft($trip)) {
            return 'draft';
        }

        if (strtolower((string) $trip->status) === Trip::STATUS_SUBMITTED) {
            return 'pending';
        }

        return strtolower((string) ($trip->status ?: 'unknown'));
    }

    public static function label(string $displayStatus): string
    {
        return match ($displayStatus) {
            'pending' => 'Submitted',
            'under_review' => 'Under Review',
            'changes_requested' => 'Changes Requested',
            'revision_required' => 'Revision Required',
            'approved' => 'Approved',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'rejected' => 'Rejected',
            'draft' => 'Draft',
            default => ucwords(str_replace('_', ' ', $displayStatus)),
        };
    }

    public static function isTripRequest(Trip $trip): bool
    {
        return str_starts_with((string) $trip->trip_code, 'TRQ-');
    }

    public static function isLogisticsTrip(Trip $trip): bool
    {
        return str_starts_with((string) $trip->trip_code, 'TRIP-');
    }

    public static function isTripRequestDraft(Trip $trip): bool
    {
        if (! self::isTripRequest($trip)) {
            return false;
        }

        return strtolower((string) $trip->status) === Trip::STATUS_DRAFT
            && $trip->workflow_stage === Trip::WORKFLOW_TRIP_REQUEST;
    }
}
