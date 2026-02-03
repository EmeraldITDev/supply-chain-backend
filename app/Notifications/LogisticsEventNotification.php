<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LogisticsEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $type,
        protected array $payload
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }
}
