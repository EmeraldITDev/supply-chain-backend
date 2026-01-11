<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class DocumentExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $documentType;
    protected string $expiryDate;
    protected string $vendorName;
    protected int $vendorId;
    protected int $daysUntilExpiry;

    public function __construct(
        string $documentType,
        string $expiryDate,
        string $vendorName,
        int $vendorId,
        int $daysUntilExpiry
    ) {
        $this->documentType = $documentType;
        $this->expiryDate = $expiryDate;
        $this->vendorName = $vendorName;
        $this->vendorId = $vendorId;
        $this->daysUntilExpiry = $daysUntilExpiry;
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
        $message = $this->daysUntilExpiry > 0
            ? "The {$this->documentType} for {$this->vendorName} will expire in {$this->daysUntilExpiry} days"
            : "The {$this->documentType} for {$this->vendorName} has expired";

        return [
            'type' => 'document_expiry',
            'title' => 'Document Expiry Alert',
            'message' => $message,
            'document_type' => $this->documentType,
            'expiry_date' => $this->expiryDate,
            'vendor_name' => $this->vendorName,
            'vendor_id' => $this->vendorId,
            'days_until_expiry' => $this->daysUntilExpiry,
            'action_url' => "/vendors/{$this->vendorId}",
            'icon' => 'exclamation-triangle',
            'color' => $this->daysUntilExpiry <= 0 ? 'red' : 'yellow',
            'priority' => $this->daysUntilExpiry <= 7 ? 'high' : 'normal',
        ];
    }
}
