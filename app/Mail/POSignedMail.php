<?php

namespace App\Mail;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class POSignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public MRF $mrf)
    {
    }

    public function build(): self
    {
        $poNumber = $this->mrf->po_number ?? $this->mrf->mrf_id;

        return $this
            ->subject('Purchase Order Signed - '.$poNumber)
            ->view('emails.po-signed')
            ->with([
                'poUrl' => rtrim((string) config('app.frontend_url'), '/').'/mrfs/'.$this->mrf->mrf_id,
            ]);
    }
}
