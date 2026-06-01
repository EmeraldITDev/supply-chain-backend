<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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
        $message = (string) ($this->payload['message'] ?? '');

        return [
            'type' => $this->type,
            'title' => $this->payload['title'] ?? Str::title(str_replace('_', ' ', $this->type)),
            'message' => $message,
            'action_url' => $this->payload['action_url'] ?? null,
            'icon' => $this->payload['icon'] ?? 'bell',
            'color' => $this->payload['color'] ?? 'blue',
            'priority' => $this->payload['priority'] ?? 'normal',
            'payload' => $this->payload,
        ];
    }
}
