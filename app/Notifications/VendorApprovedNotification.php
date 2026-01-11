<?php

namespace App\Notifications;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class VendorApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Vendor $vendor;
    protected string $temporaryPassword;

    public function __construct(Vendor $vendor, string $temporaryPassword)
    {
        $this->vendor = $vendor;
        $this->temporaryPassword = $temporaryPassword;
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
            'type' => 'vendor_approved',
            'title' => 'Vendor Registration Approved',
            'message' => "The vendor '{$this->vendor->name}' has been approved and credentials have been sent",
            'vendor_id' => $this->vendor->id,
            'vendor_number' => $this->vendor->vendor_id,
            'vendor_name' => $this->vendor->name,
            'action_url' => "/vendors/{$this->vendor->id}",
            'icon' => 'check-circle',
            'color' => 'green',
            'priority' => 'normal',
        ];
    }
}
