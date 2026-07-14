<?php

namespace App\Support;

use Illuminate\Notifications\Notification;

final class DatabaseNotifications
{
    public static function send(object $notifiable, Notification $notification): void
    {
        $notifiable->notifyNow($notification, ['database']);

        // Bust the cached unread badge count so a freshly delivered notification
        // is reflected immediately, even though the count is cached with a
        // longer TTL to avoid a slow COUNT(*) on every poll.
        if (method_exists($notifiable, 'getKey')) {
            FastCache::store()->forget('notifications.unread.'.$notifiable->getKey());
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
