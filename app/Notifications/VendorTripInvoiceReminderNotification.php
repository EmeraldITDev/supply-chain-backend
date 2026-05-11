<?php

namespace App\Notifications;

use App\Models\Logistics\Trip;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorTripInvoiceReminderNotification extends Notification implements ShouldQueue
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
            ->subject("Submit invoice for trip {$this->trip->trip_code}")
            ->greeting("Hello {$this->vendor->name},")
            ->line('The trip has been routed to Procurement. Please submit your invoice through the Vendor Portal when your purchase order is available.')
            ->line("Trip Code: {$this->trip->trip_code}")
            ->line("Title: {$this->trip->title}")
            ->action('Vendor Portal', url("/vendor-portal/trips/{$this->trip->id}/invoice"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'trip_id' => $this->trip->id,
            'trip_code' => $this->trip->trip_code,
            'vendor_id' => $this->vendor->id,
            'message' => 'Please submit your invoice for this trip.',
            'action_url' => "/vendor-portal/trips/{$this->trip->id}/invoice",
            'notification_type' => 'trip_invoice_reminder',
        ];
    }
}
