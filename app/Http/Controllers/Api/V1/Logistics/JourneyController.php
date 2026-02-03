<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreJourneyRequest;
use App\Http\Requests\Logistics\UpdateJourneyRequest;
use App\Http\Requests\Logistics\UpdateJourneyStatusRequest;
use App\Models\Logistics\Journey;
use App\Models\Logistics\Trip;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\JourneyService;
use Illuminate\Http\Request;

class JourneyController extends ApiController
{
    public function __construct(private JourneyService $journeyService, private AuditLogger $auditLogger)
    {
    }

    public function store(StoreJourneyRequest $request)
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? Journey::STATUS_NOT_STARTED;
        $data['created_by'] = $request->user()?->id;

        $journey = Journey::create($data);

        $this->auditLogger->log('journey_created', $request->user(), 'journey', (string) $journey->id, $data, $request);

        return $this->success([
            'journey' => $journey,
        ], 201);
    }

    public function listByTrip(int $tripId)
    {
        $journeys = Journey::where('trip_id', $tripId)->paginate(20);

        return $this->success([
            'journeys' => $journeys,
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
            'journey' => $journey,
        ]);
    }

    public function updateStatus(UpdateJourneyStatusRequest $request, int $id)
    {
        $journey = Journey::find($id);

        if (!$journey) {
            return $this->error('Journey not found', 'NOT_FOUND', 404);
        }

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

        $this->auditLogger->log('journey_status_updated', $request->user(), 'journey', (string) $journey->id, ['status' => $status], $request);

        return $this->success([
            'journey' => $journey,
        ]);
    }
}
