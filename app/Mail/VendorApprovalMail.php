<?php

namespace App\Mail;

use App\Models\VendorRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public VendorRegistration $registration;
    public string $temporaryPassword;

    /**
     * Create a new message instance.
     */
    public function __construct(VendorRegistration $registration, string $temporaryPassword)
    {
        $this->registration = $registration;
        $this->temporaryPassword = $temporaryPassword;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Emerald Partnership - Your Vendor Portal Access',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontendUrl = env('FRONTEND_URL', 'https://emerald-supply-chain.vercel.app');
        $vendorPortalUrl = rtrim($frontendUrl, '/') . '/vendor-portal';
        
        return new Content(
            view: 'emails.vendor-approval',
            with: [
                'vendorPortalUrl' => $vendorPortalUrl,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
