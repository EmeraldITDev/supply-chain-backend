<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Trip $trip,
        public User $requester,
    ) {
    }

    public function build(): self
    {
        $departure = $this->trip->scheduled_departure_at?->format('l, F j, Y \a\t g:i A');

        return $this
            ->subject('New trip request — ' . ($this->trip->destination ?? $this->trip->trip_code))
            ->view('emails.trip-request-submitted', [
                'requesterName' => $this->requester->name,
                'tripCode' => $this->trip->trip_code,
                'origin' => $this->trip->origin,
                'destination' => $this->trip->destination,
                'purpose' => $this->trip->purpose,
                'departure' => $departure,
            ]);
    }
}
