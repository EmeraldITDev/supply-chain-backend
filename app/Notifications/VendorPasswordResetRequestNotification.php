<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class VendorPasswordResetRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $vendor;
    protected string $vendorEmail;

    public function __construct($vendor, string $vendorEmail)
    {
        $this->vendor = $vendor;
        $this->vendorEmail = $vendorEmail;
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
        $vendorName = is_object($this->vendor) && isset($this->vendor->name) 
            ? $this->vendor->name 
            : ($this->vendorEmail ?? 'Unknown Vendor');
        
        $vendorId = is_object($this->vendor) && isset($this->vendor->vendor_id)
            ? $this->vendor->vendor_id
            : 'N/A';

        return [
            'type' => 'vendor_password_reset_request',
            'title' => 'Vendor Password Reset Request',
            'message' => "The vendor '{$vendorName}' ({$this->vendorEmail}) has requested a password reset. A temporary password has been generated and sent.",
            'vendor_id' => is_object($this->vendor) && isset($this->vendor->id) ? $this->vendor->id : null,
            'vendor_number' => $vendorId,
            'vendor_name' => $vendorName,
            'vendor_email' => $this->vendorEmail,
            'action_url' => is_object($this->vendor) && isset($this->vendor->id) ? "/vendors/{$this->vendor->id}" : "/vendors",
            'icon' => 'key',
            'color' => 'blue',
            'priority' => 'normal',
        ];
    }
}
