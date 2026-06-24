<?php

namespace App\Services;

use App\Models\User;
use App\Models\MRF;
use App\Models\SRF;
use App\Support\LogisticsMrfRouting;
use App\Models\RFQ;
use App\Models\Quotation;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Models\ProcurementDocument;
use App\Notifications\MRFSubmittedNotification;
use App\Notifications\MRFApprovedNotification;
use App\Notifications\MRFRejectedNotification;
use App\Notifications\MRFRequesterUpdatedNotification;
use App\Notifications\SRFRequesterUpdatedNotification;
use App\Notifications\RFQAssignedNotification;
use App\Notifications\QuotationSubmittedNotification;
use App\Notifications\QuotationStatusUpdatedNotification;
use App\Notifications\VendorRegistrationNotification;
use App\Notifications\VendorApprovedNotification;
use App\Notifications\DocumentExpiryNotification;
use App\Notifications\SystemAnnouncementNotification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Notify first approver(s) about new MRF submission.
     *
     * Routing:
     * - Non-standard contract types → Supply Chain Director only
     * - Emerald contracts with logistics exception → Supply Chain Director
     * - Emerald contracts without exception → Executive (bunmi.babajide@emeraldcfze.com)
     * - Other standard types (oando, dangote, heritage) → Supply Chain Director
     */
    public function notifyMRFSubmitted(MRF $mrf): void
    {
        try {
            $mrf->loadMissing('requester');

            // Normalize and check contract type
            $normalizedType = strtolower(trim((string) $mrf->contract_type));
            $standardTypes = ['emerald', 'oando', 'dangote', 'heritage'];
            $isStandardType = in_array($normalizedType, $standardTypes, true);

            $notifiables = collect();

            // Non-standard contract types go directly to Supply Chain Director
            if (!$isStandardType) {
                $notifiables = User::whereIn('supply_chain_role', [
                    'supply_chain_director',
                    'supply_chain',
                    'admin',
                ])->get();
            } elseif ($normalizedType === 'emerald' && LogisticsMrfRouting::mrfShouldStartAtSupplyChainDirector($mrf)) {
                // Emerald with logistics exception
                $notifiables = User::whereIn('supply_chain_role', [
                    'supply_chain_director',
                    'supply_chain',
                    'admin',
                ])->get();
            } elseif ($normalizedType === 'emerald') {
                // Standard Emerald: use named executive approver
                $namedExecutive = User::where('email', 'bunmi.babajide@emeraldcfze.com')->first();

                if ($namedExecutive) {
                    $notifiables->push($namedExecutive);
                } else {
                    Log::warning('Configured Emerald executive approver not found by email; falling back to executive role.', [
                        'mrf_id' => $mrf->mrf_id,
                    ]);
                    $notifiables = User::whereIn('supply_chain_role', ['executive', 'admin'])->get();
                }
            } else {
                // Other standard types (oando, dangote, heritage) go to Supply Chain Director
                $notifiables = User::whereIn('supply_chain_role', [
                    'supply_chain_director',
                    'supply_chain',
                    'admin',
                ])->get();
            }

            foreach ($notifiables as $user) {
                $user->notifyNow(new MRFSubmittedNotification($mrf));
            }

            Log::info('MRF submitted notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF submitted notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify requester about MRF approval
     */
    public function notifyMRFApproved(MRF $mrf, User $approver, ?string $remarks = null): void
    {
        try {
            $requester = $mrf->requester;
            if ($requester) {
                $requester->notifyNow(new MRFApprovedNotification($mrf, $approver->name, $remarks));
            }

            Log::info('MRF approved notification sent', [
                'mrf_id' => $mrf->mrf_id,
                'requester_id' => $requester?->id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF approved notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify workflow participants when a requester edits an MRF within the edit window.
     */
    public function notifyMRFRequesterUpdated(MRF $mrf, User $editor, ?string $changeSummary = null): void
    {
        try {
            $roles = $this->mrfWorkflowParticipantRoles($mrf);

            $notifiables = User::query()
                ->whereIn('supply_chain_role', $roles)
                ->get();

            foreach ($notifiables as $user) {
                if ((int) $user->id === (int) $editor->id) {
                    continue;
                }

                $user->notifyNow(new MRFRequesterUpdatedNotification($mrf, $editor, $changeSummary));
            }

            $editor->notifyNow(new MRFRequesterUpdatedNotification(
                $mrf,
                $editor,
                $changeSummary ?: 'Your changes were saved successfully.'
            ));

            Log::info('MRF requester edit notification sent', [
                'mrf_id' => $mrf->mrf_id,
                'editor_id' => $editor->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF requester edit notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify workflow participants when a requester edits an SRF within the edit window.
     */
    public function notifySRFRequesterUpdated(SRF $srf, User $editor, ?string $changeSummary = null): void
    {
        try {
            $roles = $this->srfWorkflowParticipantRoles($srf);

            $notifiables = User::query()
                ->whereIn('supply_chain_role', $roles)
                ->get();

            foreach ($notifiables as $user) {
                if ((int) $user->id === (int) $editor->id) {
                    continue;
                }

                $user->notifyNow(new SRFRequesterUpdatedNotification($srf, $editor, $changeSummary));
            }

            $editor->notifyNow(new SRFRequesterUpdatedNotification(
                $srf,
                $editor,
                $changeSummary ?: 'Your changes were saved successfully.'
            ));

            Log::info('SRF requester edit notification sent', [
                'srf_id' => $srf->srf_id,
                'editor_id' => $editor->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send SRF requester edit notification', [
                'srf_id' => $srf->srf_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function mrfWorkflowParticipantRoles(MRF $mrf): array
    {
        $state = strtolower(trim((string) ($mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED)));

        if ($state === WorkflowStateService::STATE_EXECUTIVE_REVIEW) {
            return ['executive', 'admin'];
        }

        if (in_array($state, [
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW,
            WorkflowStateService::STATE_VENDOR_SELECTED,
            WorkflowStateService::STATE_INVOICE_APPROVED,
        ], true)) {
            return ['supply_chain_director', 'supply_chain', 'admin'];
        }

        if (in_array($state, [
            WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            WorkflowStateService::STATE_PROCUREMENT_APPROVED,
            WorkflowStateService::STATE_RFQ_ISSUED,
            WorkflowStateService::STATE_QUOTATIONS_RECEIVED,
            WorkflowStateService::STATE_QUOTATIONS_EVALUATED,
            WorkflowStateService::STATE_EXECUTIVE_APPROVED,
            WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
            WorkflowStateService::STATE_MRF_CREATED,
        ], true)) {
            return ['procurement', 'procurement_manager', 'supply_chain_director', 'supply_chain', 'executive', 'admin'];
        }

        return ['procurement', 'procurement_manager', 'supply_chain_director', 'supply_chain', 'executive', 'admin'];
    }

    /**
     * @return list<string>
     */
    private function srfWorkflowParticipantRoles(SRF $srf): array
    {
        $stage = strtolower(trim((string) ($srf->current_stage ?? '')));

        if ($stage === 'supply_chain_director_review') {
            return ['supply_chain_director', 'supply_chain', 'admin'];
        }

        if (in_array($stage, ['procurement', 'procurement_review', 'vendor_selected'], true)) {
            return ['procurement', 'procurement_manager', 'admin'];
        }

        return ['procurement', 'procurement_manager', 'supply_chain_director', 'supply_chain', 'logistics_manager', 'logistics_officer', 'admin'];
    }

    /**
     * Notify requester about MRF rejection
     */
    public function notifyMRFRejected(MRF $mrf, User $rejector, ?string $reason = null): void
    {
        try {
            $requester = $mrf->requester;
            if ($requester) {
                $requester->notifyNow(new MRFRejectedNotification($mrf, $rejector->name, $reason));
            }

            Log::info('MRF rejected notification sent', [
                'mrf_id' => $mrf->mrf_id,
                'requester_id' => $requester?->id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF rejected notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify vendor about RFQ assignment
     */
    public function notifyRFQAssigned(RFQ $rfq, Vendor $vendor): void
    {
        try {
            // Get vendor's user account
            $vendorUser = User::where('vendor_id', $vendor->id)->first();

            if ($vendorUser) {
                $vendorUser->notifyNow(new RFQAssignedNotification($rfq));
            }

            Log::info('RFQ assigned notification sent', [
                'rfq_id' => $rfq->rfq_number,
                'vendor_id' => $vendor->vendor_id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send RFQ assigned notification', [
                'rfq_id' => $rfq->rfq_number,
                'vendor_id' => $vendor->vendor_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify procurement team about new quotation submission
     */
    public function notifyQuotationSubmitted(Quotation $quotation, string $vendorName): void
    {
        try {
            // Only notify specific recipients for quotation submissions
            $notifiables = User::whereIn('email', [
                'viva.musa@emeraldcfze.com',
                'lateef.olanrewaju@emeraldcfze.com',
            ])->get();

            foreach ($notifiables as $user) {
                $user->notifyNow(new QuotationSubmittedNotification($quotation, $vendorName));
            }

            Log::info('Quotation submitted notification sent', [
                'quotation_id' => $quotation->id,
                'vendor' => $vendorName
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send quotation submitted notification', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify vendor about quotation status update
     */
    public function notifyQuotationStatusUpdated(
        Quotation $quotation,
        string $oldStatus,
        string $newStatus,
        ?string $remarks = null
    ): void {
        try {
            // Get vendor's user account
            $vendor = $quotation->vendor;
            if ($vendor) {
                $vendorUser = User::where('vendor_id', $vendor->id)->first();

                if ($vendorUser) {
                    $vendorUser->notifyNow(new QuotationStatusUpdatedNotification(
                        $quotation,
                        $oldStatus,
                        $newStatus,
                        $remarks
                    ));
                }
            }

            Log::info('Quotation status updated notification sent', [
                'quotation_id' => $quotation->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send quotation status updated notification', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify procurement and logistics teams about new vendor registration (database + email).
     */
    public function notifyVendorRegistration(VendorRegistration $registration): void
    {
        try {
            $hardcodedEmails = collect([
                'bunmi.babajide@emeraldcfze.com',
                'viva.musa@emeraldcfze.com',
                'lateef.olanrewaju@emeraldcfze.com',
            ])->filter();

            $fromHardcoded = User::query()
                ->whereIn('email', $hardcodedEmails->all())
                ->whereNotNull('email')
                ->get();

            $fromRoles = User::query()
                ->whereIn('supply_chain_role', [
                    'procurement_manager',
                    'procurement',
                    'logistics_manager',
                    'logistics_officer',
                ])
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();

            $notifiables = $fromHardcoded
                ->merge($fromRoles)
                ->unique('id')
                ->values();

            foreach ($notifiables as $user) {
                $user->notifyNow(new VendorRegistrationNotification($registration));
            }

            Log::info('Vendor registration notification sent', [
                'registration_id' => $registration->id,
                'vendor_name' => $registration->company_name,
                'recipient_count' => $notifiables->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send vendor registration notification', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify procurement team about vendor approval
     */
    public function notifyVendorApproved(Vendor $vendor, string $temporaryPassword): void
    {
        try {
            // Get procurement managers and admins
            $notifiables = User::whereIn('supply_chain_role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($notifiables as $user) {
                $user->notifyNow(new VendorApprovedNotification($vendor, $temporaryPassword));
            }

            Log::info('Vendor approved notification sent', [
                'vendor_id' => $vendor->vendor_id,
                'vendor_name' => $vendor->name
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send vendor approved notification', [
                'vendor_id' => $vendor->vendor_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify about document expiry
     */
    public function notifyDocumentExpiry(
        string $documentType,
        string $expiryDate,
        Vendor $vendor,
        int $daysUntilExpiry
    ): void {
        try {
            // Notify procurement managers and vendor
            $notifiables = User::whereIn('supply_chain_role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            // Add vendor user
            $vendorUser = User::where('vendor_id', $vendor->id)->first();
            if ($vendorUser) {
                $notifiables->push($vendorUser);
            }

            foreach ($notifiables as $user) {
                $user->notifyNow(new DocumentExpiryNotification(
                    $documentType,
                    $expiryDate,
                    $vendor->name,
                    $vendor->id,
                    $daysUntilExpiry
                ));
            }

            Log::info('Document expiry notification sent', [
                'vendor_id' => $vendor->vendor_id,
                'document_type' => $documentType,
                'days_until_expiry' => $daysUntilExpiry
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send document expiry notification', [
                'vendor_id' => $vendor->vendor_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send system announcement to all users or specific roles
     */
    public function sendSystemAnnouncement(
        string $title,
        string $message,
        ?array $roles = null,
        ?string $actionUrl = null,
        string $priority = 'normal'
    ): void {
        try {
            $query = User::query();

            if ($roles) {
                $query->whereIn('supply_chain_role', $roles);
            }

            $users = $query->get();

            foreach ($users as $user) {
                $user->notifyNow(new SystemAnnouncementNotification(
                    $title,
                    $message,
                    $actionUrl,
                    $priority
                ));
            }

            Log::info('System announcement sent', [
                'title' => $title,
                'roles' => $roles ?? 'all',
                'recipients' => $users->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send system announcement', [
                'title' => $title,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify specific user(s)
     */
    public function notifyUsers(array $userIds, $notification): void
    {
        try {
            $users = User::whereIn('id', $userIds)->get();

            foreach ($users as $user) {
                $user->notifyNow($notification);
            }

            Log::info('Custom notification sent', [
                'user_ids' => $userIds,
                'notification_type' => get_class($notification)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send custom notification', [
                'user_ids' => $userIds,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify chairman about pending MRF approval
     */
    public function notifyMRFPendingChairmanApproval(MRF $mrf): void
    {
        try {
            $chairmen = User::whereIn('supply_chain_role', ['chairman', 'admin'])->get();

            foreach ($chairmen as $chairman) {
                $chairman->notifyNow(new SystemAnnouncementNotification(
                    'High-Value MRF Pending Approval',
                    "MRF {$mrf->mrf_id} requires your approval. " . ($mrf->estimated_cost !== null
                        ? "Amount: {$mrf->currency} " . number_format((float) $mrf->estimated_cost, 2)
                        : 'Estimated amount not specified.'),
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('MRF pending chairman approval notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF chairman notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify procurement managers about approved MRF
     */
    public function notifyMRFPendingProcurement(MRF $mrf): void
    {
        try {
            $procurementManagers = User::whereIn('supply_chain_role', ['procurement_manager', 'procurement', 'admin'])->get();

            foreach ($procurementManagers as $manager) {
                $manager->notifyNow(new SystemAnnouncementNotification(
                    'MRF Ready for PO Generation',
                    "MRF {$mrf->mrf_id} has been approved and is ready for PO generation.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            Log::info('MRF pending procurement notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF procurement notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify Lazarus Angbazo (special director) about high-value custom contract type MRF
     * for final authorization before proceeding to procurement
     */
    public function notifyLazarusDirectorApprovalPending(MRF $mrf, User $approver, ?string $remarks = null): void
    {
        try {
            // Find Lazarus.angbazo user
            $lazarus = User::where('email', 'lazarus.angbazo@emeraldcfze.com')->first();

            if ($lazarus) {
                $lazarus->notifyNow(new SystemAnnouncementNotification(
                    'High-Value Custom Contract MRF - Director Approval Required',
                    "MRF {$mrf->mrf_id} (Contract Type: {$mrf->contract_type}) has been approved by Supply Chain Director {$approver->name} and requires your authorization before proceeding. " .
                    ($mrf->estimated_cost !== null ? "Amount: ₦" . number_format((float) $mrf->estimated_cost, 2) : "Amount not specified."),
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));

                Log::info('High-value custom MRF sent to Lazarus Angbazo for approval', [
                    'mrf_id' => $mrf->mrf_id,
                    'contract_type' => $mrf->contract_type,
                    'estimated_cost' => $mrf->estimated_cost
                ]);
            } else {
                Log::warning('Lazarus Angbazo user not found by email; skipping notification', [
                    'mrf_id' => $mrf->mrf_id,
                    'contract_type' => $mrf->contract_type
                ]);

                // Fallback: notify supply chain directors if Lazarus not found
                $supplyChainDirectors = User::whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain', 'admin'])->get();
                foreach ($supplyChainDirectors as $director) {
                    $director->notifyNow(new SystemAnnouncementNotification(
                        'High-Value Custom Contract MRF - Director Approval Required',
                        "MRF {$mrf->mrf_id} (Custom Contract: {$mrf->contract_type}) requires director-level approval. Amount: ₦" .
                        number_format((float) ($mrf->estimated_cost ?? 0), 2),
                        "/mrfs/{$mrf->mrf_id}",
                        'high'
                    ));
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Lazarus director approval notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify Supply Chain Director about PO ready for signature
     */
    public function notifyPOReadyForSignature(MRF $mrf): void
    {
        try {
            $supplyChainDirectors = User::whereIn('supply_chain_role', ['supply_chain_director', 'supply_chain', 'admin'])->get();

            foreach ($supplyChainDirectors as $director) {
                $director->notifyNow(new SystemAnnouncementNotification(
                    'PO Ready for Signature',
                    "PO {$mrf->po_number} for MRF {$mrf->mrf_id} is ready for your signature.",
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('PO ready for signature notification sent', ['mrf_id' => $mrf->mrf_id, 'po_number' => $mrf->po_number]);
        } catch (\Exception $e) {
            Log::error('Failed to send PO signature notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify Finance team about signed PO
     */
    public function notifyPOSignedToFinance(MRF $mrf): void
    {
        try {
            $financeTeam = User::whereIn('supply_chain_role', ['finance', 'admin'])->get();

            foreach ($financeTeam as $finance) {
                $finance->notifyNow(new SystemAnnouncementNotification(
                    'Signed PO Received',
                    "PO {$mrf->po_number} for MRF {$mrf->mrf_id} has been signed and is ready for payment processing.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            Log::info('PO signed to finance notification sent', ['mrf_id' => $mrf->mrf_id, 'po_number' => $mrf->po_number]);
        } catch (\Exception $e) {
            Log::error('Failed to send PO signed notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify procurement about PO rejection
     */
    public function notifyPORejectedToProcurement(MRF $mrf, string $reason): void
    {
        try {
            $procurementManagers = User::whereIn('supply_chain_role', ['procurement_manager', 'procurement', 'admin'])->get();

            foreach ($procurementManagers as $manager) {
                $manager->notifyNow(new SystemAnnouncementNotification(
                    'PO Rejected - Revision Required',
                    "PO {$mrf->po_number} for MRF {$mrf->mrf_id} has been rejected. Reason: {$reason}",
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('PO rejected notification sent', ['mrf_id' => $mrf->mrf_id, 'po_number' => $mrf->po_number]);
        } catch (\Exception $e) {
            Log::error('Failed to send PO rejection notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify chairman about pending payment approval
     */
    public function notifyPaymentPendingChairman(MRF $mrf): void
    {
        try {
            $chairmen = User::whereIn('supply_chain_role', ['chairman', 'admin'])->get();

            foreach ($chairmen as $chairman) {
                $chairman->notifyNow(new SystemAnnouncementNotification(
                    'Payment Pending Approval',
                    "Payment for PO {$mrf->po_number} (MRF {$mrf->mrf_id}) requires your approval. " . ($mrf->estimated_cost !== null
                        ? "Amount: {$mrf->currency} " . number_format((float) $mrf->estimated_cost, 2)
                        : 'Amount not specified.'),
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('Payment pending chairman notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::error('Failed to send payment pending notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify executives when MRF is forwarded from procurement
     */
    public function notifyMRFForwardedToExecutive(MRF $mrf, User $approver): void
    {
        try {
            // Notify executives
            $executives = User::whereIn('supply_chain_role', ['executive', 'admin'])->get();

            foreach ($executives as $executive) {
                $executive->notifyNow(new SystemAnnouncementNotification(
                    'MRF Pending Your Approval',
                    "MRF {$mrf->mrf_id} - {$mrf->title} has been approved by {$approver->name} and requires your approval. " . ($mrf->estimated_cost !== null
                        ? 'Estimated cost: ₦' . number_format((float) $mrf->estimated_cost, 2)
                        : 'Estimated cost not specified.'),
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('MRF forwarded to executive notification sent', [
                'mrf_id' => $mrf->mrf_id,
                'approved_by' => $approver->name
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF forwarded to executive notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify all stakeholders about completed MRF
     */
    public function notifyMRFCompleted(MRF $mrf): void
    {
        try {
            // Notify requester
            $requester = $mrf->requester;
            if ($requester) {
                $requester->notifyNow(new SystemAnnouncementNotification(
                    'MRF Completed',
                    "Your MRF {$mrf->mrf_id} has been completed. Payment has been approved.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            // Notify procurement, finance, and admins
            $stakeholders = User::whereIn('supply_chain_role', [
                'procurement_manager',
                'procurement',
                'finance',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($stakeholders as $stakeholder) {
                $stakeholder->notifyNow(new SystemAnnouncementNotification(
                    'MRF Workflow Completed',
                    "MRF {$mrf->mrf_id} workflow has been completed. PO {$mrf->po_number}.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            Log::info('MRF completed notification sent', ['mrf_id' => $mrf->mrf_id]);
        } catch (\Exception $e) {
            Log::error('Failed to send MRF completed notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify vendor about quotation being awarded
     */
    public function notifyQuotationAwarded(Quotation $quotation): void
    {
        try {
            $vendor = $quotation->vendor;
            $vendorUser = User::where('vendor_id', $vendor->id)->first();

            if ($vendorUser) {
                $vendorUser->notifyNow(new SystemAnnouncementNotification(
                    'Quotation Awarded! 🎉',
                    "Congratulations! Your quotation {$quotation->quotation_id} has been selected for RFQ {$quotation->rfq->rfq_id}. Total amount: {$quotation->currency} " . number_format($quotation->total_amount, 2),
                    "/quotations/{$quotation->quotation_id}",
                    'high'
                ));
            }

            // Notify procurement team
            $procurementTeam = User::whereIn('supply_chain_role', ['procurement_manager', 'procurement', 'admin'])->get();
            foreach ($procurementTeam as $manager) {
                $manager->notifyNow(new SystemAnnouncementNotification(
                    'Vendor Selected',
                    "Vendor {$vendor->name} has been selected for RFQ {$quotation->rfq->rfq_id}. Quotation: {$quotation->quotation_id}.",
                    "/rfqs/{$quotation->rfq->rfq_id}",
                    'normal'
                ));
            }

            Log::info('Quotation awarded notification sent', [
                'quotation_id' => $quotation->quotation_id,
                'vendor_id' => $vendor->vendor_id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send quotation awarded notification', [
                'quotation_id' => $quotation->quotation_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify vendor about quotation being rejected
     */
    public function notifyQuotationRejected(Quotation $quotation): void
    {
        try {
            $vendor = $quotation->vendor;
            $vendorUser = User::where('vendor_id', $vendor->id)->first();

            if ($vendorUser) {
                $vendorUser->notifyNow(new SystemAnnouncementNotification(
                    'Quotation Not Selected',
                    "Thank you for your submission for RFQ {$quotation->rfq->rfq_id}. Another vendor was selected for this opportunity.",
                    "/quotations/{$quotation->quotation_id}",
                    'normal'
                ));
            }

            Log::info('Quotation rejected notification sent', [
                'quotation_id' => $quotation->quotation_id,
                'vendor_id' => $vendor->vendor_id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send quotation rejected notification', [
                'quotation_id' => $quotation->quotation_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify vendor about RFQ being closed
     */
    public function notifyRFQClosed(RFQ $rfq, Vendor $vendor): void
    {
        try {
            $vendorUser = User::where('vendor_id', $vendor->id)->first();

            if ($vendorUser) {
                $vendorUser->notifyNow(new SystemAnnouncementNotification(
                    'RFQ Closed',
                    "RFQ {$rfq->rfq_id} has been closed without vendor selection.",
                    "/rfqs/{$rfq->rfq_id}",
                    'normal'
                ));
            }

            Log::info('RFQ closed notification sent', [
                'rfq_id' => $rfq->rfq_id,
                'vendor_id' => $vendor->vendor_id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send RFQ closed notification', [
                'rfq_id' => $rfq->rfq_id,
                'vendor_id' => $vendor->vendor_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify Procurement Manager about GRN request
     */
    public function notifyGRNRequested(MRF $mrf, User $requestedBy): void
    {
        try {
            $procurementManagers = User::whereIn('supply_chain_role', ['procurement_manager', 'procurement', 'admin'])->get();

            foreach ($procurementManagers as $manager) {
                $manager->notifyNow(new SystemAnnouncementNotification(
                    'GRN Requested',
                    "GRN has been requested for PO {$mrf->po_number} (MRF {$mrf->mrf_id}) by {$requestedBy->name}. Please complete the GRN.",
                    "/mrfs/{$mrf->mrf_id}",
                    'high'
                ));
            }

            Log::info('GRN requested notification sent', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $mrf->po_number,
                'requested_by' => $requestedBy->id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send GRN requested notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify Finance Officer about GRN completion
     */
    public function notifyGRNCompleted(MRF $mrf, User $completedBy): void
    {
        try {
            $financeTeam = User::whereIn('supply_chain_role', ['finance', 'finance_officer', 'admin'])->get();

            foreach ($financeTeam as $finance) {
                $finance->notifyNow(new SystemAnnouncementNotification(
                    'GRN Completed',
                    "GRN for PO {$mrf->po_number} (MRF {$mrf->mrf_id}) has been completed by {$completedBy->name}.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            // Also notify the requester
            $requester = $mrf->requester;
            if ($requester) {
                $requester->notifyNow(new SystemAnnouncementNotification(
                    'GRN Completed',
                    "GRN for your MRF {$mrf->mrf_id} (PO {$mrf->po_number}) has been completed.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            Log::info('GRN completed notification sent', [
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $mrf->po_number,
                'completed_by' => $completedBy->id
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send GRN completed notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function notifyVendorInvoiceSubmitted(MRF $mrf, ProcurementDocument $document): void
    {
        try {
            $message = "Vendor invoice submitted for MRF {$mrf->mrf_id}. Document: {$document->file_name}.";

            $recipients = User::query()
                ->whereIn('supply_chain_role', [
                    'procurement_manager',
                    'procurement',
                    'supply_chain_director',
                    'supply_chain',
                    'executive',
                    'finance',
                    'admin',
                ])
                ->whereNotNull('email')
                ->get();

            foreach ($recipients as $recipient) {
                $recipient->notifyNow(new SystemAnnouncementNotification(
                    'Vendor Invoice Submitted',
                    $message,
                    "/mrfs/{$mrf->mrf_id}",
                    'normal',
                    [
                        'mrf_id' => $mrf->mrf_id,
                        'document_id' => $document->id,
                        'document_type' => $document->type,
                    ]
                ));
            }

            Log::info('Vendor invoice submitted notifications sent', [
                'mrf_id' => $mrf->mrf_id,
                'document_id' => $document->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send vendor invoice submitted notifications', [
                'mrf_id' => $mrf->mrf_id,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
