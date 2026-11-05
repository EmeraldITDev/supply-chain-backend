<?php

namespace App\Services\Logistics;

use App\Mail\TripChangesRequestedMail;
use App\Mail\TripExternalPassengerConfirmedMail;
use App\Mail\TripRequestRejectedMail;
use App\Mail\TripRequestRequesterUpdatedMail;
use App\Mail\TripRequestDirectorApprovedMail;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Notifications\LogisticsEventNotification;
use App\Support\DatabaseNotifications;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TripRequestNotificationService
{

    public function notifyRequesterUpdated(Trip $trip, User $editor, ?string $changeSummary = null, bool $requiresReview = false): void
    {
        $message = sprintf(
            'Trip request %s was updated by %s%s',
            $trip->trip_code,
            $editor->name,
            $changeSummary ? ': ' . $changeSummary : ''
        );

        $this->notifyUsersByRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            'trip_request.requester_updated',
            'Trip request updated',
            $message,
            '/logistics?tab=trip-requests'
        );

        if ($requiresReview) {
            $this->sendToUsersWithRoles(
                $trip,
                ['logistics_manager', 'logistics_officer'],
                fn (string $email) => new TripRequestRequesterUpdatedMail($trip, $editor, $changeSummary),
                'trip requester resubmission'
            );
        }

        $this->notifyUser(
            $editor,
            $trip,
            'trip_request.requester_updated',
            'Trip request updated',
            $changeSummary ? "Your changes were saved. {$changeSummary}" : 'Your trip request changes were saved successfully.',
            '/department'
        );
    }

    public function notifySubmitted(Trip $trip, User $requester): void
    {
        $message = sprintf(
            'New trip request %s submitted by %s (%s → %s)',
            $trip->trip_code,
            $requester->name,
            $trip->origin ?? '—',
            $trip->destination ?? '—'
        );

        $this->notifyUsersByRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            'trip_request_submitted',
            'New trip request',
            $message,
            '/trip-requests/' . $trip->id
        );

        $this->sendToUsersWithRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            fn (string $email) => new \App\Mail\TripRequestSubmittedMail($trip, $requester),
            'trip request submission'
        );
    }

    public function notifyForwardedToDirector(Trip $trip, User $forwardedBy): void
    {
        $message = sprintf(
            'Trip request %s was forwarded by %s for your approval.',
            $trip->trip_code,
            $forwardedBy->name
        );

        $this->notifyUsersByRoles(
            $trip,
            ['supply_chain_director', 'supply_chain'],
            'trip_request_director_review',
            'Trip request awaiting approval',
            $message,
            '/trip-requests/' . $trip->id
        );

        $this->sendToUsersWithRoles(
            $trip,
            ['supply_chain_director', 'supply_chain'],
            fn (string $email) => new \App\Mail\TripRequestForwardedMail($trip, $forwardedBy),
            'trip director approval'
        );
    }

    public function notifyChangesRequested(Trip $trip, User $reviewer, ?string $reason = null): void
    {
        $requester = $trip->creator ?? User::find($trip->created_by);
        if (! $requester) {
            return;
        }

        $message = sprintf(
            'Changes were requested on trip request %s by %s%s',
            $trip->trip_code,
            $reviewer->name,
            $reason ? ': ' . $reason : ''
        );

        $this->notifyUser(
            $requester,
            $trip,
            'trip_request_changes_requested',
            'Changes requested on your trip request',
            '/trip-requests/' . $trip->id,
            [
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'reason' => $reason,
                'workflow_stage' => Trip::WORKFLOW_CHANGES_REQUESTED,
                'approval_status' => 'changes_requested',
                'icon' => 'alert-circle',
                'color' => 'amber',
                'priority' => 'high',
            ]
        );

        if (! $requester->email) {
            return;
        }

        try {
            Mail::to($requester->email)->send(new TripChangesRequestedMail(
                $trip,
                $requester,
                $reviewer,
                (string) ($reason ?? 'Please review and update your trip request.')
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to email trip changes requested notification', [
                'trip_request_id' => $trip->id,
                'requester_id' => $requester->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyDirectorApproved(Trip $trip, User $director): void
    {
        $lmMessage = sprintf(
            'Trip request %s was approved by %s and is awaiting logistics processing for %s.',
            $trip->trip_code,
            $director->name,
            $trip->destination ?? 'its destination'
        );

        $this->notifyUsersByRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            'trip_request_director_approved',
            'Ready for logistics processing',
            $lmMessage,
            '/trip-requests/' . $trip->id,
            [
                'director_id' => $director->id,
                'director_name' => $director->name,
                'workflow_stage' => Trip::WORKFLOW_DIRECTOR_APPROVED,
                'approval_status' => 'director_approved',
                'icon' => 'check-circle',
                'color' => 'green',
                'priority' => 'high',
            ]
        );

        $this->sendToUsersWithRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            fn (string $email) => new TripRequestDirectorApprovedMail($trip, $director),
            'trip logistics approval'
        );

        $requester = $trip->creator ?? User::find($trip->created_by);
        if (! $requester) {
            return;
        }

        $requesterMessage = sprintf(
            'Your trip request %s was approved by Supervising Director %s and is awaiting logistics conversion.',
            $trip->trip_code,
            $director->name
        );

        $this->notifyUser(
            $requester,
            $trip,
            'trip_request_director_approved',
            'Trip request approved by director',
            $requesterMessage,
            '/trip-requests/' . $trip->id,
            [
                'director_id' => $director->id,
                'director_name' => $director->name,
                'workflow_stage' => Trip::WORKFLOW_DIRECTOR_APPROVED,
                'approval_status' => 'director_approved',
                'icon' => 'check-circle',
                'color' => 'green',
                'priority' => 'normal',
            ]
        );

        $this->sendToUser($requester, new TripRequestDirectorApprovedMail($trip, $director), 'trip requester approval');
    }

    public function notifyScdApproved(Trip $trip, User $scdApprover): void
    {
        $message = sprintf(
            'Trip request %s was approved by Supply Chain Director %s and is awaiting logistics processing for %s.',
            $trip->trip_code,
            $scdApprover->name,
            $trip->destination ?? 'its destination'
        );

        $this->notifyUsersByRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            'trip_request_scd_approved',
            'Trip request approved by SCD',
            $message,
            '/trip-requests/' . $trip->id,
            [
                'scd_approver_id' => $scdApprover->id,
                'scd_approver_name' => $scdApprover->name,
                'workflow_stage' => Trip::WORKFLOW_SCD_APPROVED,
                'approval_status' => 'approved',
                'icon' => 'check-circle',
                'color' => 'green',
                'priority' => 'high',
            ]
        );

        $this->sendToUsersWithRoles(
            $trip,
            ['logistics_manager', 'logistics_officer'],
            fn (string $email) => new TripRequestDirectorApprovedMail($trip, $scdApprover),
            'trip logistics approval'
        );

        $requester = $trip->creator ?? User::find($trip->created_by);
        if ($requester) {
            $this->notifyUser($requester, $trip, 'trip_request_scd_approved', 'Trip request approved',
                sprintf('Your trip request %s was approved and is awaiting logistics processing.', $trip->trip_code),
                '/trip-requests/' . $trip->id);
            $this->sendToUser($requester, new TripRequestDirectorApprovedMail($trip, $scdApprover), 'trip requester approval');
        }
    }

    public function notifyDirectorRejected(Trip $trip, User $director, User $requester, ?string $reason = null): void
    {
        $message = sprintf(
            'Trip request %s was rejected by Supervising Director %s%s.',
            $trip->trip_code,
            $director->name,
            $reason ? ': ' . $reason : ''
        );

        $extra = [
            'director_id' => $director->id,
            'director_name' => $director->name,
            'reason' => $reason,
            'approval_status' => 'rejected',
            'icon' => 'x-circle',
            'color' => 'red',
            'priority' => 'high',
        ];

        $this->notifyUser(
            $requester,
            $trip,
            'trip_request_director_rejected',
            'Trip request rejected',
            $message,
            '/department',
            $extra
        );

        $this->sendToUser($requester, new TripRequestRejectedMail($trip, $director, $reason), 'trip requester rejection');
    }

    public function notifyDirectorReturned(Trip $trip, User $director, User $requester, ?string $reason = null): void
    {
        $message = sprintf(
            'Trip request %s was returned for revision by Supervising Director %s%s.',
            $trip->trip_code,
            $director->name,
            $reason ? ': ' . $reason : ''
        );

        $extra = [
            'director_id' => $director->id,
            'director_name' => $director->name,
            'reason' => $reason,
            'workflow_stage' => Trip::WORKFLOW_CHANGES_REQUESTED,
            'approval_status' => 'revision_required',
            'icon' => 'alert-circle',
            'color' => 'amber',
            'priority' => 'high',
        ];

        $this->notifyUser(
            $requester,
            $trip,
            'trip_request_returned',
            'Returned for revision',
            $message,
            '/trip-requests/' . $trip->id,
            $extra
        );

    }

    public function notifyConverted(Trip $tripRequest, Trip $logisticsTrip, User $convertedBy): void
    {
        $requester = $tripRequest->creator ?? User::find($tripRequest->created_by);
        if ($requester) {
            $this->notifyUser(
                $requester,
                $logisticsTrip,
                'trip_request_converted',
                'Trip request converted',
                sprintf('Your trip request %s was converted to logistics trip %s.', $tripRequest->trip_code, $logisticsTrip->trip_code),
                '/trips/' . $logisticsTrip->id
            );
        }

        app(TripSchedulingNotificationService::class)->notifyTripCreated($logisticsTrip);
    }

    public function notifyConfirmed(Trip $tripRequest, Trip $logisticsTrip, User $confirmedBy): void
    {
        $requester = $tripRequest->creator ?? User::find($tripRequest->created_by);
        if ($requester) {
            $this->notifyUser(
                $requester,
                $logisticsTrip,
                'trip_request_confirmed',
                'Trip request approved',
                sprintf('Your trip request %s has been approved and scheduled as %s.', $tripRequest->trip_code, $logisticsTrip->trip_code),
                '/trips/' . $logisticsTrip->id
            );

            foreach ($tripRequest->external_passengers ?? [] as $passenger) {
                if (empty($passenger['email'])) {
                    continue;
                }
                try {
                    Mail::to($passenger['email'])->send(
                        new TripExternalPassengerConfirmedMail($logisticsTrip, $passenger, $requester)
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to email external trip passenger on confirm', [
                        'trip_request_id' => $tripRequest->id,
                        'logistics_trip_id' => $logisticsTrip->id,
                        'email' => $passenger['email'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        app(TripSchedulingNotificationService::class)->notifyTripCreated($logisticsTrip);
    }

    public function notifyRejected(Trip $trip, User $requester, ?string $reason = null): void
    {
        $message = sprintf(
            'Trip request %s was rejected%s.',
            $trip->trip_code,
            $reason ? ': ' . $reason : ''
        );

        $this->notifyUser(
            $requester,
            $trip,
            'trip_request_rejected',
            'Trip request rejected',
            $message,
            '/department'
        );

        $this->sendToUser($requester, new TripRequestRejectedMail($trip, null, $reason), 'trip requester rejection');
    }

    public function notifyComment(Trip $trip, User $author, string $body, bool $isTripRequest): void
    {
        $recipientIds = collect($trip->passenger_user_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->push((int) $trip->created_by)
            ->when($trip->driver_user_id, fn ($c) => $c->push((int) $trip->driver_user_id))
            ->unique()
            ->filter(fn (int $id) => $id > 0 && $id !== (int) $author->id)
            ->values()
            ->all();

        $actionUrl = $isTripRequest
            ? '/logistics?tab=trip-requests'
            : '/trips/' . $trip->id;

        User::query()
            ->whereIn('id', $recipientIds)
            ->get()
            ->each(fn (User $user) => $this->notifyUser(
                $user,
                $trip,
                'trip_comment_added',
                'New trip comment',
                sprintf('%s commented on trip %s', $author->name, $trip->trip_code),
                $actionUrl
            ));
    }

    /**
     * @param  list<string>  $roles
     */
    private function notifyUsersByRoles(
        Trip $trip,
        array $roles,
        string $type,
        string $title,
        string $message,
        string $actionUrl,
        array $extra = [],
    ): void {
        User::query()
            ->whereIn('supply_chain_role', $roles)
            ->get()
            ->unique(fn (User $user) => trim((string) $user->email) !== ''
                ? strtolower(trim((string) $user->email))
                : 'user:' . $user->id)
            ->each(fn (User $user) => $this->notifyUser($user, $trip, $type, $title, $message, $actionUrl, $extra));
    }

    public function notifyLogisticsReviewAction(Trip $trip, User $reviewer, string $action, ?string $reason = null): void
    {
        $message = match ($action) {
            'forward' => sprintf('Trip request %s was forwarded to the Supply Chain Director by %s.', $trip->trip_code, $reviewer->name),
            'request_changes' => sprintf('Trip request %s requires changes from the requester: %s', $trip->trip_code, $reason ?: 'No reason provided'),
            'reject' => sprintf('Trip request %s was rejected by %s%s', $trip->trip_code, $reviewer->name, $reason ? ': ' . $reason : ''),
            default => sprintf('Trip request %s received a review update from %s.', $trip->trip_code, $reviewer->name),
        };

        $this->notifyUsersByRoles(
            $trip,
            ['supply_chain_director', 'supply_chain', 'logistics_manager', 'logistics_officer', 'procurement_manager', 'procurement'],
            'trip_request_review',
            'Trip request review update',
            $message,
            '/trip-requests/' . $trip->id
        );
    }

    private function notifyUser(
        User $user,
        Trip $trip,
        string $type,
        string $title,
        string $message,
        string $actionUrl,
        array $extra = [],
    ): void {
        try {
            DatabaseNotifications::send($user, new LogisticsEventNotification($type, array_merge([
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'trip_id' => $trip->id,
                'trip_code' => $trip->trip_code,
                'icon' => 'truck',
                'color' => 'blue',
                'priority' => 'normal',
            ], $extra)));
        } catch (\Throwable $e) {
            Log::warning('Trip request notification failed', [
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendToUsersWithRoles(Trip $trip, array $roles, callable $mailableFactory, string $event): void
    {
        User::query()
            ->whereIn('supply_chain_role', $roles)
            ->whereNotNull('email')
            ->get()
            ->filter(fn (User $user) => trim((string) $user->email) !== '')
            ->unique(fn (User $user) => strtolower(trim((string) $user->email)))
            ->each(fn (User $user) => $this->sendToUser($user, $mailableFactory((string) $user->email), $event));
    }

    private function sendToUser(User $user, object $mailable, string $event): void
    {
        if (! trim((string) $user->email)) {
            return;
        }

        try {
            Mail::to(trim((string) $user->email))->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Trip notification email failed', [
                'event' => $event,
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
