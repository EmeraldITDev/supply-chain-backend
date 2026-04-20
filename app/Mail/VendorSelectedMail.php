<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorSelectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Quotation $quotation;

    public function __construct(Quotation $quotation)
    {
        $this->quotation = $quotation;
    }

    public function build(): self
    {
        return $this
            ->subject('Vendor Selected - ' . $this->quotation->quotation_id)
            ->view('emails.vendor-selected');
    }
}
