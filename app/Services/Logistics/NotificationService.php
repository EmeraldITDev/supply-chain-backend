<?php

namespace App\Services\Logistics;

use App\Models\Logistics\NotificationEvent;
use App\Models\User;
use App\Notifications\LogisticsEventNotification;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    public function recordAndDispatch(string $eventKey, string $type, array $payload, array $recipients): NotificationEvent
    {
        $event = NotificationEvent::firstOrCreate([
            'event_key' => $eventKey,
        ], [
            'type' => $type,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        if ($event->wasRecentlyCreated) {
            Notification::send($recipients, new LogisticsEventNotification($type, $payload));
            $event->status = 'sent';
            $event->attempts = 1;
            $event->save();
        }

        return $event;
    }

    /**
     * Resolve recipient users from roles.
     *
     * @param array<string> $roles
     * @return array<int, User>
     */
    public function resolveRecipientsByRoles(array $roles): array
    {
        return User::query()
            ->whereIn('role', $roles)
            ->get()
            ->all();
    }
}
