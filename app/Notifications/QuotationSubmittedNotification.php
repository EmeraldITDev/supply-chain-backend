<?php

namespace App\Notifications;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class QuotationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Quotation $quotation;
    protected string $vendorName;

    public function __construct(Quotation $quotation, string $vendorName)
    {
        $this->quotation = $quotation;
        $this->vendorName = $vendorName;
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
            'type' => 'quotation_submitted',
            'title' => 'New Quotation Received',
            'message' => "A new quotation has been submitted by {$this->vendorName}",
            'quotation_id' => $this->quotation->id,
            'vendor_name' => $this->vendorName,
            'total_amount' => $this->quotation->total_amount,
            'currency' => $this->quotation->currency ?? 'USD',
            'rfq_id' => $this->quotation->rfq_id,
            'action_url' => "/quotations/{$this->quotation->quotation_id}",
            'icon' => 'document-text',
            'color' => 'purple',
            'priority' => 'normal',
        ];
    }
}
