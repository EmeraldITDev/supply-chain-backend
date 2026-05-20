<?php

namespace App\Mail;

use App\Models\Logistics\FleetDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DriverAssignmentManagerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public FleetDriver $driver,
        public ?string $vehicleLabel = null,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Driver assigned — ' . $this->driver->name)
            ->view('emails.driver-assignment-manager');
    }
}
