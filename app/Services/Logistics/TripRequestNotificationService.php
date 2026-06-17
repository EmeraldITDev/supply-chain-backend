<?php

namespace App\Services\Logistics;

use App\Mail\TripExternalPassengerConfirmedMail;
use App\Mail\TripRequestSubmittedMail;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Notifications\LogisticsEventNotification;
use App\Services\WorkflowNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TripRequestNotificationService
{
    public function __construct(
        private WorkflowNotificationService $workflowNotifications,
    ) {
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
            '/logistics?tab=trip-requests'
        );

        $emails = User::query()
            ->whereIn('supply_chain_role', ['logistics_manager', 'logistics_officer'])
            ->whereNotNull('email')
            ->pluck('email');

        $emails = $emails->merge(collect(config('scm.logistics_notification_cc_emails', [])));

        foreach ($emails->filter()->unique(fn ($e) => strtolower((string) $e))->values() as $email) {
            $this->workflowNotifications->notifyTripRequestSubmittedToEmail($trip, $requester, (string) $email);
        }
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
    ): void {
        User::query()
            ->whereIn('supply_chain_role', $roles)
            ->whereNotNull('email')
            ->get()
            ->each(fn (User $user) => $this->notifyUser($user, $trip, $type, $title, $message, $actionUrl));
    }

    private function notifyUser(User $user, Trip $trip, string $type, string $title, string $message, string $actionUrl): void
    {
        try {
            $user->notifyNow(new LogisticsEventNotification($type, [
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'trip_id' => $trip->id,
                'trip_code' => $trip->trip_code,
                'icon' => 'truck',
                'color' => 'blue',
                'priority' => 'normal',
            ]));
        } catch (\Throwable $e) {
            Log::warning('Trip request notification failed', [
                'trip_id' => $trip->id,
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
