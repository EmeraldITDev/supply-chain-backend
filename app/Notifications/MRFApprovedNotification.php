<?php

namespace App\Notifications;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

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
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'https://emerald-supply-chain.vercel.app');
        $mrfUrl = rtrim($frontendUrl, '/') . '/mrfs/' . $this->mrf->mrf_id;

        return (new MailMessage)
            ->subject("MRF Approved - {$this->mrf->mrf_id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your Material Requisition Form has been approved.")
            ->line("**MRF Number:** {$this->mrf->mrf_id}")
            ->line("**Approved By:** {$this->approverName}")
            ->when($this->remarks, fn($mail) => $mail->line("**Remarks:** {$this->remarks}"))
            ->action('View MRF Details', $mrfUrl)
            ->line('The MRF is now moving to the next approval stage.');
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
            'action_url' => "/mrfs/{$this->mrf->mrf_id}",
            'icon' => 'check-circle',
            'color' => 'green',
            'priority' => 'normal',
        ];
    }
}
