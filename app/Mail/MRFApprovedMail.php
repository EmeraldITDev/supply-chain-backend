<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MRFApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $mrf;

    public function __construct($mrf)
    {
        $this->mrf = $mrf;
    }

    public function build(): self
    {
        return $this
            ->subject('MRF Approved - ' . $this->mrf->mrf_id)
            ->view('emails.mrf-approved');
    }
}
