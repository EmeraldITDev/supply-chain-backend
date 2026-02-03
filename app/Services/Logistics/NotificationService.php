<?php

namespace App\Services\Logistics;

use App\Models\Logistics\NotificationEvent;
use App\Models\User;
use App\Jobs\SendLogisticsNotification;

class NotificationService
{
    public function recordAndDispatch(string $eventKey, string $type, array $payload, array $roles = []): NotificationEvent
    {
        $event = NotificationEvent::firstOrCreate([
            'event_key' => $eventKey,
        ], [
            'type' => $type,
            'payload' => array_merge($payload, ['roles' => $roles]),
            'status' => 'pending',
            'attempts' => 0,
        ]);

        if ($event->wasRecentlyCreated) {
            SendLogisticsNotification::dispatch($event->id);
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
