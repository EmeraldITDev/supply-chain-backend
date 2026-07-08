<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripChangesRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Trip $trip,
        public User $requester,
        public User $reviewer,
        public string $reason,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Changes requested on trip request — ' . ($this->trip->trip_code ?? 'Trip'))
            ->view('emails.trip-changes-requested', [
                'requesterName' => $this->requester->name,
                'reviewerName' => $this->reviewer->name,
                'tripCode' => $this->trip->trip_code,
                'origin' => $this->trip->origin,
                'destination' => $this->trip->destination,
                'reason' => $this->reason,
            ]);
    }
}
