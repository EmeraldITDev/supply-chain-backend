<?php

namespace App\Notifications;

use App\Models\Logistics\Trip;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorAssignedToTripNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Trip $trip,
        private Vendor $vendor
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
            ->subject("New Trip Assignment: {$this->trip->trip_code}")
            ->greeting("Hello {$this->vendor->name},")
            ->line("You have been assigned to a new logistics trip.")
            ->line("Trip Code: {$this->trip->trip_code}")
            ->line("Origin: {$this->trip->origin}")
            ->line("Destination: {$this->trip->destination}")
            ->line("Scheduled Departure: {$this->trip->scheduled_departure_at?->format('Y-m-d H:i')}")
            ->action('View Trip Details', url("/logistics/trips/{$this->trip->id}"))
            ->line("Please review the trip details and confirm your ability to proceed with the assignment.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'trip_id' => $this->trip->id,
            'trip_code' => $this->trip->trip_code,
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'origin' => $this->trip->origin,
            'destination' => $this->trip->destination,
            'scheduled_departure_at' => $this->trip->scheduled_departure_at,
            'message' => "You have been assigned to trip {$this->trip->trip_code}",
            'action_url' => "/logistics/trips/{$this->trip->id}",
            'notification_type' => 'trip_assignment',
        ];
    }
}
