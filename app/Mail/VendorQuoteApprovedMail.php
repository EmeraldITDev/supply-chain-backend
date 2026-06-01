<?php

namespace App\Mail;

use App\Models\MRF;
use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorQuoteApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public MRF $mrf,
        public Quotation $quotation,
        public bool $invoiceGateOpen,
        public ?string $gateType = null,
    ) {
    }

    public function build(): self
    {
        $portalUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/vendor-portal';
        $invoiceUrl = $portalUrl . '/mrfs/' . rawurlencode($this->mrf->mrf_id) . '/invoice';

        return $this
            ->subject('Your quotation has been approved — ' . ($this->mrf->formatted_id ?: $this->mrf->mrf_id))
            ->view('emails.vendor-quote-approved')
            ->with([
                'mrf' => $this->mrf,
                'quotation' => $this->quotation,
                'invoiceGateOpen' => $this->invoiceGateOpen,
                'gateType' => $this->gateType,
                'vendorPortalUrl' => $portalUrl,
                'invoiceUploadUrl' => $invoiceUrl,
            ]);
    }
}
