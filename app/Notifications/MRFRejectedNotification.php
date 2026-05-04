<?php

namespace App\Notifications;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class MRFRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected MRF $mrf;
    protected string $rejectorName;
    protected ?string $reason;

    public function __construct(MRF $mrf, string $rejectorName, ?string $reason = null)
    {
        $this->mrf = $mrf;
        $this->rejectorName = $rejectorName;
        $this->reason = $reason;
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

        return (new MailMessage)
            ->subject("MRF Rejected - {$this->mrf->mrf_id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your Material Requisition Form has been rejected.")
            ->line("**MRF Number:** {$this->mrf->mrf_id}")
            ->line("**Rejected By:** {$this->rejectorName}")
            ->when($this->reason, fn($mail) => $mail->line("**Reason:** {$this->reason}"))
            ->action('View MRF Details', $mrfUrl)
            ->line('Please review and resubmit if necessary.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'mrf_rejected',
            'title' => 'MRF Rejected',
            'message' => "Your Material Requisition Form ({$this->mrf->mrf_id}) has been rejected by {$this->rejectorName}",
            'mrf_id' => $this->mrf->id,
            'mrf_number' => $this->mrf->mrf_id,
            'rejector' => $this->rejectorName,
            'reason' => $this->reason,
            'action_url' => "/mrfs/{$this->mrf->mrf_id}",
            'icon' => 'x-circle',
            'color' => 'red',
            'priority' => 'high',
        ];
    }
}
