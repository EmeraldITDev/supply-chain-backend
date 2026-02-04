<?php

namespace App\Notifications;

use App\Models\Logistics\Journey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JourneyStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Journey $journey,
        private string $previousStatus,
        private string $newStatus
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabels = [
            'DRAFT' => 'Draft',
            'SCHEDULED' => 'Scheduled',
            'DEPARTED' => 'Departed',
            'EN_ROUTE' => 'En Route',
            'ARRIVED' => 'Arrived',
            'COMPLETED' => 'Completed',
            'CANCELLED' => 'Cancelled',
        ];

        $trip = $this->journey->trip;
        
        return (new MailMessage)
            ->subject("Journey Status Update: {$trip->trip_code} - {$statusLabels[$this->newStatus] ?? $this->newStatus}")
            ->greeting("Journey Update Notification")
            ->line("Trip Code: {$trip->trip_code}")
            ->line("Origin: {$trip->origin}")
            ->line("Destination: {$trip->destination}")
            ->line("Status Changed: {$statusLabels[$this->previousStatus] ?? $this->previousStatus} → {$statusLabels[$this->newStatus] ?? $this->newStatus}")
            ->when($this->journey->current_location, fn($mail) => $mail->line("Current Location: {$this->journey->current_location}"))
            ->when($this->journey->estimated_arrival_at, fn($mail) => $mail->line("Estimated Arrival: {$this->journey->estimated_arrival_at->format('Y-m-d H:i')}"))
            ->action('View Journey Details', url("/logistics/trips/{$trip->id}"))
            ->line('Thank you for using our logistics platform.');
    }

    public function toArray(object $notifiable): array
    {
        $statusLabels = [
            'DRAFT' => 'Draft',
            'SCHEDULED' => 'Scheduled',
            'DEPARTED' => 'Departed',
            'EN_ROUTE' => 'En Route',
            'ARRIVED' => 'Arrived',
            'COMPLETED' => 'Completed',
            'CANCELLED' => 'Cancelled',
        ];

        $trip = $this->journey->trip;

        return [
            'journey_id' => $this->journey->id,
            'trip_id' => $trip->id,
            'trip_code' => $trip->trip_code,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'status_label' => $statusLabels[$this->newStatus] ?? $this->newStatus,
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'current_location' => $this->journey->current_location,
            'estimated_arrival_at' => $this->journey->estimated_arrival_at,
            'message' => "Journey for trip {$trip->trip_code} is now " . strtolower($statusLabels[$this->newStatus] ?? $this->newStatus),
            'action_url' => "/logistics/trips/{$trip->id}",
            'notification_type' => 'journey_status_update',
        ];
    }
}
