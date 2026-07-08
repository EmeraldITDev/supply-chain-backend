<?php

namespace App\Support;

use Illuminate\Notifications\Notification;

final class DatabaseNotifications
{
    public static function send(object $notifiable, Notification $notification): void
    {
        $notifiable->notifyNow($notification, ['database']);
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
