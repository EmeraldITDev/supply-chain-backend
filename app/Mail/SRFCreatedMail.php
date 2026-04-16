<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SRFCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $srf;

    public function __construct($srf)
    {
        $this->srf = $srf;
    }

    public function build(): self
    {
        return $this
            ->subject('New SRF Submitted - ' . $this->srf->srf_id)
            ->view('emails.srf-created');
    }
}
