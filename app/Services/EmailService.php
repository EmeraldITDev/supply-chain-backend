<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Send vendor invitation email
     */
    public function sendVendorInvitation(string $email, string $companyName): bool
    {
        try {
            Mail::send('emails.vendor-invitation', [
                'companyName' => $companyName,
                'registrationUrl' => config('app.frontend_url') . '/vendor-portal',
                'portalUrl' => config('app.frontend_url'),
            ], function ($message) use ($email, $companyName) {
                $message->to($email)
                    ->subject('Invitation to Register as a Vendor - ' . config('app.name'));
            });

            Log::info('Vendor invitation email sent', [
                'email' => $email,
                'company' => $companyName
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send vendor invitation email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send vendor approval notification with credentials
     */
    public function sendVendorApprovalEmail(
        string $email,
        string $companyName,
        string $temporaryPassword
    ): bool {
        try {
            Mail::send('emails.vendor-approval', [
                'companyName' => $companyName,
                'email' => $email,
                'temporaryPassword' => $temporaryPassword,
                'loginUrl' => config('app.frontend_url') . '/vendor-portal',
                'changePasswordUrl' => config('app.frontend_url') . '/vendor-portal',
            ], function ($message) use ($email, $companyName) {
                $message->to($email)
                    ->subject('Vendor Registration Approved - ' . config('app.name'));
            });

            Log::info('Vendor approval email sent', [
                'email' => $email,
                'company' => $companyName
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send vendor approval email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(
        string $email,
        string $name,
        string $temporaryPassword
    ): bool {
        try {
            Mail::send('emails.password-reset', [
                'name' => $name,
                'temporaryPassword' => $temporaryPassword,
                'loginUrl' => config('app.frontend_url') . '/vendor-portal',
            ], function ($message) use ($email) {
                $message->to($email)
                    ->subject('Password Reset - ' . config('app.name'));
            });

            Log::info('Password reset email sent', ['email' => $email]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send document expiry reminder
     */
    public function sendDocumentExpiryReminder(
        string $email,
        string $companyName,
        array $expiringDocuments
    ): bool {
        try {
            Mail::send('emails.document-expiry', [
                'companyName' => $companyName,
                'documents' => $expiringDocuments,
                'portalUrl' => config('app.frontend_url') . '/vendor-portal',
            ], function ($message) use ($email) {
                $message->to($email)
                    ->subject('Document Expiry Reminder - ' . config('app.name'));
            });

            Log::info('Document expiry reminder sent', ['email' => $email]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send document expiry reminder', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send RFQ notification to vendor
     */
    public function sendRFQNotification(
        string $email,
        string $companyName,
        string $rfqId,
        string $rfqTitle,
        string $deadline
    ): bool {
        try {
            Mail::send('emails.rfq-notification', [
                'companyName' => $companyName,
                'rfqId' => $rfqId,
                'rfqTitle' => $rfqTitle,
                'deadline' => $deadline,
                'rfqUrl' => config('app.frontend_url') . '/vendor-portal',
            ], function ($message) use ($email, $rfqId) {
                $message->to($email)
                    ->subject("New RFQ Assigned: {$rfqId} - " . config('app.name'));
            });

            Log::info('RFQ notification sent', [
                'email' => $email,
                'rfq_id' => $rfqId
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send RFQ notification', [
                'email' => $email,
                'rfq_id' => $rfqId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send quotation status notification
     */
    public function sendQuotationStatusNotification(
        string $email,
        string $companyName,
        string $quotationId,
        string $status,
        ?string $remarks = null
    ): bool {
        try {
            Mail::send('emails.quotation-status', [
                'companyName' => $companyName,
                'quotationId' => $quotationId,
                'status' => $status,
                'remarks' => $remarks,
                'portalUrl' => config('app.frontend_url') . '/vendor-portal',
            ], function ($message) use ($email, $quotationId, $status) {
                $message->to($email)
                    ->subject("Quotation {$quotationId} - {$status} - " . config('app.name'));
            });

            Log::info('Quotation status notification sent', [
                'email' => $email,
                'quotation_id' => $quotationId,
                'status' => $status
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send quotation status notification', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generic email sender for custom notifications
     */
    public function sendCustomEmail(
        string $email,
        string $subject,
        string $template,
        array $data = []
    ): bool {
        try {
            Mail::send($template, $data, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });

            Log::info('Custom email sent', [
                'email' => $email,
                'template' => $template
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send custom email', [
                'email' => $email,
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
