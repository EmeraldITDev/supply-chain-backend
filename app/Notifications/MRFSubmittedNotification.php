<?php

namespace App\Notifications;

use App\Models\MRF;
use App\Support\LogisticsMrfRouting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

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
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        // Check if recipient has procurement module access
        $hasProcurementAccess = in_array($notifiable->role, [
            'procurement',
            'procurement_manager',
            'supply_chain_director',
            'supply_chain',
            'admin'
        ]);

        // Build URL based on procurement access
        if ($hasProcurementAccess) {
            $mrfUrl = 'https://scm.emeraldcfze.com/procurement';
        } else {
            $mrfUrl = 'https://scm.emeraldcfze.com';
        }

        $this->mrf->loadMissing('requester');
        $isEmeraldContract = strtolower(trim((string) $this->mrf->contract_type)) === 'emerald';
        $scdFirst = ! $isEmeraldContract || LogisticsMrfRouting::mrfShouldStartAtSupplyChainDirector($this->mrf);
        $firstApprovalLabel = $scdFirst
            ? 'Supply Chain Director Initial Approval'
            : 'Executive Approval';

        return (new MailMessage)
            ->subject("New MRF Submitted - {$this->mrf->mrf_id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new Material Requisition Form has been submitted and requires your attention.")
            ->line("**MRF Number:** {$this->mrf->mrf_id}")
            ->line("**Submitted By:** {$this->mrf->requester_name}")
            ->line("**Category:** {$this->mrf->category}")
            ->line("**Estimated Cost:** " . ($this->mrf->estimated_cost ? '$' . number_format($this->mrf->estimated_cost, 2) : 'Not specified'))
            ->line("**Urgency:** {$this->mrf->urgency}")
            ->line("**Approval Stage:** {$firstApprovalLabel}")
            ->action('Review MRF', $mrfUrl)
            ->line('Please review and provide your approval or feedback.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $this->mrf->loadMissing('requester');
        $isEmeraldContract = strtolower(trim((string) $this->mrf->contract_type)) === 'emerald';
        $scdFirst = ! $isEmeraldContract || LogisticsMrfRouting::mrfShouldStartAtSupplyChainDirector($this->mrf);
        $firstApprovalLabel = $scdFirst
            ? 'Supply Chain Director Initial Approval'
            : 'Executive Approval (bunmi.babajide@emeraldcfze.com)';

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
