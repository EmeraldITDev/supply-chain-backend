<?php

namespace App\Services;

use App\Mail\MRFCreatedMail;
use App\Mail\MRFApprovedMail;
use App\Mail\MRFRejectedMail;
use App\Mail\POGeneratedMail;
use App\Mail\QuotationSubmittedMail;
use App\Mail\RFQSentMail;
use App\Mail\SRFCreatedMail;
use App\Mail\VendorSelectedMail;
use App\Models\MRF;
use App\Models\Quotation;
use App\Models\RFQ;
use App\Models\SRF;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class WorkflowNotificationService
{
    public function notifyMRFSubmitted(MRF $mrf): void
    {
        // Temporary debug routing: only send new MRF notifications to Viva and Lateef.
        // Intentionally misspelled emails for controlled debugging.
        $emails = [
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
        ];

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'mrf_created',
                recipient: $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new MRFCreatedMail($mrf)
            );
        }
    }

    public function notifySRFSubmitted(SRF $srf): void
    {
        // Send to specific procurement team members
        $emails = [
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
            'bunmi.babajide@emeraldcfze.com',
        ];

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'srf_created',
                recipient: $email,
                modelId: $srf->formatted_id ?: $srf->srf_id,
                mailableFactory: static fn () => new SRFCreatedMail($srf)
            );
        }
    }

    public function notifyMRFApproved(MRF $mrf): void
    {
        $emails = collect([
            $mrf->requester?->email ?? null,
        ])
        ->filter()
        ->unique()
        ->values()
        ->toArray();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'mrf_approved',
                recipient: $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new MRFApprovedMail($mrf)
            );
        }
    }

    public function notifyMRFRejected(MRF $mrf, ?string $remarks = null): void
    {
        $emails = collect([
            $mrf->requester?->email ?? null,
        ])
        ->filter()
        ->unique()
        ->values()
        ->toArray();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'mrf_rejected',
                recipient: $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new MRFRejectedMail($mrf, $remarks)
            );
        }
    }

    public function notifyRFQSent(RFQ $rfq): void
    {
        $rfq->loadMissing('vendors');

        foreach ($rfq->vendors as $vendor) {
            $emails = collect([
                $vendor->email ?? null,
                User::where('vendor_id', $vendor->id)->value('email'),
            ])->filter()->unique()->values()->toArray();

            foreach ($emails as $email) {
                $this->deliverMailable(
                    event: 'rfq_sent',
                    recipient: $email,
                    modelId: $rfq->formatted_id ?: $rfq->rfq_id,
                    mailableFactory: static fn () => new RFQSentMail($rfq, $vendor)
                );
            }
        }
    }

    public function notifyPOGenerated(MRF $mrf): void
    {
        $mrf->loadMissing(['requester', 'selectedVendor']);

        $emails = collect([
            $mrf->requester?->email ?? null,
            $mrf->selectedVendor?->email ?? null,
        ])->merge([
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
            'bunmi.babajide@emeraldcfze.com',
        ])
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'po_generated',
                recipient: $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new POGeneratedMail($mrf)
            );
        }
    }

    public function notifyQuotationSubmitted(Quotation $quotation): void
    {
        $quotation->loadMissing(['rfq', 'vendor']);

        // Only send to specific recipients for quotation submissions
        $emails = [
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
        ];

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'quotation_submitted',
                recipient: $email,
                modelId: $quotation->quotation_id,
                mailableFactory: static fn () => new QuotationSubmittedMail($quotation)
            );
        }
    }

    public function notifyVendorSelected(Quotation $quotation): void
    {
        $quotation->loadMissing(['vendor', 'rfq.mrf.requester']);

        $emails = collect([
            $quotation->vendor?->email ?? null,
            $quotation->rfq?->mrf?->requester?->email ?? null,
        ])->filter()->unique()->values()->toArray();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'vendor_selected',
                recipient: $email,
                modelId: $quotation->quotation_id,
                mailableFactory: static fn () => new VendorSelectedMail($quotation)
            );
        }
    }

    private function getEmailsByRoles(array $roles): array
    {
        return User::whereIn('role', $roles)
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->toArray();
    }

    private function deliverMailable(string $event, string $recipient, string $modelId, callable $mailableFactory): void
    {
        try {
            $mail = Mail::to($recipient);

            // Queue when queue driver is async; send immediately only for sync queue.
            if (config('queue.default') !== 'sync') {
                $mail->queue($mailableFactory());
            } else {
                $mail->send($mailableFactory());
            }
        } catch (\Throwable $e) {
            Log::error('Workflow email dispatch failed', [
                'event' => $event,
                'recipient' => $recipient,
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
