<?php

namespace App\Mail;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class POReadyForSignatureMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public MRF $mrf)
    {
    }

    public function build(): self
    {
        $poNumber = $this->mrf->po_number ?? $this->mrf->mrf_id;

        return $this
            ->subject('PO Ready for Signature - '.$poNumber)
            ->view('emails.po-ready-for-signature')
            ->with([
                'signingUrl' => rtrim((string) config('app.frontend_url'), '/').'/mrfs/'.$this->mrf->mrf_id,
            ]);
    }
}
