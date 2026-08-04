<?php

namespace App\Mail;

use App\Models\Logistics\Trip;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TripRequestForwardedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Trip $trip,
        public User $forwardedBy,
    ) {
    }

    public function build(): self
    {
        $departure = $this->formatDeparture($this->trip->scheduled_departure_at ?? null);

        return $this
            ->subject('Trip request forwarded for your review — ' . ($this->trip->trip_code ?? $this->trip->destination ?? 'trip request'))
            ->view('emails.trip-request-forwarded', [
                'forwardedByName' => $this->forwardedBy->name ?? 'A team member',
                'tripCode' => $this->trip->trip_code ?? '—',
                'origin' => $this->trip->origin ?? '—',
                'destination' => $this->trip->destination ?? '—',
                'purpose' => $this->trip->purpose ?? null,
                'departure' => $departure,
            ]);
    }

    private function formatDeparture(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('l, F j, Y \a\t g:i A');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format('l, F j, Y \a\t g:i A');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
