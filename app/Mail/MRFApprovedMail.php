<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MRFApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $mrf;
    public $mrfUrl;

    public function __construct($mrf)
    {
        $this->mrf = $mrf;
    }

    public function build(): self
    {
        // Build correct URL based on recipient's procurement access
        $recipient = $this->getRecipientEmail();
        $user = User::where('email', $recipient)->first();

        if ($user && in_array($user->role, [
            'procurement',
            'procurement_manager',
            'supply_chain_director',
            'supply_chain',
            'admin'
        ])) {
            $this->mrfUrl = 'https://scm.emeraldcfze.com/procurement';
        } else {
            $this->mrfUrl = 'https://scm.emeraldcfze.com';
        }

        return $this
            ->subject('MRF Approved - ' . $this->mrf->mrf_id)
            ->view('emails.mrf-approved');
    }

    private function getRecipientEmail(): ?string
    {
        // Extract recipient email from the mailable's 'to' property
        if (isset($this->to) && is_array($this->to) && count($this->to) > 0) {
            $toAddress = $this->to[0];
            return $toAddress['address'] ?? $toAddress;
        }
        return null;
    }
}
