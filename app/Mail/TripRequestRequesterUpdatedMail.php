<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripRequestRequesterUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Trip $trip,
        public User $editor,
        public ?string $changeSummary = null,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Trip request updated — ' . ($this->trip->trip_code ?? $this->trip->destination))
            ->view('emails.trip-request-requester-updated', [
                'editorName' => $this->editor->name,
                'tripCode' => $this->trip->trip_code,
                'origin' => $this->trip->origin,
                'destination' => $this->trip->destination,
                'changeSummary' => $this->changeSummary ?: 'Request details were updated.',
            ]);
    }
}
