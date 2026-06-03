<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripExternalPassengerConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{name: string, email: string, phone?: string|null}  $passenger
     */
    public function __construct(
        public Trip $trip,
        public array $passenger,
        public User $requester,
    ) {
    }

    public function build(): self
    {
        $departure = $this->trip->scheduled_departure_at?->format('l, F j, Y \a\t g:i A');
        $arrival = $this->trip->scheduled_arrival_at?->format('l, F j, Y \a\t g:i A');

        return $this
            ->subject('Trip confirmed — ' . ($this->trip->destination ?? 'Trip'))
            ->view('emails.trip-external-passenger-confirmed', [
                'passengerName' => $this->passenger['name'] ?? 'Guest',
                'destination' => $this->trip->destination,
                'purpose' => $this->trip->purpose,
                'origin' => $this->trip->origin,
                'departure' => $departure,
                'arrival' => $arrival,
                'requesterName' => $this->requester->name,
                'requesterEmail' => $this->requester->email,
                'requesterPhone' => $this->requester->phone ?? null,
                'tripCode' => $this->trip->trip_code,
            ]);
    }
}
