<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreVendorSubmissionRequest;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Logistics\TripVendorSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VendorPortalTripController extends ApiController
{
    public function __construct(
        private TripVendorSubmissionService $submissionService,
    ) {
    }

    /**
     * Resolve the Vendor row for portal routes. Prefer users.vendor_id; if
     * missing (e.g. vendor signed in via /api/auth/login without a link),
     * match an approved vendor by the same email as the authenticated user.
     */
    private function resolvePortalVendor(?User $user): ?Vendor
    {
        if (!$user) {
            return null;
        }

        $user->loadMissing('vendor');
        if ($user->vendor) {
            return $user->vendor;
        }

        if (!$this->userActsAsVendor($user)) {
            return null;
        }

        $email = trim((string) $user->email);
        if ($email === '') {
            return null;
        }

        $normalized = mb_strtolower($email);

        $candidates = Vendor::query()
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(contact_person_email, \'\'))) = ?', [$normalized]);
            })
            ->orderBy('id')
            ->get();

        $resolved = $candidates->first(function (Vendor $v) {
            return in_array(strtolower(trim((string) ($v->status ?? ''))), ['approved', 'active'], true);
        });

        if ($resolved && $user->vendor_id === null) {
            try {
                $user->forceFill(['vendor_id' => $resolved->id])->saveQuietly();
            } catch (\Throwable $e) {
                Log::warning('Could not persist users.vendor_id for vendor portal', [
                    'user_id' => $user->id,
                    'vendor_id' => $resolved->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $resolved;
    }

    private function userActsAsVendor(User $user): bool
    {
        if ($user->role !== null && strtolower((string) $user->role) === 'vendor') {
            return true;
        }
        if (method_exists($user, 'hasRole')) {
            try {
                return $user->hasRole('vendor');
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get all trips assigned to the authenticated vendor
     */
    public function indexVendorTrips(Request $request)
    {
        $vendor = $this->resolvePortalVendor($request->user());

        if (!$vendor) {
            return $this->error('User is not a vendor', 'NOT_VENDOR', 403);
        }

        $trips = Trip::where(function ($q) use ($vendor) {
            $q->whereHas('vendorSubmissions', function ($query) use ($vendor) {
                $query->where('vendor_id', $vendor->id);
            })->orWhere('vendor_id', $vendor->id);
        })
            ->with(['vendorSubmissions' => function ($query) use ($vendor) {
                $query->where('vendor_id', $vendor->id);
            }])
            ->paginate(20);

        return $this->success([
            'trips' => $trips,
        ]);
    }

    /**
     * Vendor submits driver, vehicle, and security details for a trip
     */
    public function submitVendorDetails(StoreVendorSubmissionRequest $request, int $tripId)
    {
        $vendor = $this->resolvePortalVendor($request->user());

        if (!$vendor) {
            return $this->error('User is not a vendor', 'NOT_VENDOR', 403);
        }

        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        // Check if vendor is invited for this trip
        $submission = $trip->vendorSubmissions()->where('vendor_id', $vendor->id)->first();

        if (!$submission) {
            return $this->error('Vendor is not invited for this trip', 'NOT_INVITED', 403);
        }

        try {
            $data = $request->validated();
            $submission = $this->submissionService->submitVendorDetails(
                $trip,
                $vendor,
                $data,
                $request->user()->id
            );

            return $this->success([
                'message' => 'Submission created successfully',
                'submission' => [
                    'id' => $submission->id,
                    'trip_id' => $submission->trip_id,
                    'vendor_id' => $submission->vendor_id,
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
                ],
            ], 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 'SUBMISSION_FAILED', 400);
        }
    }

    /**
     * Upload documents for a vendor submission
     */
    public function uploadDocuments(Request $request, int $tripId)
    {
        $vendor = $this->resolvePortalVendor($request->user());

        if (!$vendor) {
            return $this->error('User is not a vendor', 'NOT_VENDOR', 403);
        }

        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $submission = $trip->vendorSubmissions()->where('vendor_id', $vendor->id)->first();

        if (!$submission) {
            return $this->error('Vendor is not invited for this trip', 'NOT_INVITED', 403);
        }

        // Validate uploaded files
        $request->validate([
            'documents' => 'required|array|min:1',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB max
        ]);

        try {
            $uploadedDocuments = [];

            foreach ($request->file('documents') as $file) {
                // Store file
                $path = $file->store('trip-submissions/' . $tripId . '/' . $submission->id, 's3');

                // Create document record
                $submission->documents()->create([
                    'document_type' => $request->input('document_type', 'supporting_document'),
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);

                $uploadedDocuments[] = [
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                ];
            }

            return $this->success([
                'message' => 'Documents uploaded successfully',
                'documents' => $uploadedDocuments,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Document upload failed: ' . $e->getMessage(), 'UPLOAD_FAILED', 400);
        }
    }

    /**
     * Get vendor submission details for a trip (vendor-facing)
     */
    public function getVendorSubmission(Request $request, int $tripId)
    {
        $vendor = $this->resolvePortalVendor($request->user());

        if (!$vendor) {
            return $this->error('User is not a vendor', 'NOT_VENDOR', 403);
        }

        $trip = Trip::find($tripId);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $submission = $trip->vendorSubmissions()
            ->where('vendor_id', $vendor->id)
            ->with('documents')
            ->first();

        if (!$submission) {
            return $this->error('No submission found for this vendor', 'NOT_FOUND', 404);
        }

        return $this->success([
            'submission' => [
                'id' => $submission->id,
                'trip_id' => $submission->trip_id,
                'trip_code' => $trip->trip_code,
                'trip_title' => $trip->title,
                'vendor_id' => $submission->vendor_id,
                'vehicle_make' => $submission->vehicle_make,
                'vehicle_model' => $submission->vehicle_model,
                'plate_number' => $submission->plate_number,
                'driver_name' => $submission->driver_name,
                'driver_phone' => $submission->driver_phone,
                'driver_license_no' => $submission->driver_license_no,
                'security_info' => $submission->security_info,
                'quoted_price' => $submission->quoted_price,
                'currency' => $submission->currency,
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at,
                'documents' => $submission->documents->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type,
                        'file_name' => $doc->file_name,
                        'mime_type' => $doc->mime_type,
                        'size' => $doc->size,
                    ];
                }),
            ],
        ]);
    }
}
