<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Models\Logistics\Trip;
use App\Models\User;
use App\Services\TripRequestWorkflowService;
use App\Support\PassengerEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TripRequestWorkflowController extends ApiController
{
    public function __construct(private TripRequestWorkflowService $workflow)
    {
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !PassengerEligibility::canCreateTripRequest($user)) {
            return $this->error('You are not allowed to create trip requests', 'FORBIDDEN', 403);
        }

        $validator = Validator::make($request->all(), [
            'destination' => 'required|string|max:255',
            'purpose' => 'required|string|max:500',
            'scheduled_departure_at' => 'required|date',
            'scheduled_arrival_at' => 'nullable|date|after_or_equal:scheduled_departure_at',
            'origin' => 'nullable|string|max:255',
            'passenger_user_ids' => 'required|array|min:1',
            'passenger_user_ids.*' => 'integer|exists:users,id',
            'driver_user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $trip = Trip::create([
            'trip_code' => 'TRQ-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
            'title' => 'Trip request: ' . $request->destination,
            'purpose' => $request->purpose,
            'origin' => $request->input('origin', 'Office'),
            'destination' => $request->destination,
            'scheduled_departure_at' => $request->scheduled_departure_at,
            'scheduled_arrival_at' => $request->scheduled_arrival_at,
            'passenger_user_ids' => $request->passenger_user_ids,
            'driver_user_id' => $request->driver_user_id,
            'status' => Trip::STATUS_DRAFT,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'draft',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'created_by' => $user->id,
        ]);

        $this->workflow->advance(
            $trip,
            Trip::WORKFLOW_TRIP_REQUEST,
            "New trip request {$trip->trip_code} submitted by {$user->name}"
        );

        return $this->success(['trip' => $trip->load(['driver', 'creator'])], 201);
    }

    public function convertToLogisticsRequest(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['logistics_manager', 'logistics_officer', 'admin'], true)) {
            return $this->error('Only logistics managers can convert trip requests', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'vendor_id' => 'nullable|exists:vendors,id',
            'vehicle_id' => 'nullable|exists:logistics_vehicles,id',
            'passenger_user_ids' => 'nullable|array',
            'passenger_user_ids.*' => 'integer|exists:users,id',
            'driver_user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $trip->fill($validator->validated());
        $trip->workflow_stage = Trip::WORKFLOW_PROCUREMENT_REVIEW;
        $trip->status = Trip::STATUS_SCHEDULED;
        $trip->updated_by = $user->id;
        $trip->save();

        $this->workflow->notifyStage(
            $trip,
            'trip_logistics_converted',
            "Trip {$trip->trip_code} converted to logistics request and sent to procurement",
            ['procurement_manager', 'procurement']
        );

        return $this->success(['trip' => $trip->fresh(['vendor', 'vehicle', 'driver'])]);
    }

    public function procurementApproveQuote(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['procurement_manager', 'procurement', 'admin'], true)) {
            return $this->error('Procurement role required', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (!$trip || !$trip->selected_vendor_id) {
            return $this->error('Select a vendor quote before approval', 'INVALID_STATE', 422);
        }

        $trip->approval_status = 'approved';
        $trip->workflow_stage = Trip::WORKFLOW_SCD_APPROVAL;
        $trip->updated_by = $user->id;
        $trip->save();

        $this->workflow->advance(
            $trip,
            Trip::WORKFLOW_SCD_APPROVAL,
            "Trip {$trip->trip_code} vendor quote approved by procurement; awaiting Supply Chain Director"
        );

        return $this->success(['trip' => $trip]);
    }

    public function scdApprove(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            return $this->error('Supply Chain Director role required', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $trip->workflow_stage = Trip::WORKFLOW_PO_PENDING_SIGN;
        $trip->updated_by = $user->id;
        $trip->save();

        $this->workflow->notifyStage(
            $trip,
            'trip_scd_approved',
            "Trip {$trip->trip_code} approved by Supply Chain Director; procurement may generate PO",
            ['procurement_manager', 'procurement']
        );

        return $this->success(['trip' => $trip]);
    }

    public function generatePo(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['procurement_manager', 'procurement', 'admin'], true)) {
            return $this->error('Procurement role required', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'po_number' => 'required|string|max:100',
            'unsigned_po_url' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $trip->fill($validator->validated());
        $trip->workflow_stage = Trip::WORKFLOW_PO_PENDING_SIGN;
        $trip->updated_by = $user->id;
        $trip->save();

        $this->workflow->notifyStage(
            $trip,
            'trip_po_generated',
            "PO {$trip->po_number} generated for trip {$trip->trip_code}; awaiting SCD signature",
            ['supply_chain_director', 'supply_chain']
        );

        return $this->success(['trip' => $trip]);
    }

    public function uploadSignedPo(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            return $this->error('Supply Chain Director role required', 'FORBIDDEN', 403);
        }

        $validator = Validator::make($request->all(), [
            'signed_po_url' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $trip = Trip::find($id);
        if (!$trip) {
            return $this->error('Trip not found', 'NOT_FOUND', 404);
        }

        $trip->signed_po_url = $request->signed_po_url;
        $trip->workflow_stage = Trip::WORKFLOW_PO_SIGNED;
        $trip->status = Trip::STATUS_CLOSED;
        $trip->updated_by = $user->id;
        $trip->save();

        $this->workflow->notifyStage(
            $trip,
            'trip_po_signed',
            "Signed PO uploaded for trip {$trip->trip_code}",
            ['procurement_manager', 'procurement']
        );

        return $this->success(['trip' => $trip]);
    }
}
