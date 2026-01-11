<?php

namespace App\Notifications;

use App\Models\RFQ;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class RFQAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected RFQ $rfq;

    public function __construct(RFQ $rfq)
    {
        $this->rfq = $rfq;
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
            'type' => 'rfq_assigned',
            'title' => 'New RFQ Assignment',
            'message' => "You have been assigned a new Request for Quotation ({$this->rfq->rfq_number})",
            'rfq_id' => $this->rfq->id,
            'rfq_number' => $this->rfq->rfq_number,
            'description' => $this->rfq->description,
            'deadline' => $this->rfq->deadline?->format('Y-m-d'),
            'action_url' => "/rfqs/{$this->rfq->id}",
            'icon' => 'clipboard-list',
            'color' => 'blue',
            'priority' => 'high',
        ];
    }
}
