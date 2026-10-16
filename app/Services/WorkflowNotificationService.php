<?php

namespace App\Services;

use App\Mail\MRFCreatedMail;
use App\Mail\MRFApprovedMail;
use App\Mail\MRFRejectedMail;
use App\Mail\POGeneratedMail;
use App\Mail\POReadyForSignatureMail;
use App\Mail\POForwardedToVendorMail;
use App\Mail\POSignedMail;
use App\Mail\VendorSelectionApprovedMail;
use App\Mail\QuotationSubmittedMail;
use App\Mail\RFQSentMail;
use App\Mail\SRFCreatedMail;
use App\Mail\TripApprovedMail;
use App\Mail\TripRequestDirectorApprovedMail;
use App\Mail\TripRequestForwardedMail;
use App\Mail\TripRequestSubmittedMail;
use App\Mail\VendorQuoteApprovedMail;
use App\Mail\VendorSelectedMail;
use App\Notifications\SystemAnnouncementNotification;
use App\Models\Logistics\Trip;
use App\Models\Logistics\TripRfq;
use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\Quotation;
use App\Models\RFQ;
use App\Models\SRF;
use App\Models\User;
use App\Notifications\SRFSubmittedNotification;
use App\Support\DatabaseNotifications;
use App\Support\LazarusNotificationSettings;
use App\Support\PurchaseOrderInvoiceCc;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WorkflowNotificationService
{
    public const LAZARUS_EMAIL = LazarusNotificationSettings::EMAIL;
    public function notifyMRFSubmitted(MRF $mrf): void
    {
        // Parallel first-approval recipients: executives + supply chain directors.
        $emails = collect($this->getEmailsByRoles([
            'executive',
            'admin',
            'supply_chain_director',
            'supply_chain',
        ]))
            ->push('bunmi.babajide@emeraldcfze.com')
            ->merge(collect(config('scm.logistics_notification_cc_emails', [])))
            ->filter()
            ->unique(fn ($e) => strtolower((string) $e))
            ->values();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'mrf_created',
                recipient: (string) $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new MRFCreatedMail($mrf)
            );
        }
    }

    public function notifyTripRequestSubmittedToEmail(Trip $trip, User $requester, string $email): void
    {
        $this->deliverMailable(
            event: 'trip_request_submitted',
            recipient: $email,
            modelId: $trip->trip_code,
            mailableFactory: static fn () => new TripRequestSubmittedMail($trip, $requester)
        );
    }

    public function notifyTripRequestForwardedToDirectorEmail(Trip $trip, User $forwardedBy, string $email): void
    {
        $this->deliverMailable(
            event: 'trip_request_forwarded',
            recipient: $email,
            modelId: $trip->trip_code,
            mailableFactory: static fn () => new TripRequestForwardedMail($trip, $forwardedBy)
        );
    }

    public function notifyTripRequestDirectorApprovedToEmail(Trip $trip, User $director, string $email): void
    {
        $this->deliverMailable(
            event: 'trip_request_director_approved',
            recipient: $email,
            modelId: $trip->trip_code,
            mailableFactory: static fn () => new TripRequestDirectorApprovedMail($trip, $director)
        );
    }

    public function notifyLogisticsManagerTripApproved(Trip $trip, User $approver): void
    {
        $managers = User::query()
            ->where('supply_chain_role', 'logistics_manager')
            ->whereNotNull('email')
            ->get();

        foreach ($managers as $manager) {
            DatabaseNotifications::send($manager, new SystemAnnouncementNotification(
                'Trip Request Approved',
                "Trip {$trip->trip_code} to {$trip->destination} has been approved by {$approver->name} and is ready for logistics processing.",
                [
                    'action_url' => "/logistics/trips/{$trip->id}",
                    'trip_code' => $trip->trip_code,
                    'destination' => $trip->destination,
                    'requester' => $trip->creator?->name,
                    'approved_at' => now()->toIso8601String(),
                ]
            ));

            Mail::to($manager->email)->queue(new TripRequestDirectorApprovedMail($trip, $approver));
        }
    }

    public function notifyScdTripPendingApproval(Trip $trip, User $forwardedBy): void
    {
        $directors = User::query()
            ->whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain'])
            ->get();

        foreach ($directors as $director) {
            DatabaseNotifications::send($director, new SystemAnnouncementNotification(
                'Trip Request Pending Your Approval',
                "Trip {$trip->trip_code} to {$trip->destination} has been forwarded for your approval by {$forwardedBy->name}.",
                [
                    'action_url' => "/supply-chain/trips/{$trip->id}",
                    'trip_code' => $trip->trip_code,
                    'destination' => $trip->destination,
                ]
            ));

            if (! empty($director->email)) {
                $this->notifyTripRequestForwardedToDirectorEmail($trip, $forwardedBy, (string) $director->email);
            }
        }
    }

    public function notifyRequesterChangesRequired(Trip $trip, User $reviewer, ?string $comments): void
    {
        if (! $trip->creator) {
            return;
        }

        DatabaseNotifications::send($trip->creator, new SystemAnnouncementNotification(
            'Changes Requested on Your Trip',
            "Your trip request {$trip->trip_code} has been returned for changes. Reason: " . ($comments ?? 'Please review and resubmit.'),
            [
                'action_url' => "/trip-requests/{$trip->id}",
                'trip_code' => $trip->trip_code,
            ]
        ));
    }

    public function notifyRequesterTripRejected(Trip $trip, User $reviewer, ?string $remarks = null): void
    {
        if (! $trip->creator) {
            return;
        }

        DatabaseNotifications::send($trip->creator, new SystemAnnouncementNotification(
            'Trip Request Rejected',
            "Your trip request {$trip->trip_code} has been rejected. Reason: " . ($remarks ?? 'No additional reason provided.'),
            [
                'action_url' => "/trip-requests/{$trip->id}",
                'trip_code' => $trip->trip_code,
            ]
        ));
    }

    public function evaluatePostApprovalRouting(Trip $trip, User $approver): void
    {
        $hasExternalVendors = $trip->rfqs()->where('scd_approved', true)->exists();

        if ($hasExternalVendors) {
            $trip->update(['status' => Trip::STATUS_PROCUREMENT_PENDING]);
            $this->notifyProcurementManagerTripApproved($trip, $approver);
        } else {
            $trip->update(['status' => Trip::STATUS_JOURNEY_ACTIVE]);
            $this->notifyPassengersTripConfirmed($trip);
        }
    }

    public function notifyProcurementManagerTripApproved(Trip $trip, User $approver): void
    {
        $managers = User::query()
            ->whereIn('supply_chain_role', ['procurement_manager', 'procurement'])
            ->get();

        foreach ($managers as $manager) {
            DatabaseNotifications::send($manager, new SystemAnnouncementNotification(
                'Logistics Request Approved — PO Required',
                "Trip {$trip->trip_code} to {$trip->destination} has been approved by the Supply Chain Director. A Purchase Order is required.",
                [
                    'action_url' => "/procurement/logistics-trips/{$trip->id}",
                    'trip_code' => $trip->trip_code,
                    'destination' => $trip->destination,
                    'approved_by' => $approver->name,
                    'approved_at' => now()->toIso8601String(),
                ]
            ));

            Mail::to($manager->email)->queue(new \App\Mail\LogisticsTripApprovedMail($trip, $approver, $manager));
        }
    }

    public function notifyPassengersTripConfirmed(Trip $trip): void
    {
        $passengerIds = $trip->passenger_user_ids ?? [];
        $passengers = User::query()->whereIn('id', $passengerIds)->get();

        foreach ($passengers as $passenger) {
            DatabaseNotifications::send($passenger, new SystemAnnouncementNotification(
                'Trip confirmed',
                "Your trip {$trip->trip_code} to {$trip->destination} has been confirmed and is now active.",
                [
                    'action_url' => "/trip-requests/{$trip->id}",
                    'trip_code' => $trip->trip_code,
                    'destination' => $trip->destination,
                ]
            ));
        }
    }

    public function notifyVendorRfqSent(TripRfq $rfq, Trip $trip): void
    {
        $vendor = $rfq->vendor;
        if (! $vendor) {
            return;
        }

        DatabaseNotifications::send($vendor->email ? null : null, new SystemAnnouncementNotification(
            'RFQ sent',
            "A new RFQ for {$trip->trip_code} has been sent to {$vendor->name}.",
            [
                'action_url' => "/vendor/rfqs/{$rfq->id}",
                'trip_code' => $trip->trip_code,
                'vendor_name' => $vendor->name,
            ]
        ));
    }

    public function notifyLogisticsManagerAllQuotationsReceived(Trip $trip): void
    {
        $managers = User::query()->whereIn('supply_chain_role', ['logistics_manager', 'logistics_officer', 'admin'])->get();

        foreach ($managers as $manager) {
            DatabaseNotifications::send($manager, new SystemAnnouncementNotification(
                'All quotations received',
                "All vendor quotations for trip {$trip->trip_code} have been received.",
                [
                    'action_url' => "/logistics/trips/{$trip->id}",
                    'trip_code' => $trip->trip_code,
                ]
            ));
        }
    }

    public function notifyLogisticsManagerNewTrip(Trip $trip, User $user): void
    {
        $managers = User::query()->whereIn('supply_chain_role', ['logistics_manager', 'logistics_officer', 'admin'])->get();

        foreach ($managers as $manager) {
            DatabaseNotifications::send($manager, new SystemAnnouncementNotification(
                'New trip request submitted',
                "Trip {$trip->trip_code} has been submitted by {$user->name} and is awaiting review.",
                [
                    'action_url' => "/logistics/trips/{$trip->id}",
                    'trip_code' => $trip->trip_code,
                    'requester' => $user->name,
                ]
            ));
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
                    ->whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain'])
                    ->whereNotNull('email')
                    ->pluck('email')
            );
        }

        $emails = $emails->merge(
            User::query()
                ->whereIn('supply_chain_role', ['logistics_manager', 'logistics_officer'])
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

        $this->notifySRFSubmittedInApp($srf);
    }

    private function notifySRFSubmittedInApp(SRF $srf): void
    {
        try {
            $srf->loadMissing('requester');

            $roles = ['logistics_manager', 'logistics_officer', 'procurement_manager', 'procurement'];
            $stage = strtolower((string) ($srf->current_stage ?? ''));
            if ($stage === 'supply_chain_director_review' || ($srf->origin ?? null) === 'fleet_dashboard') {
                $roles[] = 'supply_chain_director';
                $roles[] = 'supply_chain';
            }

            $notifiables = User::query()
                ->whereIn('supply_chain_role', array_unique($roles))
                ->get();

            DatabaseNotifications::sendMany($notifiables, new SRFSubmittedNotification($srf));
        } catch (\Throwable $e) {
            Log::error('SRF submitted in-app notification failed', [
                'srf_id' => $srf->srf_id,
                'error' => $e->getMessage(),
            ]);
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
            ->merge($this->getEmailsByRoles(['supply_chain_director', 'supply_chain']))
            ->merge(config('scm.po_cc_recipients', []))
            ->push(\App\Support\PurchaseOrderInvoiceCc::PROCUREMENT_EMAIL)
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

    /**
     * Email the people who must sign a newly generated purchase order.
     */
    public function notifyPOReadyForSignature(MRF $mrf): void
    {
        $mrf->loadMissing(['requester', 'selectedVendor']);

        $emails = collect($this->getEmailsByRoles(['supply_chain_director', 'supply_chain']))
            ->merge(config('scm.po_generated_to_recipients', []))
            ->merge(config('scm.po_cc_recipients', []))
            ->filter()
            ->unique(fn ($email) => strtolower((string) $email))
            ->values()
            ->all();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'po_ready_for_signature',
                recipient: (string) $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new POReadyForSignatureMail($mrf)
            );
        }
    }

    /**
     * Email finance and the requester after a purchase order is signed.
     * The vendor is not included. Supply Chain forwards that copy separately.
     */
    public function notifyPOSigned(MRF $mrf): void
    {
        $mrf->loadMissing(['requester']);

        $emails = collect([
            $mrf->requester?->email ?? null,
        ])
            ->merge($this->getEmailsByRoles(['finance', 'finance_officer', 'admin']))
            ->merge(config('scm.po_cc_recipients', []))
            ->push(PurchaseOrderInvoiceCc::PROCUREMENT_EMAIL)
            ->filter()
            ->reject(fn ($email) => $this->isLazarusEmail($email)
                && ! LazarusNotificationSettings::enabled('po_signed'))
            ->unique(fn ($email) => strtolower((string) $email))
            ->values()
            ->all();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'po_signed',
                recipient: (string) $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new POSignedMail($mrf)
            );
        }
    }

    /**
     * Email the selected vendor a copy of the signed purchase order.
     * Procurement is copied. This is only sent when Supply Chain chooses to forward it.
     */
    public function forwardSignedPoToVendor(MRF $mrf): void
    {
        $mrf->loadMissing(['selectedVendor']);
        $vendorEmail = trim((string) ($mrf->selectedVendor?->email ?? ''));
        if (! filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('The selected vendor has no email address.');
        }

        $attachment = $this->signedPurchaseOrderPdf($mrf);
        $mailable = new POForwardedToVendorMail($mrf, $attachment !== null);
        if ($attachment !== null) {
            $mailable->attachData($attachment['bytes'], $attachment['name'], [
                'mime' => 'application/pdf',
            ]);
        }

        $mail = Mail::to($vendorEmail);
        $procurement = PurchaseOrderInvoiceCc::PROCUREMENT_EMAIL;
        if (strcasecmp($vendorEmail, $procurement) !== 0) {
            $mail->cc($procurement);
        }

        // Send now so the signed PDF is attached on this request.
        $mail->send($mailable);
    }

    /**
     * Email procurement after SCD approves the vendor and a PO must be generated.
     */
    public function notifyVendorSelectionApproved(MRF $mrf, User $approver): void
    {
        $emails = collect($this->getEmailsByRoles(['procurement', 'procurement_manager', 'admin']))
            ->merge(config('scm.po_cc_recipients', []))
            ->filter()
            ->unique(fn ($email) => strtolower((string) $email))
            ->values()
            ->all();

        foreach ($emails as $email) {
            $this->deliverMailable(
                event: 'vendor_selection_approved',
                recipient: (string) $email,
                modelId: $mrf->formatted_id ?: $mrf->mrf_id,
                mailableFactory: static fn () => new VendorSelectionApprovedMail($mrf, $approver)
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
                DatabaseNotifications::send($vendorUser, new \App\Notifications\VendorQuoteApprovedNotification(
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

    public function notifyFinanceDeliveryConfirmed(MRF $mrf, User $confirmedBy): void
    {
        $financeUsers = User::query()
            ->whereIn('supply_chain_role', ['finance', 'finance_officer', 'admin'])
            ->get();

        $title = $mrf->title ?: ($mrf->formatted_id ?: $mrf->mrf_id);
        $ref = $mrf->formatted_id ?: $mrf->mrf_id;

        foreach ($financeUsers as $finance) {
            try {
                DatabaseNotifications::send($finance, new SystemAnnouncementNotification(
                    'Delivery Confirmed — Payment Can Proceed',
                    "Delivery for {$ref} ({$title}) has been confirmed by {$confirmedBy->name}. Payment processing can now proceed.",
                    [
                        'action_url' => "/procurement/{$mrf->mrf_id}",
                        'mrf_id' => $mrf->mrf_id,
                        'title' => $mrf->title,
                    ]
                ));
            } catch (\Throwable $e) {
                Log::error('Finance delivery-confirmed notification failed', [
                    'mrf_id' => $mrf->mrf_id,
                    'user_id' => $finance->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function isLazarusEmail(mixed $email): bool
    {
        return strcasecmp(trim((string) $email), self::LAZARUS_EMAIL) === 0;
    }

    /**
     * @return array{bytes: string, name: string}|null
     */
    private function signedPurchaseOrderPdf(MRF $mrf): ?array
    {
        $diskName = (string) config('filesystems.documents_disk', 's3');
        if (! config("filesystems.disks.{$diskName}")) {
            return null;
        }

        $document = ProcurementDocument::query()
            ->where('type', ProcurementDocument::TYPE_SIGNED_PO)
            ->where('is_active', true)
            ->where(function ($query) use ($mrf) {
                $query->where('mrf_id', $mrf->id);
                if (filled($mrf->mrf_id)) {
                    $query->orWhere('mrf_id', $mrf->mrf_id);
                }
            })
            ->latest('id')
            ->first();

        $path = trim((string) ($document?->file_path ?? ''));
        if ($path === '' || str_contains($path, '://')) {
            return null;
        }

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            $poNumber = $mrf->po_number ?: ($mrf->formatted_id ?: $mrf->mrf_id);

            return [
                'bytes' => $bytes,
                'name' => 'PO-'.$poNumber.'-signed.pdf',
            ];
        } catch (\Throwable $e) {
            Log::warning('Signed PO could not be attached to the vendor email', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getEmailsByRoles(array $roles): array
    {
        return User::whereIn('supply_chain_role', $roles)
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->toArray();
    }

    private function deliverMailable(string $event, string $recipient, string $modelId, callable $mailableFactory): void
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Workflow email dispatch skipped due to invalid recipient', [
                'event' => $event,
                'recipient' => $recipient,
                'model_id' => $modelId,
            ]);

            return;
        }

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
