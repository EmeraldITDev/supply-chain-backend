<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\AssignVendorRequest;
use App\Http\Requests\Logistics\BulkUploadTripsRequest;
use App\Http\Requests\Logistics\StoreTripRequest;
use App\Http\Requests\Logistics\UpdateTripRequest;
use App\Models\Logistics\Trip;
use App\Models\Logistics\Vehicle;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\IdempotencyService;
use App\Services\Logistics\TripCommentService;
use App\Services\Logistics\TripRequestNotificationService;
use App\Services\Logistics\TripSchedulingNotificationService;
use App\Services\Logistics\TripService;
use App\Services\Logistics\TripVendorSubmissionService;
use App\Services\Logistics\FleetVehicleAssignmentGuard;
use App\Services\Logistics\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TripController extends ApiController
{
    public function __construct(
        private TripService $tripService,
        private AuditLogger $auditLogger,
        private IdempotencyService $idempotency,
        private UploadService $uploadService,
        private TripVendorSubmissionService $submissionService,
        private TripSchedulingNotificationService $schedulingNotifications,
        private TripCommentService $commentService,
        private TripRequestNotificationService $tripRequestNotifications,
    ) {
    }

    public function store(StoreTripRequest $request)
    {
        if ($cached = $this->idempotency->getCachedResponse($request)) {
            return response()->json($cached['response'], $cached['status']);
        }

        $data = $request->validated();
        if ($response = $this->resolveFleetVehicleForTrip($data)) {
            return $response;
        }
        $data['trip_code'] = $data['trip_code'] ?? 'TRIP-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        $data['status'] = $data['status'] ?? Trip::STATUS_DRAFT;
        $data['trip_type'] = $data['trip_type'] ?? Trip::TYPE_PERSONNEL;
        $data['priority'] = $data['priority'] ?? Trip::PRIORITY_NORMAL;
        $data['created_by'] = $request->user()?->id;

        $trip = Trip::create($data);
        $trip->load(['vendor', 'vehicle', 'driver']);

        $this->schedulingNotifications->notifyTripCreated($trip);

        $logDescription = $request->input('purpose')
            ?? "Trip created from {$trip->origin} to {$trip->destination}";

        $this->auditLogger->log(
            'trip_created',
            $request->user(),
            'trip',
            (string) $trip->id,
            $logDescription,
            $trip->toArray(),
            $request
        );

        $response = [
            'trip' => $this->presentTrip($trip),
        ];

        $this->idempotency->storeResponse($request, ['success' => true, 'data' => $response], 201);

        return $this->success($response, 201);
    }

    public function index(Request $request)
    {
        $query = Trip::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        return $this->success([
            'trips' => $query->paginate(20),
        ]);
    }

    public function show(int $id)
    {
        $trip = Trip::with(['vendor', 'vehicle', 'journeys', 'materials'])->find($id);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'trip' => $this->presentTrip($trip),
        ]);
    }

    public function update(UpdateTripRequest $request, int $id)
    {
        $trip = Trip::find($id);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();

        if ($response = $this->resolveFleetVehicleForTrip($data)) {
            return $response;
        }

        if (isset($data['status'])) {
            $blockReason = app(TripVendorSubmissionService::class)->statusAdvancementBlockedReason($trip, $data['status']);
            if ($blockReason !== null) {
                return $this->error($blockReason, 'VENDOR_SUBMISSION_REQUIRED', 422);
            }
            if (!$this->tripService->canTransition($trip->status, $data['status'])) {
                return $this->error('Invalid status transition', 'INVALID_TRANSITION', 422);
            }
        }

        $previousPassengerIds = $trip->passenger_user_ids ?? [];
        $previousDriverUserId = $trip->driver_user_id ? (int) $trip->driver_user_id : null;
        $previousExternalDriver = is_array($trip->external_driver) ? $trip->external_driver : null;

        $data['updated_by'] = $request->user()?->id;
        $trip->fill($data)->save();
        $trip->load(['vendor', 'vehicle', 'driver']);

        if (array_key_exists('passenger_user_ids', $data)) {
            $this->schedulingNotifications->notifyPassengerListChanged(
                $trip,
                $previousPassengerIds,
                $trip->passenger_user_ids ?? []
            );
        }

        if (array_key_exists('driver_user_id', $data) || array_key_exists('external_driver', $data)) {
            $this->schedulingNotifications->notifyDriverReassignment(
                $trip,
                $previousDriverUserId,
                $previousExternalDriver,
                $trip->driver_user_id ? (int) $trip->driver_user_id : null,
                is_array($trip->external_driver) ? $trip->external_driver : null,
            );
        }

        $this->auditLogger->log(
            'trip_updated',
            $request->user(),
            'trip',
            (string) $trip->id,
            $request->input('purpose') ?? "Updated trip details for {$trip->trip_code}",
            $request->validated(),
            $request
        );

        return $this->success([
            'trip' => $this->presentTrip($trip),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveFleetVehicleForTrip(array &$data): ?JsonResponse
    {
        if (!array_key_exists('vehicle_id', $data)) {
            return null;
        }

        $vehicleId = $data['vehicle_id'];
        $confirmed = (bool) ($data['confirm_vehicle_assignment_override'] ?? false);
        unset($data['confirm_vehicle_assignment_override']);

        if ($vehicleId === null) {
            return null;
        }

        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $guard = app(FleetVehicleAssignmentGuard::class)->evaluate($vehicle);

        if ($guard['hard_block']) {
            return $this->error($guard['message'] ?? 'Vehicle cannot be assigned', 'VEHICLE_INACTIVE', 422);
        }

        if ($guard['warning'] && !$confirmed) {
            return response()->json([
                'success' => false,
                'warning' => true,
                'message' => $guard['message'],
                'allow_override' => $guard['allow_override'],
            ], 422);
        }

        return null;
    }

    public function assignVendor(AssignVendorRequest $request, int $id)
    {
        $trip = Trip::find($id);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        if (!in_array($trip->status, [Trip::STATUS_DRAFT, Trip::STATUS_SCHEDULED], true)) {
            return $this->error('Trip must be draft or scheduled to assign a vendor', 'INVALID_STATUS', 422);
        }

        $vendor = Vendor::find($request->vendor_id);
        if (!$vendor) {
            return $this->error('Vendor not found', 'NOT_FOUND', 404);
        }

        $emailSent = false;
        $emailError = null;

        try {
            $trip->vendor_id = $request->vendor_id;
            $trip->status = Trip::STATUS_DRAFT;
            if (empty($trip->approval_status)) {
                $trip->approval_status = 'draft';
            }
            $trip->save();

            // Same workflow as POST /trips/{id}/invite-vendors: create a pending
            // TripVendorSubmission and send the trip quote (RFQ) email with a
            // vendor-portal link so responses appear under Compare Vendor Responses.
            // notifyExisting=true resends the invitation if a submission already existed.
            // Email failure must never roll back the assignment or return HTTP 500.
            try {
                $submissionResult = $this->submissionService->createSubmissionsForVendors(
                    $trip->fresh(),
                    [(int) $request->vendor_id],
                    true
                );
                $emailSent = (bool) ($submissionResult['invitation']['sent'] ?? false);
                $emailError = $submissionResult['invitation']['error'] ?? null;
            } catch (\Throwable $submissionException) {
                \Log::error('Trip vendor invite failed after assignment', [
                    'trip_id' => $trip->id,
                    'vendor_id' => $vendor->id,
                    'error' => $submissionException->getMessage(),
                ]);

                $emailSent = false;
                $emailError = $submissionException->getMessage();
            }

            try {
                $this->auditLogger->log(
                    'trip_vendor_assigned',
                    $request->user(),
                    'trip',
                    (string) $trip->id,
                    "Vendor #{$vendor->id} ({$vendor->name}) assigned to trip {$trip->trip_code}",
                    ['vendor_id' => $trip->vendor_id, 'email_sent' => $emailSent],
                    $request
                );
            } catch (\Throwable $auditException) {
                \Log::warning('Failed to write audit log for vendor assignment', [
                    'trip_id' => $trip->id,
                    'vendor_id' => $vendor->id,
                    'error' => $auditException->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('Trip vendor assignment failed', [
                'trip_id' => $id,
                'vendor_id' => $request->vendor_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                'Failed to assign vendor. Please try again.',
                'ASSIGNMENT_FAILED',
                500,
                config('app.debug') ? ['exception' => [$e->getMessage()]] : []
            );
        }

        $freshTrip = $trip->fresh(['vendor']);
        $payload = array_merge($freshTrip->toArray(), [
            'assigned' => true,
            'email_sent' => $emailSent,
        ]);

        if (! $emailSent && $emailError) {
            $payload['email_error'] = $emailError;
        }

        return $this->success($payload);
    }

    public function bulkUpload(BulkUploadTripsRequest $request)
    {
        [$validRows, $errors] = $this->uploadService->validateRows($request->rows, ['title', 'origin', 'destination']);

        $created = [];
        foreach ($validRows as $row) {
            $created[] = Trip::create([
                'trip_code' => 'TRIP-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'title' => $row['title'],
                'origin' => $row['origin'],
                'destination' => $row['destination'],
                'scheduled_departure_at' => $row['scheduled_departure_at'] ?? null,
                'scheduled_arrival_at' => $row['scheduled_arrival_at'] ?? null,
                'status' => Trip::STATUS_DRAFT,
                'created_by' => $request->user()?->id,
            ]);
        }

        return $this->success([
            'created' => $created,
            'errors' => $errors,
        ], count($errors) > 0 ? 207 : 201);
    }

    public function assignResources(Request $request, int $id)
    {
        $trip = Trip::find($id);

        if (! $trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|exists:logistics_vehicles,id',
            'driver_type' => 'required|in:internal,external',
            'driver_user_id' => 'required_if:driver_type,internal|nullable|integer|exists:users,id',
            'external_driver' => 'required_if:driver_type,external|nullable|array',
            'external_driver.name' => 'required_if:driver_type,external|nullable|string|max:255',
            'external_driver.phone' => 'nullable|string|max:50',
            'external_driver.email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $driverType = (string) $request->input('driver_type');
        $previousDriverUserId = $trip->driver_user_id ? (int) $trip->driver_user_id : null;
        $previousExternalDriver = is_array($trip->external_driver) ? $trip->external_driver : null;

        $trip->vehicle_id = (int) $request->input('vehicle_id');
        $trip->driver_user_id = $driverType === 'internal' ? (int) $request->input('driver_user_id') : null;
        $trip->external_driver = $driverType === 'external' ? $request->input('external_driver') : null;
        if ($request->has('notes')) {
            $trip->notes = $request->input('notes');
        }
        $trip->updated_by = $request->user()?->id;
        $trip->save();
        $trip->load(['vendor', 'vehicle', 'driver']);

        $this->schedulingNotifications->notifyDriverReassignment(
            $trip,
            $previousDriverUserId,
            $previousExternalDriver,
            $trip->driver_user_id ? (int) $trip->driver_user_id : null,
            is_array($trip->external_driver) ? $trip->external_driver : null,
        );

        return $this->success([
            'trip' => $this->presentTrip($trip),
        ]);
    }

    public function getComments(Request $request, int $id)
    {
        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        if (! $this->canAccessTrip($request->user(), $trip)) {
            return $this->error('You are not allowed to view comments on this trip', 'FORBIDDEN', 403);
        }

        return $this->success([
            'comments' => $this->commentService->listForTrip($trip),
        ]);
    }

    public function addComment(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated', 'UNAUTHENTICATED', 401);
        }

        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        if (! $this->canAccessTrip($user, $trip)) {
            return $this->error('You are not allowed to comment on this trip', 'FORBIDDEN', 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $comment = $this->commentService->add($trip, $user, (string) $request->input('body'));
        $this->tripRequestNotifications->notifyComment($trip, $user, $comment->body, false);

        return $this->success([
            'comment' => $this->commentService->present($comment),
        ], 201);
    }

    public function cancel(int $id, Request $request)
    {
        $trip = Trip::find($id);
    
        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }
    
        // Check if trip can be cancelled/deleted (not already completed, closed, or cancelled)
        if (in_array($trip->status, [Trip::STATUS_COMPLETED, Trip::STATUS_CLOSED])) {
            return $this->error(
                'Cannot delete a trip with status: ' . $trip->status,
                'INVALID_STATUS',
                422
            );
        }
    
        // Capture data for audit before deletion
        $tripData = $trip->toArray();
    
        // Delete the trip
        $trip->delete();
    
        $this->auditLogger->log(
            'trip_deleted',
            $request->user(),
            'trip',
            (string) $id,
            'Trip deleted from system',
            [
                'deleted_by' => $request->user()?->id,
                'deleted_at' => now(),
                'trip_data' => $tripData,
            ],
            $request
        );
    
        return $this->success([
            'message' => 'Trip deleted successfully',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTrip(Trip $trip): array
    {
        $trip->loadMissing(['vendor', 'vehicle', 'driver']);

        return array_merge($trip->toArray(), $trip->driverApiFields());
    }

    private function canAccessTrip(?User $user, Trip $trip): bool
    {
        if (! $user) {
            return false;
        }

        if (in_array($user->scmRole(), [
            'logistics_manager', 'logistics_officer', 'procurement_manager', 'procurement',
            'supply_chain_director', 'supply_chain', 'admin',
        ], true)) {
            return true;
        }

        if ((int) $trip->created_by === (int) $user->id) {
            return true;
        }

        $passengers = $trip->passenger_user_ids ?? [];

        return in_array($user->id, $passengers, true)
            || (int) $trip->driver_user_id === (int) $user->id;
    }
    
}
