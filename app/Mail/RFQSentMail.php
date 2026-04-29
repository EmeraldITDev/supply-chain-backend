<?php

namespace App\Mail;

use App\Models\RFQ;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RFQSentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public RFQ $rfq;
    public Vendor $vendor;

    public function __construct(RFQ $rfq, Vendor $vendor)
    {
        $this->rfq = $rfq;
        $this->vendor = $vendor;
    }

    public function build(): self
    {
        $deadline = $this->rfq->deadline ? $this->rfq->deadline->format('Y-m-d') : 'Not specified';

        return $this
            ->subject('New RFQ Assigned - ' . $this->rfq->rfq_id)
            ->view('emails.rfq-notification')
            ->with([
                'companyName' => $this->vendor->name,
                'rfqId' => $this->rfq->rfq_id,
                'rfqTitle' => method_exists($this->rfq, 'getDisplayTitle')
                    ? $this->rfq->getDisplayTitle()
                    : ($this->rfq->title ?? $this->rfq->description),
                'deadline' => $deadline,
                'rfqUrl' => rtrim((string) config('app.frontend_url'), '/') . '/vendor-portal',
            ]);
    }
}
