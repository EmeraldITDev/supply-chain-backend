<?php

namespace App\Notifications;

use App\Models\MRF;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MRFRequesterUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected MRF $mrf,
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
        $this->mrf->loadMissing('requester');
        $mrfNumber = $this->mrf->formatted_id ?: $this->mrf->mrf_id;
        $summary = $this->changeSummary ?: 'Request details were updated.';

        return (new MailMessage)
            ->subject("MRF Updated - {$mrfNumber}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Material Request Form {$mrfNumber} was updated by {$this->editor->name}.")
            ->line("**Changes:** {$summary}")
            ->action('Review MRF', url('/mrfs/' . rawurlencode((string) $mrfNumber)))
            ->line('Please review the updated request in the supply chain portal.');
    }

    public function toArray(object $notifiable): array
    {
        $mrfNumber = $this->mrf->formatted_id ?: $this->mrf->mrf_id;
        $summary = $this->changeSummary ?: 'Request details were updated.';

        return [
            'type' => 'mrf.requester_updated',
            'title' => 'MRF updated by requester',
            'message' => "MRF {$mrfNumber} was updated by {$this->editor->name}. {$summary}",
            'mrf_id' => $this->mrf->id,
            'mrf_number' => $mrfNumber,
            'editor_id' => $this->editor->id,
            'editor_name' => $this->editor->name,
            'change_summary' => $summary,
            'action_url' => '/mrfs/' . $mrfNumber,
            'icon' => 'pencil',
            'color' => 'amber',
            'priority' => 'normal',
        ];
    }
}
