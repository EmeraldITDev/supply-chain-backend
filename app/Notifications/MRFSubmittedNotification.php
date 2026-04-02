<?php

namespace App\Notifications;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class MRFSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected MRF $mrf;

    public function __construct(MRF $mrf)
    {
        $this->mrf = $mrf;
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
        $isEmeraldContract = strtolower(trim((string) $this->mrf->contract_type)) === 'emerald';
        $firstApprovalLabel = $isEmeraldContract
            ? 'Executive Approval (Dr. Gomi Babajide)'
            : 'Supply Chain Director Initial Approval';

        return [
            'type' => 'mrf_submitted',
            'title' => 'New MRF Submitted',
            'message' => "A new Material Requisition Form ({$this->mrf->mrf_id}) has been submitted by {$this->mrf->requester_name} and is awaiting {$firstApprovalLabel}.",
            'mrf_id' => $this->mrf->id,
            'mrf_number' => $this->mrf->mrf_id,
            'requester' => $this->mrf->requester_name,
            'contract_type' => $this->mrf->contract_type,
            'first_approval_stage_label' => $firstApprovalLabel,
            'urgency' => $this->mrf->urgency,
            'category' => $this->mrf->category,
            'estimated_cost' => $this->mrf->estimated_cost,
            'action_url' => "/mrfs/{$this->mrf->mrf_id}",
            'icon' => 'document',
            'color' => 'blue',
            'priority' => $this->mrf->urgency === 'Critical' ? 'high' : 'normal',
        ];
    }
}
