<?php

namespace App\Notifications;

use App\Models\Logistics\Trip;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorInvitedForTripNotification extends Notification implements ShouldQueue
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
            ->subject("Trip Quote Request: {$this->trip->trip_code}")
            ->greeting("Hello {$this->vendor->name},")
            ->line("You have been invited to submit a quotation for a logistics trip.")
            ->line("Trip Code: {$this->trip->trip_code}")
            ->line("Title: {$this->trip->title}")
            ->line("Origin: {$this->trip->origin}")
            ->line("Destination: {$this->trip->destination}")
            ->line("Scheduled Departure: {$this->trip->scheduled_departure_at?->format('Y-m-d H:i')}")
            ->line("Description: {$this->trip->description}")
            ->action('Submit Your Quote', url("/vendor-portal/trips/{$this->trip->id}/submission"))
            ->line("Please review the trip details and submit your vehicle, driver information, and quoted price through the Vendor Portal.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'trip_id' => $this->trip->id,
            'trip_code' => $this->trip->trip_code,
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'title' => $this->trip->title,
            'origin' => $this->trip->origin,
            'destination' => $this->trip->destination,
            'scheduled_departure_at' => $this->trip->scheduled_departure_at,
            'message' => "You have been invited to submit a quotation for trip {$this->trip->trip_code}",
            'action_url' => "/vendor-portal/trips/{$this->trip->id}/submission",
            'notification_type' => 'vendor_invite',
        ];
    }
}
