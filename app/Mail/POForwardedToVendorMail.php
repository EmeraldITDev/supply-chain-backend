<?php

namespace App\Mail;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class POForwardedToVendorMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MRF $mrf,
        public bool $hasAttachment = false,
    ) {
    }

    public function build(): self
    {
        $poNumber = $this->mrf->po_number ?? $this->mrf->mrf_id;

        return $this
            ->subject('Signed Purchase Order - '.$poNumber)
            ->view('emails.po-forwarded-to-vendor');
    }
}
