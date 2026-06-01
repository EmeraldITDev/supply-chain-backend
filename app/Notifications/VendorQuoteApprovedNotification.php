<?php

namespace App\Notifications;

use App\Models\MRF;
use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorQuoteApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private MRF $mrf,
        private Quotation $quotation,
        private bool $invoiceGateOpen,
        private ?string $gateType = null,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $message = $this->invoiceGateOpen
            ? 'Your quotation has been approved. You may submit your final invoice and supporting documents.'
            : (($this->gateType === 'delivery')
                ? 'Your quotation has been approved. Submit your final invoice after delivery confirmation and GRN completion.'
                : 'Your quotation has been approved. We will notify you when invoice submission opens.');

        return [
            'type' => 'vendor_quote_approved',
            'title' => 'Quotation Approved',
            'message' => $message,
            'mrf_id' => $this->mrf->mrf_id,
            'quotation_id' => $this->quotation->quotation_id,
            'invoice_gate_open' => $this->invoiceGateOpen,
            'gate_type' => $this->gateType,
            'action_url' => '/vendor-portal/mrfs/' . rawurlencode($this->mrf->mrf_id) . '/invoice',
            'icon' => 'check-circle',
            'color' => 'green',
            'priority' => 'high',
        ];
    }
}
