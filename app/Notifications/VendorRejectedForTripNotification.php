<?php

namespace App\Notifications;

use App\Models\Logistics\Trip;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorRejectedForTripNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Trip $trip,
        private Vendor $vendor,
        private ?string $reason = null
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Trip Quote Decision: {$this->trip->trip_code}")
            ->greeting("Hello {$this->vendor->name},")
            ->line("Thank you for submitting your quotation for the following trip.")
            ->line("Trip Code: {$this->trip->trip_code}")
            ->line("Title: {$this->trip->title}")
            ->line("Origin: {$this->trip->origin}")
            ->line("Destination: {$this->trip->destination}")
            ->line("Unfortunately, another vendor was selected for this trip.");

        if ($this->reason) {
            $message->line("Reason: {$this->reason}");
        }

        return $message->line("We appreciate your participation and look forward to working with you on future opportunities.");
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
            'reason' => $this->reason,
            'message' => "Your quotation for trip {$this->trip->trip_code} was not selected",
            'notification_type' => 'vendor_rejected',
        ];
    }
}
