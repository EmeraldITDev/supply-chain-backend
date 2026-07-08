<?php

namespace App\Notifications;

use App\Models\SRF;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SRFSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(protected SRF $srf)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->srf->loadMissing('requester');

        return [
            'type' => 'srf_submitted',
            'title' => 'New SRF Submitted',
            'message' => sprintf(
                'Service Requisition Form %s was submitted by %s and requires review.',
                $this->srf->formatted_id ?: $this->srf->srf_id,
                $this->srf->requester?->name ?? 'a staff member'
            ),
            'srf_id' => $this->srf->id,
            'srf_number' => $this->srf->formatted_id ?: $this->srf->srf_id,
            'requester' => $this->srf->requester?->name,
            'current_stage' => $this->srf->current_stage,
            'action_url' => '/srfs/' . ($this->srf->formatted_id ?: $this->srf->srf_id),
            'icon' => 'document',
            'color' => 'blue',
            'priority' => 'normal',
        ];
    }
}
