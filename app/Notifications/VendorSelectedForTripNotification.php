<?php

namespace App\Notifications;

use App\Models\Logistics\Trip;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorSelectedForTripNotification extends Notification implements ShouldQueue
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
            ->subject("Trip Approved - Invoice Submission Required: {$this->trip->trip_code}")
            ->greeting("Hello {$this->vendor->name},")
            ->line("Congratulations! Your quotation has been accepted for the following trip.")
            ->line("Trip Code: {$this->trip->trip_code}")
            ->line("Title: {$this->trip->title}")
            ->line("Origin: {$this->trip->origin}")
            ->line("Destination: {$this->trip->destination}")
            ->line("Scheduled Departure: {$this->trip->scheduled_departure_at?->format('Y-m-d H:i')}")
            ->line("The trip has been approved and routed to Procurement for PO generation.")
            ->action('Submit Invoice', url("/vendor-portal/trips/{$this->trip->id}/invoice"))
            ->line("Once the Purchase Order (PO) is ready, please submit your invoice through the Vendor Portal.");
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
            'message' => "Your quotation for trip {$this->trip->trip_code} has been approved",
            'action_url' => "/vendor-portal/trips/{$this->trip->id}/invoice",
            'notification_type' => 'vendor_selected',
        ];
    }
}
