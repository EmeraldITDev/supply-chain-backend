<?php

namespace App\Notifications;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class QuotationStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Quotation $quotation;
    protected string $oldStatus;
    protected string $newStatus;
    protected ?string $remarks;

    public function __construct(Quotation $quotation, string $oldStatus, string $newStatus, ?string $remarks = null)
    {
        $this->quotation = $quotation;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->remarks = $remarks;
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
        $color = match($this->newStatus) {
            'Approved', 'Accepted' => 'green',
            'Rejected', 'Declined' => 'red',
            'Under Review' => 'yellow',
            default => 'blue',
        };

        return [
            'type' => 'quotation_status_updated',
            'title' => 'Quotation Status Updated',
            'message' => "Your quotation status has been updated to: {$this->newStatus}",
            'quotation_id' => $this->quotation->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'remarks' => $this->remarks,
            'action_url' => "/quotations/{$this->quotation->quotation_id}",
            'icon' => 'refresh',
            'color' => $color,
            'priority' => in_array($this->newStatus, ['Approved', 'Rejected']) ? 'high' : 'normal',
        ];
    }
}
