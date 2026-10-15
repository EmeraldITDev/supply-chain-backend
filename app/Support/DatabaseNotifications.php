<?php

namespace App\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

final class DatabaseNotifications
{
    /**
     * Deliver a notification's database channel immediately (for in-app badge),
     * then deliver any other channels declared by via() (typically mail).
     *
     * Previously only the database channel was sent, which silently dropped
     * email for every notification that lists 'mail' in via().
     */
    public static function send(object $notifiable, Notification $notification): void
    {
        $channels = method_exists($notification, 'via')
            ? array_values(array_unique((array) $notification->via($notifiable)))
            : ['database'];

        if ($channels === []) {
            return;
        }

        $databaseChannels = array_values(array_intersect($channels, ['database']));
        $otherChannels = array_values(array_diff($channels, ['database']));

        if ($databaseChannels !== []) {
            $notifiable->notifyNow($notification, $databaseChannels);

            // Bust the cached unread badge count so a freshly delivered notification
            // is reflected immediately, even though the count is cached with a
            // longer TTL to avoid a slow COUNT(*) on every poll.
            if (method_exists($notifiable, 'getKey')) {
                FastCache::store()->forget('notifications.unread.'.$notifiable->getKey());
            }
        }

        if ($otherChannels === []) {
            return;
        }

        try {
            // Always deliver mail/SMS synchronously here. Render runs without a
            // dedicated queue worker, and QUEUE_CONNECTION is typically sync —
            // notifyNow keeps emails from being stuck in a queue that never drains.
            $notifiable->notifyNow($notification, $otherChannels);
        } catch (\Throwable $e) {
            Log::error('Notification non-database channel failed', [
                'channels' => $otherChannels,
                'notification' => $notification::class,
                'notifiable' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  iterable<int, object>  $notifiables
     */
    public static function sendMany(iterable $notifiables, Notification $notification): void
    {
        foreach ($notifiables as $notifiable) {
            static::send($notifiable, $notification);
        }
    }
}
