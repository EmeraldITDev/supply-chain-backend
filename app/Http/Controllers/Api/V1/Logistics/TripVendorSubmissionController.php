<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\InviteVendorsRequest;
use App\Http\Requests\Logistics\SelectVendorRequest;
use App\Models\Logistics\Trip;
use App\Models\Logistics\TripVendorSubmission;
use App\Models\Vendor;
use App\Notifications\VendorTripInvoiceReminderNotification;
use App\Services\Logistics\TripVendorSubmissionService;
use Illuminate\Http\Request;

class TripVendorSubmissionController extends ApiController
{
    public function __construct(
        private TripVendorSubmissionService $submissionService,
    ) {
    }

    /**
     * Invite vendors to submit quotes for a trip
     */
    public function inviteVendors(InviteVendorsRequest $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        // Check if trip is in draft status
        if ($trip->status !== Trip::STATUS_DRAFT && $trip->status !== Trip::STATUS_SCHEDULED) {
            return $this->error(
                'Vendors can only be invited for trips in draft or scheduled status',
                'INVALID_STATUS',
                400
            );
        }

        try {
            $data = $request->validated();
            $submissions = $this->submissionService->createSubmissionsForVendors($trip, $data['vendor_ids']);

            return $this->success([
                'message' => 'Vendors invited successfully',
                'submissions_count' => $submissions->count(),
                'trip_id' => $trip->id,
                'is_multi_vendor' => $trip->multi_vendor,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Failed to invite vendors: ' . $e->getMessage(), 'INVITATION_FAILED', 400);
        }
    }

    /**
     * Get all vendor responses for a trip
     */
    public function getVendorResponses(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        try {
            $responses = $this->submissionService->getVendorResponses($trip);

            return $this->success([
                'trip_id' => $trip->id,
                'trip_code' => $trip->trip_code,
                'trip_title' => $trip->title,
                'vendor_responses' => $responses,
                'total_responses' => $responses->count(),
                'pending_responses' => $responses->where('status', 'pending')->count(),
                'submitted_responses' => $responses->where('status', 'submitted')->count(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve vendor responses: ' . $e->getMessage(), 'FETCH_FAILED', 400);
        }
    }

    /**
     * Select a vendor for a trip
     */
    public function selectVendor(SelectVendorRequest $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();
        $vendor = Vendor::find($data['vendor_id']);

        if (!$vendor) {
            return $this->error('Vendor not found', 'NOT_FOUND', 404);
        }

        // Check if vendor has submitted for this trip
        $submission = $trip->vendorSubmissions()->where('vendor_id', $vendor->id)->first();

        if (!$submission) {
            return $this->error('Vendor has not submitted for this trip', 'NOT_SUBMITTED', 400);
        }

        try {
            $this->submissionService->selectVendor($trip, $vendor);

            return $this->success([
                'message' => 'Vendor selected successfully',
                'trip_id' => $trip->id,
                'selected_vendor_id' => $vendor->id,
                'selected_vendor_name' => $vendor->name,
                'trip_status' => $trip->status,
                'approval_status' => $trip->approval_status,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to select vendor: ' . $e->getMessage(), 'SELECTION_FAILED', 400);
        }
    }

    /**
     * List all vendor submissions for a trip (internal / approvers).
     */
    public function listTripSubmissions(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $submissions = $trip->vendorSubmissions()
            ->with(['vendor', 'documents', 'submittedBy'])
            ->get()
            ->map(function (TripVendorSubmission $submission) use ($trip) {
                return [
                    'id' => $submission->id,
                    'trip_id' => $submission->trip_id,
                    'trip_code' => $trip->trip_code,
                    'vendor_id' => $submission->vendor_id,
                    'vendor_name' => $submission->vendor->name,
                    'vendor_contact' => [
                        'email' => $submission->vendor->email,
                        'phone' => $submission->vendor->phone,
                    ],
                    'vehicle_details' => [
                        'make' => $submission->vehicle_make,
                        'model' => $submission->vehicle_model,
                        'plate_number' => $submission->plate_number,
                    ],
                    'driver_details' => [
                        'name' => $submission->driver_name,
                        'phone' => $submission->driver_phone,
                        'license_no' => $submission->driver_license_no,
                    ],
                    'security_info' => $submission->security_info,
                    'quoted_price' => $submission->quoted_price,
                    'currency' => $submission->currency,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at,
                    'submitted_by' => $submission->submittedBy?->name,
                    'documents' => $submission->documents->map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'document_type' => $doc->document_type,
                            'file_name' => $doc->file_name,
                            'size' => $doc->size,
                        ];
                    }),
                ];
            });

        return $this->success([
            'trip_id' => $trip->id,
            'submissions' => $submissions,
        ]);
    }

    /**
     * Get submission details (internal endpoint for approvers)
     */
    public function getSubmission(Request $request, int $tripId, int $submissionId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $submission = $trip->vendorSubmissions()
            ->where('id', $submissionId)
            ->with(['vendor', 'documents', 'submittedBy'])
            ->first();

        if (!$submission) {
            return $this->error('Submission not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'submission' => [
                'id' => $submission->id,
                'trip_id' => $submission->trip_id,
                'trip_code' => $trip->trip_code,
                'vendor_id' => $submission->vendor_id,
                'vendor_name' => $submission->vendor->name,
                'vendor_contact' => [
                    'email' => $submission->vendor->email,
                    'phone' => $submission->vendor->phone,
                ],
                'vehicle_details' => [
                    'make' => $submission->vehicle_make,
                    'model' => $submission->vehicle_model,
                    'plate_number' => $submission->plate_number,
                ],
                'driver_details' => [
                    'name' => $submission->driver_name,
                    'phone' => $submission->driver_phone,
                    'license_no' => $submission->driver_license_no,
                ],
                'security_info' => $submission->security_info,
                'quoted_price' => $submission->quoted_price,
                'currency' => $submission->currency,
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at,
                'submitted_by' => $submission->submittedBy?->name,
                'documents' => $submission->documents->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type,
                        'file_name' => $doc->file_name,
                        'size' => $doc->size,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Route trip to procurement after vendor selection
     */
    public function routeToProcurement(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        // Check if a vendor is selected
        if (!$trip->selected_vendor_id) {
            return $this->error('No vendor has been selected for this trip', 'NO_SELECTION', 400);
        }

        // Check if approval status is approved
        if ($trip->approval_status !== 'approved') {
            return $this->error('Trip must be approved before routing to procurement', 'NOT_APPROVED', 400);
        }

        try {
            $metadata = $trip->metadata ?? [];
            $metadata['procurement_routed_at'] = now()->toIso8601String();
            $metadata['po_requisition_status'] = 'pending';

            $trip->update([
                'status' => Trip::STATUS_VENDOR_ASSIGNED,
                'metadata' => $metadata,
            ]);

            return $this->success([
                'message' => 'Trip routed to procurement successfully',
                'trip_id' => $trip->id,
                'trip_status' => $trip->status,
                'procurement' => [
                    'requisition_status' => 'pending',
                    'note' => 'Link this trip to Procurement PO workflow when the MRF integration is available.',
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to route trip to procurement: ' . $e->getMessage(), 'ROUTE_FAILED', 400);
        }
    }

    /**
     * Notify vendor to submit invoice
     */
    public function notifyInvoiceSubmission(Request $request, int $tripId)
    {
        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        if (!$trip->selectedVendor) {
            return $this->error('No vendor has been selected for this trip', 'NO_SELECTION', 400);
        }

        try {
            $vendor = $trip->selectedVendor;
            $notifiable = $vendor->users()->first();
            if ($notifiable) {
                $notifiable->notifyNow(new VendorTripInvoiceReminderNotification($trip, $vendor));
            }

            return $this->success([
                'message' => 'Invoice submission notification sent to vendor',
                'vendor_id' => $trip->selectedVendor->id,
                'vendor_name' => $trip->selectedVendor->name,
                'notified_user' => (bool) $notifiable,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to notify vendor: ' . $e->getMessage(), 'NOTIFICATION_FAILED', 400);
        }
    }
}
