<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreJourneyRequest;
use App\Http\Requests\Logistics\UpdateJourneyRequest;
use App\Http\Requests\Logistics\UpdateJourneyStatusRequest;
use App\Models\Logistics\Journey;
use App\Models\Logistics\Trip;
use App\Models\Logistics\Report;
use App\Models\User;
use App\Http\Requests\Logistics\StoreReportRequest;
use App\Notifications\JourneyStatusUpdatedNotification;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\JourneyService;
use Illuminate\Http\Request;

class JourneyController extends ApiController
{
    public function __construct(private JourneyService $journeyService, private AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('limit', $request->input('per_page', 20))));

        $query = Journey::query()
            ->with(['trip' => fn ($q) => $q->with(['vehicle', 'driver', 'vendor'])])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn ($j) => $this->presentJourney($j))->values()->all();

        return $this->success([
            'journeys' => $items,
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreJourneyRequest $request)
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? Journey::STATUS_NOT_STARTED;
        $data['created_by'] = $request->user()?->id;

        $journey = Journey::create($data);

        $this->auditLogger->log('journey_created', $request->user(), 'journey', (string) $journey->id, $data, $request);

        return $this->success([
            'journey' => $this->presentJourney($journey->load(['trip' => fn ($q) => $q->with(['vehicle', 'driver', 'vendor'])])),
        ], 201);
    }

    public function listByTrip(int $tripId)
    {
        $paginator = Journey::where('trip_id', $tripId)->paginate(20);

        $items = collect($paginator->items())->map(fn ($j) => $this->presentJourney($j))->values()->all();

        return $this->success([
            'journeys' => $items,
        ]);
    }

    public function update(UpdateJourneyRequest $request, int $id)
    {
        $journey = Journey::find($id);

        if (!$journey) {
            return $this->error('Journey not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();

        if (isset($data['status']) && !$this->journeyService->canTransition($journey->status, $data['status'])) {
            return $this->error('Invalid status transition', 'INVALID_TRANSITION', 422);
        }

        $data['updated_by'] = $request->user()?->id;
        $journey->fill($data)->save();

        $this->auditLogger->log('journey_updated', $request->user(), 'journey', (string) $journey->id, $data, $request);

        return $this->success([
            'journey' => $this->presentJourney($journey->fresh()->load(['trip' => fn ($q) => $q->with(['vehicle', 'driver', 'vendor'])])),
        ]);
    }

    public function updateStatus(UpdateJourneyStatusRequest $request, int $id)
    {
        $journey = Journey::find($id);

        if (!$journey) {
            return $this->error('Journey not found', 'NOT_FOUND', 404);
        }

        $previousStatus = $journey->status;
        $status = $request->status;

        if (!$this->journeyService->canTransition($journey->status, $status)) {
            return $this->error('Invalid status transition', 'INVALID_TRANSITION', 422);
        }

        $timestamp = $request->input('timestamp', now());

        $journey->status = $status;
        if ($status === Journey::STATUS_DEPARTED) {
            $journey->departed_at = $timestamp;
        }
        if ($status === Journey::STATUS_ARRIVED) {
            $journey->arrived_at = $timestamp;
        }
        if ($status === Journey::STATUS_EN_ROUTE) {
            $journey->last_checkpoint_at = $timestamp;
            $journey->last_checkpoint_location = $request->input('location');
        }
        $journey->save();

        // Send notification for status change
        try {
            $trip = $journey->trip;
            if ($trip && $trip->vendor && $trip->vendor->email) {
                $trip->vendor->notifyNow(new JourneyStatusUpdatedNotification($journey, $previousStatus, $status));
            }
        } catch (\Exception $e) {
            // Log but don't fail the request if notification fails
            \Log::warning('Failed to send journey status notification', [
                'journey_id' => $journey->id,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }

        $this->auditLogger->log('journey_status_updated', $request->user(), 'journey', (string) $journey->id, ['status' => $status], $request);

        return $this->success([
            'journey' => $this->presentJourney($journey->fresh()->load(['trip' => fn ($q) => $q->with(['vehicle', 'driver', 'vendor'])])),
        ]);
    }

    public function feedback(StoreReportRequest $request, int $id)
    {
        $journey = Journey::find($id);
        if (! $journey) {
            return $this->error('Journey not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();
        $report = Report::create([
            'trip_id' => $journey->trip_id,
            'journey_id' => $journey->id,
            'report_type' => $data['report_type'] ?? 'feedback',
            'payload' => $data['payload'] ?? [],
            'created_by' => $request->user()?->id,
        ]);

        $this->auditLogger->log('journey_feedback_created', $request->user(), 'journey', (string) $journey->id, $report->toArray(), $request);

        return $this->success(['report' => $report], 201);
    }

    /**
     * Present a journey with flat fields merged from linked trip where missing.
     *
     * @param Journey|object $journey
     * @return array<string, mixed>
     */
    private function presentJourney($journey): array
    {
        if (! $journey instanceof Journey) {
            // If it's a stdClass from paginator items, re-hydrate
            $journey = Journey::with(['trip' => fn ($q) => $q->with(['vehicle', 'driver', 'vendor'])])->find($journey->id);
        }

        $trip = $journey->relationLoaded('trip') ? $journey->trip : ($journey->trip ?? null);

        $vehiclePlate = $journey->vehicle_plate_number ?? $trip?->vehicle?->plate_number ?? null;
        $vehicleMake = $journey->vehicle_make ?? $trip?->vehicle?->make ?? null;
        $vehicleModel = $journey->vehicle_model ?? $trip?->vehicle?->model ?? null;
        $driverName = $journey->driver_name ?? $trip?->driver?->name ?? ($trip?->external_driver['name'] ?? null);

        // Build passengers list from linked trip if necessary
        $passengers = $journey->passengers ?? null;
        if (($passengers === null || $passengers === []) && $trip) {
            $ids = $trip->passenger_user_ids ?? [];
            $passengers = $ids === [] ? [] : User::whereIn('id', $ids)->get(['id', 'name', 'email', 'phone', 'department'])->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'department' => $u->department,
            ])->values()->all();
        }

        $base = $journey->toArray();
        $base['vehicle_plate_number'] = $vehiclePlate;
        $base['vehicle_make'] = $vehicleMake;
        $base['vehicle_model'] = $vehicleModel;
        $base['driver_name'] = $driverName;
        $base['passengers'] = $passengers;
        $base['trip'] = $trip ? $trip->load(['vehicle', 'driver', 'vendor'])->toArray() : null;

        return $base;
    }
}
