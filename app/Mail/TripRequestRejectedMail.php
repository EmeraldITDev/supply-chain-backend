<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripRequestRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Trip $trip,
        public ?User $reviewer = null,
        public ?string $reason = null,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Trip request rejected — ' . ($this->trip->trip_code ?? 'Trip'))
            ->view('emails.trip-request-rejected', [
                'requesterName' => $this->trip->creator?->name ?? 'Requester',
                'reviewerName' => $this->reviewer?->name,
                'tripCode' => $this->trip->trip_code,
                'origin' => $this->trip->origin,
                'destination' => $this->trip->destination,
                'reason' => $this->reason,
            ]);
    }
}
