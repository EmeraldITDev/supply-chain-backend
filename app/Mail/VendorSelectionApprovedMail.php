<?php

namespace App\Mail;

use App\Models\MRF;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorSelectionApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MRF $mrf,
        public User $approver,
    ) {
    }

    public function build(): self
    {
        $label = $this->mrf->formatted_id ?: $this->mrf->mrf_id;

        return $this
            ->subject('Vendor Selection Approved — PO Upload Required - '.$label)
            ->view('emails.vendor-selection-approved')
            ->with([
                'poUrl' => rtrim((string) config('app.frontend_url'), '/').'/mrfs/'.$this->mrf->mrf_id,
            ]);
    }
}
