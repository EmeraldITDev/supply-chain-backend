<?php

namespace App\Jobs;

use App\Models\Logistics\NotificationEvent;
use App\Notifications\LogisticsEventNotification;
use App\Services\Logistics\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendLogisticsNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(private int $eventId)
    {
    }

    public function handle(NotificationService $notificationService): void
    {
        $event = NotificationEvent::find($this->eventId);

        if (!$event || $event->status === 'sent') {
            return;
        }

        $roles = $event->payload['roles'] ?? [];
        $recipients = $notificationService->resolveRecipientsByRoles($roles);

        Notification::send($recipients, new LogisticsEventNotification($event->type, $event->payload));

        $event->status = 'sent';
        $event->attempts = $event->attempts + 1;
        $event->last_error = null;
        $event->save();
    }

    public function failed(Throwable $exception): void
    {
        $event = NotificationEvent::find($this->eventId);

        if (!$event) {
            return;
        }

        $event->status = 'failed';
        $event->attempts = $event->attempts + 1;
        $event->last_error = $exception->getMessage();
        $event->next_retry_at = now()->addMinutes(5);
        $event->save();
    }
}
