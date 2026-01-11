<?php

namespace App\Notifications;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class MRFApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected MRF $mrf;
    protected string $approverName;
    protected ?string $remarks;

    public function __construct(MRF $mrf, string $approverName, ?string $remarks = null)
    {
        $this->mrf = $mrf;
        $this->approverName = $approverName;
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
        return [
            'type' => 'mrf_approved',
            'title' => 'MRF Approved',
            'message' => "Your Material Requisition Form ({$this->mrf->mrf_id}) has been approved by {$this->approverName}",
            'mrf_id' => $this->mrf->id,
            'mrf_number' => $this->mrf->mrf_id,
            'approver' => $this->approverName,
            'remarks' => $this->remarks,
            'action_url' => "/mrfs/{$this->mrf->id}",
            'icon' => 'check-circle',
            'color' => 'green',
            'priority' => 'normal',
        ];
    }
}
