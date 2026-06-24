<?php

namespace App\Notifications;

use App\Models\SRF;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SRFRequesterUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected SRF $srf,
        protected User $editor,
        protected ?string $changeSummary = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $srfNumber = $this->srf->formatted_id ?: $this->srf->srf_id;
        $summary = $this->changeSummary ?: 'Request details were updated.';

        return (new MailMessage)
            ->subject("SRF Updated - {$srfNumber}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Service Request Form {$srfNumber} was updated by {$this->editor->name}.")
            ->line("**Changes:** {$summary}")
            ->action('Review SRF', url('/srfs/' . rawurlencode((string) $srfNumber)))
            ->line('Please review the updated request in the supply chain portal.');
    }

    public function toArray(object $notifiable): array
    {
        $srfNumber = $this->srf->formatted_id ?: $this->srf->srf_id;
        $summary = $this->changeSummary ?: 'Request details were updated.';

        return [
            'type' => 'srf.requester_updated',
            'title' => 'SRF updated by requester',
            'message' => "SRF {$srfNumber} was updated by {$this->editor->name}. {$summary}",
            'srf_id' => $this->srf->id,
            'srf_number' => $srfNumber,
            'editor_id' => $this->editor->id,
            'editor_name' => $this->editor->name,
            'change_summary' => $summary,
            'action_url' => '/srfs/' . $srfNumber,
            'icon' => 'pencil',
            'color' => 'amber',
            'priority' => 'normal',
        ];
    }
}
