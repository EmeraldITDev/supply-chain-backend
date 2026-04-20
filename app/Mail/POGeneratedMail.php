<?php

namespace App\Mail;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class POGeneratedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public MRF $mrf;

    public function __construct(MRF $mrf)
    {
        $this->mrf = $mrf;
    }

    public function build(): self
    {
        return $this
            ->subject('Purchase Order Generated - ' . ($this->mrf->po_number ?? $this->mrf->mrf_id))
            ->view('emails.po-generated');
    }
}
