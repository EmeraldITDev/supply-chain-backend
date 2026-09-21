<?php

namespace App\Notifications;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProcurementActionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected MRF $mrf,
        protected int $hoursPending,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $label = $this->mrf->formatted_id ?: $this->mrf->mrf_id;

        return (new MailMessage)
            ->subject("Procurement action reminder — {$label}")
            ->greeting("Hello {$notifiable->name},")
            ->line('A procurement item is awaiting action and may be overdue.')
            ->line("**MRF:** {$label}")
            ->line('**Title:** '.($this->mrf->title ?? 'N/A'))
            ->line('**Workflow state:** '.($this->mrf->workflow_state ?? 'unknown'))
            ->line("**Pending for approximately:** {$this->hoursPending} hour(s)")
            ->line('This is a reminder only. No records were approved, rejected, or closed automatically.')
            ->action('Open SCM Portal', 'https://scm.emeraldcfze.com/procurement')
            ->line('Please review and take the appropriate action.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'procurement_action_reminder',
            'mrf_id' => $this->mrf->mrf_id,
            'formatted_id' => $this->mrf->formatted_id,
            'title' => $this->mrf->title,
            'workflow_state' => $this->mrf->workflow_state,
            'hours_pending' => $this->hoursPending,
            'message' => 'Procurement action reminder — no automatic status changes were applied.',
        ];
    }
}
