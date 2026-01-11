<?php

namespace App\Notifications;

use App\Models\VendorRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class VendorRegistrationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected VendorRegistration $registration;

    public function __construct(VendorRegistration $registration)
    {
        $this->registration = $registration;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'vendor_registration',
            'title' => 'New Vendor Registration',
            'message' => "A new vendor registration has been submitted by {$this->registration->name}",
            'registration_id' => $this->registration->id,
            'vendor_name' => $this->registration->name,
            'email' => $this->registration->email,
            'category' => $this->registration->category,
            'action_url' => "/vendors/registrations/{$this->registration->id}",
            'icon' => 'user-add',
            'color' => 'blue',
            'priority' => 'normal',
        ];
    }
}
