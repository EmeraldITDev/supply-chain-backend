<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;
use App\Models\Logistics\TripVendorSubmission;
use App\Models\Vendor;
use App\Notifications\VendorInvitedForTripNotification;
use App\Notifications\VendorRejectedForTripNotification;
use App\Notifications\VendorSelectedForTripNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class TripVendorSubmissionService
{
    /**
     * Trip quote / RFQ invitation (vendor portal). Prefer the vendor's portal User;
     * fall back to the vendor record email so notifications still send when no User is linked.
     */
    public function notifyVendorInvitation(Trip $trip, Vendor $vendor): void
    {
        $notification = new VendorInvitedForTripNotification($trip, $vendor);

        $portalUser = $vendor->users()->first();
        $notifiable = $portalUser ?? ($vendor->email ? $vendor : null);
        if (!$notifiable) {
            Log::info('Vendor trip invitation skipped: no portal user and no vendor email', [
                'trip_id' => $trip->id,
                'vendor_id' => $vendor->id,
            ]);

            return;
        }

        try {
            $notifiable->notifyNow($notification);
        } catch (\Throwable $e) {
            Log::warning('Vendor trip invitation notification failed (assignment still saved)', [
                'trip_id' => $trip->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create vendor submissions for invited vendors.
     *
     * @param  bool  $notifyExisting  When true, resend the quote invitation if a submission row already exists
     *                               (used when re-assigning from Schedule Trip / Assign Vendor).
     */
    public function createSubmissionsForVendors(Trip $trip, array $vendorIds, bool $notifyExisting = false): Collection
    {
        $submissions = collect();

        foreach ($vendorIds as $vendorId) {
            // Check if submission already exists
            $existing = $trip->vendorSubmissions()->where('vendor_id', $vendorId)->first();
            if ($existing) {
                $submissions->push($existing);
                if ($notifyExisting) {
                    $vendor = Vendor::find($vendorId);
                    if ($vendor) {
                        $this->notifyVendorInvitation($trip, $vendor);
                    }
                }

                continue;
            }

            // Create new submission (explicit nulls: strict SQL / some drivers omit
            // absent columns; columns must be nullable — see relax + raw migrations).
            $submission = $trip->vendorSubmissions()->create([
                'vendor_id' => $vendorId,
                'status' => TripVendorSubmission::STATUS_PENDING,
                'vehicle_make' => null,
                'vehicle_model' => null,
                'plate_number' => null,
                'driver_name' => null,
                'driver_phone' => null,
                'driver_license_no' => null,
            ]);

            $submissions->push($submission);

            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $this->notifyVendorInvitation($trip, $vendor);
            }
        }

        // Mark trip as multi-vendor if more than one vendor
        if ($submissions->count() > 1) {
            $trip->update([
                'multi_vendor' => true,
                'approval_status' => Trip::STATUS_DRAFT,
            ]);
        }

        return $submissions;
    }

    /**
     * Submit vendor details and pricing
     */
    public function submitVendorDetails(Trip $trip, Vendor $vendor, array $data, int $submittedBy): TripVendorSubmission
    {
        $submission = $trip->vendorSubmissions()->where('vendor_id', $vendor->id)->first();

        if (!$submission) {
            // Create submission if it doesn't exist
            $submission = $trip->vendorSubmissions()->create(array_merge([
                'vendor_id' => $vendor->id,
            ], $data, [
                'submitted_by' => $submittedBy,
            ]));
        } else {
            // Update existing submission
            $submission->update(array_merge($data, [
                'submitted_by' => $submittedBy,
            ]));
        }

        $submission->markAsSubmitted($submittedBy);

        if (!$trip->approval_status || $trip->approval_status === 'draft') {
            $trip->update(['approval_status' => 'pending_review']);
        }

        return $submission;
    }

    /**
     * Get all vendor responses for a trip
     */
    public function getVendorResponses(Trip $trip)
    {
        return $trip->vendorSubmissions()
            ->with(['vendor', 'documents'])
            ->get()
            ->map(function ($submission) {
                return [
                    'id' => $submission->id,
                    'vendor_id' => $submission->vendor_id,
                    'vendor_name' => $submission->vendor->name,
                    'vendor_phone' => $submission->vendor->phone ?? null,
                    'vendor_email' => $submission->vendor->email ?? null,
                    'vehicle_make' => $submission->vehicle_make,
                    'vehicle_model' => $submission->vehicle_model,
                    'plate_number' => $submission->plate_number,
                    'driver_name' => $submission->driver_name,
                    'driver_phone' => $submission->driver_phone,
                    'driver_license_no' => $submission->driver_license_no,
                    'quoted_price' => $submission->quoted_price,
                    'currency' => $submission->currency,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at,
                    'documents_count' => $submission->documents->count(),
                ];
            });
    }

    /**
     * Approve a vendor submission
     */
    public function approveSubmission(TripVendorSubmission $submission): void
    {
        $submission->approve();
    }

    /**
     * Reject a vendor submission
     */
    public function rejectSubmission(TripVendorSubmission $submission, string $reason): void
    {
        $submission->reject($reason);

        // Send rejection notification
        $vendor = $submission->vendor;
        if ($vendor && $vendor->users()->first()) {
            $vendor->users()->first()->notifyNow(new VendorRejectedForTripNotification(
                $submission->trip,
                $vendor,
                $reason
            ));
        }
    }

    /**
     * Select a vendor for the trip
     */
    public function selectVendor(Trip $trip, Vendor $vendor): void
    {
        $submission = $trip->vendorSubmissions()->where('vendor_id', $vendor->id)->firstOrFail();

        if ($submission->status !== TripVendorSubmission::STATUS_SUBMITTED) {
            throw new \RuntimeException('Vendor must submit trip details before they can be selected.');
        }

        // Approve the selected vendor's submission
        $this->approveSubmission($submission);

        // Update trip with selected vendor
        $trip->update([
            'selected_vendor_id' => $vendor->id,
            'vendor_id' => $vendor->id,
            'approval_status' => 'approved',
            'status' => Trip::STATUS_VENDOR_ASSIGNED,
        ]);

        // Reject all other vendors
        $trip->vendorSubmissions()
            ->where('vendor_id', '!=', $vendor->id)
            ->where('status', '!=', TripVendorSubmission::STATUS_REJECTED)
            ->get()
            ->each(function ($otherSubmission) {
                $this->rejectSubmission($otherSubmission, 'Another vendor was selected for this trip');
            });

        // Send approval notification to selected vendor
        if ($vendor && $vendor->users()->first()) {
            $vendor->users()->first()->notifyNow(new VendorSelectedForTripNotification($trip, $vendor));
        }
    }

    /**
     * Check if all required submissions are present
     */
    public function allSubmissionsPresent(Trip $trip): bool
    {
        if (!$trip->multi_vendor) {
            return true;
        }

        return $trip->vendorSubmissions()->count() > 0
            && $trip->vendorSubmissions()->where('status', TripVendorSubmission::STATUS_PENDING)->count() === 0;
    }

    /**
     * Block trip status advancement if submissions not complete
     */
    public function canAdvanceStatus(Trip $trip): bool
    {
        if (!$trip->multi_vendor) {
            return true;
        }

        // Check if a vendor is selected
        return $trip->selected_vendor_id !== null && $trip->approval_status === 'approved';
    }

    /**
     * Block trip status changes until vendor portal workflow rules are satisfied.
     */
    public function statusAdvancementBlockedReason(Trip $trip, string $newStatus): ?string
    {
        $gatedStatuses = [
            Trip::STATUS_VENDOR_ASSIGNED,
            Trip::STATUS_IN_PROGRESS,
            Trip::STATUS_COMPLETED,
            Trip::STATUS_CLOSED,
        ];

        if (!in_array($newStatus, $gatedStatuses, true)) {
            return null;
        }

        if ($trip->multi_vendor) {
            if (!$trip->selected_vendor_id || $trip->approval_status !== 'approved') {
                return 'Multi-vendor trip requires an approved vendor selection before advancing status.';
            }

            return null;
        }

        $vendorId = $trip->selected_vendor_id ?? $trip->vendor_id;
        if (!$vendorId) {
            return null;
        }

        $submission = $trip->vendorSubmissions()->where('vendor_id', $vendorId)->first();
        if (!$submission) {
            return 'A vendor submission record is required before advancing this trip.';
        }
        if ($submission->status === TripVendorSubmission::STATUS_PENDING) {
            return 'The assigned vendor must submit trip details before this trip can advance.';
        }
        if ($submission->status === TripVendorSubmission::STATUS_SUBMITTED) {
            return 'The vendor submission must be approved (e.g. via vendor selection) before this trip can advance.';
        }
        if ($submission->status === TripVendorSubmission::STATUS_REJECTED) {
            return 'The vendor submission was rejected; resolve vendor selection before advancing this trip.';
        }

        return null;
    }
}
