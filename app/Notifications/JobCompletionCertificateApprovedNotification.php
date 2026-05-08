<?php

namespace App\Notifications;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\Trip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobCompletionCertificateApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Trip $trip,
        private JobCompletionCertificate $jcc
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Trip Completed and Closed: {$this->trip->trip_code}")
            ->greeting("Hello,")
            ->line("A trip has been successfully completed and closed.")
            ->line("Trip Code: {$this->trip->trip_code}")
            ->line("Title: {$this->trip->title}")
            ->line("Origin: {$this->trip->origin}")
            ->line("Destination: {$this->trip->destination}")
            ->line("Delivery Confirmed: " . ($this->jcc->delivery_confirmed ? 'Yes' : 'No'))
            ->line("Job Completion Certificate has been approved.")
            ->action('View Trip Details', url("/logistics/trips/{$this->trip->id}"))
            ->line("The trip is now closed and all transactions are finalized.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'trip_id' => $this->trip->id,
            'trip_code' => $this->trip->trip_code,
            'jcc_id' => $this->jcc->id,
            'title' => $this->trip->title,
            'origin' => $this->trip->origin,
            'destination' => $this->trip->destination,
            'delivery_confirmed' => $this->jcc->delivery_confirmed,
            'message' => "Trip {$this->trip->trip_code} has been completed and closed",
            'action_url' => "/logistics/trips/{$this->trip->id}",
            'notification_type' => 'trip_completed',
        ];
    }
}
