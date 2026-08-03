<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripRequestDirectorApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Trip $trip,
        public User $approver,
    ) {
    }

    public function build(): self
    {
        $departure = $this->trip->scheduled_departure_at?->format('l, F j, Y \a\t g:i A');

        return $this
            ->subject('Trip request approved by Supply Chain Director — ' . ($this->trip->trip_code ?? $this->trip->destination))
            ->view('emails.trip-request-director-approved', [
                'approverName' => $this->approver->name,
                'tripCode' => $this->trip->trip_code,
                'origin' => $this->trip->origin,
                'destination' => $this->trip->destination,
                'purpose' => $this->trip->purpose,
                'departure' => $departure,
                'requesterName' => $this->trip->creator?->name,
                'deepLink' => '/trip-requests/' . $this->trip->id,
            ]);
    }
}
