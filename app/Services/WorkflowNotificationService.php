<?php

namespace App\Services;

use App\Mail\MRFCreatedMail;
use App\Mail\MRFApprovedMail;
use App\Mail\MRFRejectedMail;
use App\Mail\POGeneratedMail;
use App\Mail\QuotationSubmittedMail;
use App\Mail\RFQSentMail;
use App\Mail\SRFCreatedMail;
use App\Mail\VendorQuoteApprovedMail;
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
        $emails = collect([
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
        ]);

        $emails = $emails->merge(collect(config('scm.logistics_notification_cc_emails', [])));

        foreach ($emails->filter()->unique(fn ($e) => strtolower((string) $e))->values() as $email) {
            $this->deliverMailable(
                event: 'mrf_created',
                recipient: (string) $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new MRFCreatedMail($mrf)
            );
        }
    }

    public function notifySRFSubmitted(SRF $srf): void
    {
        $emails = collect([
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
            'bunmi.babajide@emeraldcfze.com',
        ]);

        $stage = strtolower((string) ($srf->current_stage ?? ''));
        if ($stage === 'supply_chain_director_review' || ($srf->origin ?? null) === 'fleet_dashboard') {
            $emails = $emails->merge(
                User::query()
                    ->whereIn('role', ['supply_chain_director', 'supply_chain'])
                    ->whereNotNull('email')
                    ->pluck('email')
            );
        }

        $emails = $emails->merge(
            User::query()
                ->whereIn('role', ['logistics_manager', 'logistics_officer'])
                ->whereNotNull('email')
                ->pluck('email')
        );

        $emails = $emails->merge(collect(config('scm.logistics_notification_cc_emails', [])));

        foreach ($emails->filter()->unique(fn ($e) => strtolower((string) $e))->values() as $email) {
            $this->deliverMailable(
                event: 'srf_created',
                recipient: (string) $email,
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
        ])
            ->merge(config('scm.po_generated_to_recipients', []))
            ->merge(config('scm.po_cc_recipients', []))
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
        $emails = collect([
            'viva.musa@emeraldcfze.com',
            'lateef.olanrewaju@emeraldcfze.com',
        ]);

        $emails = $emails->merge(collect(config('scm.logistics_notification_cc_emails', [])));

        foreach ($emails->filter()->unique(fn ($e) => strtolower((string) $e))->values() as $email) {
            $this->deliverMailable(
                event: 'quotation_submitted',
                recipient: (string) $email,
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

    public function notifyVendorQuoteScdApproved(MRF $mrf): void
    {
        if (! mrfUsesFinanceAp($mrf)) {
            return;
        }

        $mrf->loadMissing(['selectedVendor']);
        $quotation = $mrf->selectedQuotation();

        if (! $quotation) {
            Log::warning('SCD vendor quote approval notification skipped; no selected quotation', [
                'mrf_id' => $mrf->mrf_id,
            ]);

            return;
        }

        $quotation->loadMissing(['vendor', 'rfq']);
        $gate = app(\App\Services\FinanceAp\VendorInvoiceGateService::class)->status($mrf->fresh());

        $vendorEmails = collect([
            $quotation->vendor?->email ?? null,
            User::query()->where('vendor_id', $quotation->vendor_id)->value('email'),
        ])->filter()->unique(fn ($email) => strtolower((string) $email));

        foreach ($vendorEmails as $email) {
            $this->deliverMailable(
                event: 'vendor_quote_scd_approved',
                recipient: (string) $email,
                modelId: $mrf->mrf_id,
                mailableFactory: fn () => new VendorQuoteApprovedMail(
                    $mrf,
                    $quotation,
                    (bool) $gate['canSubmit'],
                    $gate['gateType'],
                )
            );
        }

        $vendorUser = User::query()->where('vendor_id', $quotation->vendor_id)->first();
        if ($vendorUser) {
            try {
                $vendorUser->notify(new \App\Notifications\VendorQuoteApprovedNotification(
                    $mrf,
                    $quotation,
                    (bool) $gate['canSubmit'],
                    $gate['gateType'],
                ));
            } catch (\Throwable $e) {
                Log::error('Vendor quote approved in-app notification failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'user_id' => $vendorUser->id,
                    'error' => $e->getMessage(),
                ]);
            }
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
