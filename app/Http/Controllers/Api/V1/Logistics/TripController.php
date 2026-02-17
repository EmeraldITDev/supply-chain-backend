<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\AssignVendorRequest;
use App\Http\Requests\Logistics\BulkUploadTripsRequest;
use App\Http\Requests\Logistics\StoreTripRequest;
use App\Http\Requests\Logistics\UpdateTripRequest;
use App\Models\Logistics\Trip;
use App\Models\Vendor;
use App\Notifications\VendorAssignedToTripNotification;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\IdempotencyService;
use App\Services\Logistics\TripService;
use App\Services\Logistics\UploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TripController extends ApiController
{
    public function __construct(
        private TripService $tripService,
        private AuditLogger $auditLogger,
        private IdempotencyService $idempotency,
        private UploadService $uploadService,
    ) {
    }

    public function store(StoreTripRequest $request)
    {
        if ($cached = $this->idempotency->getCachedResponse($request)) {
            return response()->json($cached['response'], $cached['status']);
        }

        $data = $request->validated();
        $data['trip_code'] = $data['trip_code'] ?? 'TRIP-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        $data['status'] = $data['status'] ?? Trip::STATUS_DRAFT;
        $data['created_by'] = $request->user()?->id;

        $trip = Trip::create($data);

        $this->auditLogger->log(
            'trip_created',
            $request->user(),
            'trip',
            (string) $trip->id,
            "Trip created from {$trip->origin} to {$trip->destination}",
            $trip->toArray(),
            $request
        );

        $response = [
            'trip' => $trip,
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
        $trip = Trip::with(['vendor', 'journeys', 'materials'])->find($id);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'trip' => $trip,
        ]);
    }

    public function update(UpdateTripRequest $request, int $id)
    {
        $trip = Trip::find($id);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();

        if (isset($data['status']) && !$this->tripService->canTransition($trip->status, $data['status'])) {
            return $this->error('Invalid status transition', 'INVALID_TRANSITION', 422);
        }

        $data['updated_by'] = $request->user()?->id;
        $trip->fill($data)->save();

        $this->auditLogger->log('trip_updated', $request->user(), 'trip', (string) $trip->id, $data, $request);

        return $this->success([
            'trip' => $trip,
        ]);
    }

    public function assignVendor(AssignVendorRequest $request, int $id)
    {
        $trip = Trip::find($id);

        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        if (!$this->tripService->canTransition($trip->status, Trip::STATUS_VENDOR_ASSIGNED)) {
            return $this->error('Invalid status transition', 'INVALID_TRANSITION', 422);
        }

        $vendor = Vendor::find($request->vendor_id);
        if (!$vendor) {
            return $this->error('Vendor not found', 'NOT_FOUND', 404);
        }

        $trip->vendor_id = $request->vendor_id;
        $trip->status = Trip::STATUS_VENDOR_ASSIGNED;
        $trip->save();

        // Send notification to vendor
        try {
            if ($vendor->email) {
                $vendor->notify(new VendorAssignedToTripNotification($trip, $vendor));
            }
        } catch (\Exception $e) {
            // Log but don't fail the request if notification fails
            \Log::warning('Failed to send vendor assignment notification', [
                'vendor_id' => $vendor->id,
                'trip_id' => $trip->id,
                'error' => $e->getMessage()
            ]);
        }

        $this->auditLogger->log('trip_vendor_assigned', $request->user(), 'trip', (string) $trip->id, ['vendor_id' => $trip->vendor_id], $request);

        return $this->success([
            'trip' => $trip,
        ]);
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
}
