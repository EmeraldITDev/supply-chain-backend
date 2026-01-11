<?php

namespace App\Services;

use App\Models\User;
use App\Models\MRF;
use App\Models\RFQ;
use App\Models\Quotation;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Notifications\MRFSubmittedNotification;
use App\Notifications\MRFApprovedNotification;
use App\Notifications\MRFRejectedNotification;
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
     * Notify procurement managers about new MRF submission
     */
    public function notifyMRFSubmitted(MRF $mrf): void
    {
        try {
            // Get all procurement managers and admins
            $notifiables = User::whereIn('role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($notifiables as $user) {
                $user->notify(new MRFSubmittedNotification($mrf));
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
                $requester->notify(new MRFApprovedNotification($mrf, $approver->name, $remarks));
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
     * Notify requester about MRF rejection
     */
    public function notifyMRFRejected(MRF $mrf, User $rejector, ?string $reason = null): void
    {
        try {
            $requester = $mrf->requester;
            if ($requester) {
                $requester->notify(new MRFRejectedNotification($mrf, $rejector->name, $reason));
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
                $vendorUser->notify(new RFQAssignedNotification($rfq));
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
            // Get procurement managers and RFQ creator
            $notifiables = User::whereIn('role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($notifiables as $user) {
                $user->notify(new QuotationSubmittedNotification($quotation, $vendorName));
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
                    $vendorUser->notify(new QuotationStatusUpdatedNotification(
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
     * Notify procurement team about new vendor registration
     */
    public function notifyVendorRegistration(VendorRegistration $registration): void
    {
        try {
            // Get procurement managers and admins
            $notifiables = User::whereIn('role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($notifiables as $user) {
                $user->notify(new VendorRegistrationNotification($registration));
            }

            Log::info('Vendor registration notification sent', [
                'registration_id' => $registration->id,
                'vendor_name' => $registration->name
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
            $notifiables = User::whereIn('role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($notifiables as $user) {
                $user->notify(new VendorApprovedNotification($vendor, $temporaryPassword));
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
            $notifiables = User::whereIn('role', [
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
                $user->notify(new DocumentExpiryNotification(
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
                $query->whereIn('role', $roles);
            }
            
            $users = $query->get();

            foreach ($users as $user) {
                $user->notify(new SystemAnnouncementNotification(
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
                $user->notify($notification);
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
            $chairmen = User::whereIn('role', ['chairman', 'admin'])->get();

            foreach ($chairmen as $chairman) {
                $chairman->notify(new SystemAnnouncementNotification(
                    'High-Value MRF Pending Approval',
                    "MRF {$mrf->mrf_id} requires your approval. Amount: {$mrf->currency} " . number_format($mrf->estimated_cost, 2),
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
            $procurementManagers = User::whereIn('role', ['procurement_manager', 'procurement', 'admin'])->get();

            foreach ($procurementManagers as $manager) {
                $manager->notify(new SystemAnnouncementNotification(
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
     * Notify Supply Chain Director about PO ready for signature
     */
    public function notifyPOReadyForSignature(MRF $mrf): void
    {
        try {
            $supplyChainDirectors = User::whereIn('role', ['supply_chain_director', 'supply_chain', 'admin'])->get();

            foreach ($supplyChainDirectors as $director) {
                $director->notify(new SystemAnnouncementNotification(
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
            $financeTeam = User::whereIn('role', ['finance', 'admin'])->get();

            foreach ($financeTeam as $finance) {
                $finance->notify(new SystemAnnouncementNotification(
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
            $procurementManagers = User::whereIn('role', ['procurement_manager', 'procurement', 'admin'])->get();

            foreach ($procurementManagers as $manager) {
                $manager->notify(new SystemAnnouncementNotification(
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
            $chairmen = User::whereIn('role', ['chairman', 'admin'])->get();

            foreach ($chairmen as $chairman) {
                $chairman->notify(new SystemAnnouncementNotification(
                    'Payment Pending Approval',
                    "Payment for PO {$mrf->po_number} (MRF {$mrf->mrf_id}) requires your approval. Amount: {$mrf->currency} " . number_format($mrf->estimated_cost, 2),
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
     * Notify all stakeholders about completed MRF
     */
    public function notifyMRFCompleted(MRF $mrf): void
    {
        try {
            // Notify requester
            $requester = $mrf->requester;
            if ($requester) {
                $requester->notify(new SystemAnnouncementNotification(
                    'MRF Completed',
                    "Your MRF {$mrf->mrf_id} has been completed. Payment has been approved.",
                    "/mrfs/{$mrf->mrf_id}",
                    'normal'
                ));
            }

            // Notify procurement, finance, and admins
            $stakeholders = User::whereIn('role', [
                'procurement_manager',
                'procurement',
                'finance',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($stakeholders as $stakeholder) {
                $stakeholder->notify(new SystemAnnouncementNotification(
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
                $vendorUser->notify(new SystemAnnouncementNotification(
                    'Quotation Awarded! 🎉',
                    "Congratulations! Your quotation {$quotation->quotation_id} has been selected for RFQ {$quotation->rfq->rfq_id}. Total amount: {$quotation->currency} " . number_format($quotation->total_amount, 2),
                    "/quotations/{$quotation->quotation_id}",
                    'high'
                ));
            }

            // Notify procurement team
            $procurementTeam = User::whereIn('role', ['procurement_manager', 'procurement', 'admin'])->get();
            foreach ($procurementTeam as $manager) {
                $manager->notify(new SystemAnnouncementNotification(
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
                $vendorUser->notify(new SystemAnnouncementNotification(
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
                $vendorUser->notify(new SystemAnnouncementNotification(
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
}
