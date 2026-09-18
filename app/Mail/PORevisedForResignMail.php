<?php

namespace App\Mail;

use App\Models\MRF;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PORevisedForResignMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public MRF $mrf,
        public array $payload,
    ) {
    }

    public function build(): self
    {
        $poNumber = $this->payload['po_number'] ?? $this->mrf->po_number ?? $this->mrf->mrf_id;

        return $this
            ->subject('Revised Purchase Order requires a new signature — '.$poNumber)
            ->view('emails.po-revised-for-resign')
            ->with($this->payload);
    }
}
