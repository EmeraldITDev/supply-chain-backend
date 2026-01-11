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
}
