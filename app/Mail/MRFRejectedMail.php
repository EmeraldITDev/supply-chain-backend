<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MRFRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $mrf;
    public $remarks;

    public function __construct($mrf, ?string $remarks = null)
    {
        $this->mrf = $mrf;
        $this->remarks = $remarks;
    }

    public function build(): self
    {
        return $this
            ->subject('MRF Rejected - ' . $this->mrf->mrf_id)
            ->view('emails.mrf-rejected');
    }
}
