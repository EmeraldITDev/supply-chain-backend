<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Models\Logistics\Trip;
use App\Models\User;
use App\Services\Logistics\TripRequestProgressTrackerService;
use App\Services\TripRequestWorkflowService;
use App\Support\PassengerEligibility;
use App\Support\TripBookingRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TripRequestWorkflowController extends ApiController
{
    public function __construct(
        private TripRequestWorkflowService $workflow,
        private TripRequestProgressTrackerService $progressTracker,
    ) {
    }

    /**
     * List trip requests for the authenticated staff user (creator or passenger).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || ! PassengerEligibility::canCreateTripRequest($user)) {
            return $this->error('You are not allowed to view trip requests', 'FORBIDDEN', 403);
        }

        $perPage = min(100, max(1, (int) $request->input('limit', $request->input('per_page', 50))));

        $query = Trip::query()
            ->where('trip_code', 'like', 'TRQ-%')
            ->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhereJsonContains('passenger_user_ids', $user->id);
            })
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginator = $query->paginate($perPage);
        $trips = collect($paginator->items())
            ->map(fn (Trip $trip) => $this->presentTripRequest($trip, includeProgressSummary: true))
            ->values()
            ->all();

        return $this->success([
            'trips' => $trips,
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        if (! $this->canAccessTripRequest($request->user(), $trip)) {
            return $this->error('You are not allowed to view this trip request', 'FORBIDDEN', 403);
        }

        return $this->success([
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true),
        ]);
    }

    public function progressTracker(Request $request, int $id)
    {
        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        if (! $this->canAccessTripRequest($request->user(), $trip)) {
            return $this->error('You are not allowed to view this trip request', 'FORBIDDEN', 403);
        }

        return $this->success([
            'progress' => $this->progressTracker->build($trip),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user || ! PassengerEligibility::canCreateTripRequest($user)) {
            return $this->error('You are not allowed to create trip requests', 'FORBIDDEN', 403);
        }

        $bookingScope = TripBookingRules::normalizeScope(
            $request->input('bookingScope', $request->input('booking_scope', $request->input('tripType', $request->input('trip_type'))))
        );

        $validator = Validator::make($request->all(), [
            'destination' => 'required|string|max:255',
            'purpose' => 'required|string|max:500',
            'scheduled_departure_at' => 'required|date',
            'scheduled_arrival_at' => 'nullable|date|after_or_equal:scheduled_departure_at',
            'origin' => 'nullable|string|max:255',
            'passenger_user_ids' => 'required|array|min:1',
            'passenger_user_ids.*' => 'integer|exists:users,id',
            'bookingScope' => 'nullable|string',
            'booking_scope' => 'nullable|string',
            'tripType' => 'nullable|string',
            'trip_type' => 'nullable|string',
        ]);

        if ($bookingScope === null) {
            $validator->after(function ($v) {
                $v->errors()->add(
                    'bookingScope',
                    'Trip type is required. Choose Within State or Outside State.'
                );
            });
        }

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $leadCheck = TripBookingRules::validateDeparture($bookingScope, $request->scheduled_departure_at);
        if (! $leadCheck['valid']) {
            return $this->error($leadCheck['message'], 'BOOKING_LEAD_TIME_VIOLATION', 422, [
                'bookingScope' => [$leadCheck['message']],
                'scheduled_departure_at' => [$leadCheck['message']],
                'minimum_trip_date' => [$leadCheck['minimum_trip_date']],
            ]);
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
            'status' => Trip::STATUS_DRAFT,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => 'draft',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => $bookingScope,
            'created_by' => $user->id,
        ]);

        $this->workflow->advance(
            $trip,
            Trip::WORKFLOW_TRIP_REQUEST,
            "New trip request {$trip->trip_code} submitted by {$user->name}"
        );

        return $this->success([
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true),
            'bookingRules' => $this->bookingRulesPayload(),
        ], 201);
    }

    /**
     * Lead-time rules for the trip request form (frontend can use for inline validation).
     */
    public function bookingRules(Request $request)
    {
        return $this->success([
            'bookingRules' => $this->bookingRulesPayload(),
        ]);
    }

    public function convertToLogisticsRequest(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['logistics_manager', 'logistics_officer', 'admin'], true)) {
            return $this->error('Only logistics managers can convert trip requests', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (! $trip) {
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
        if (! $user || ! in_array($user->role, ['procurement_manager', 'procurement', 'admin'], true)) {
            return $this->error('Procurement role required', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (! $trip || ! $trip->selected_vendor_id) {
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
        if (! $user || ! in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            return $this->error('Supply Chain Director role required', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (! $trip) {
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
        if (! $user || ! in_array($user->role, ['procurement_manager', 'procurement', 'admin'], true)) {
            return $this->error('Procurement role required', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (! $trip) {
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
        if (! $user || ! in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            return $this->error('Supply Chain Director role required', 'FORBIDDEN', 403);
        }

        $validator = Validator::make($request->all(), [
            'signed_po_url' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $trip = Trip::find($id);
        if (! $trip) {
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

    private function canAccessTripRequest(?User $user, Trip $trip): bool
    {
        if (! $user) {
            return false;
        }

        if (in_array($user->role, [
            'logistics_manager', 'logistics_officer', 'procurement_manager', 'procurement',
            'supply_chain_director', 'supply_chain', 'admin',
        ], true)) {
            return true;
        }

        if ((int) $trip->created_by === (int) $user->id) {
            return true;
        }

        $passengers = $trip->passenger_user_ids ?? [];

        return in_array($user->id, $passengers, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTripRequest(Trip $trip, bool $includeProgressSummary = false): array
    {
        $scope = $trip->booking_scope;
        $payload = [
            'id' => $trip->id,
            'tripCode' => $trip->trip_code,
            'trip_code' => $trip->trip_code,
            'title' => $trip->title,
            'purpose' => $trip->purpose,
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'bookingScope' => $scope,
            'booking_scope' => $scope,
            'bookingScopeLabel' => $scope ? TripBookingRules::label($scope) : null,
            'tripType' => $scope,
            'trip_type' => $scope,
            'tripTypeLabel' => $scope ? TripBookingRules::label($scope) : null,
            'scheduledDepartureAt' => $trip->scheduled_departure_at?->toIso8601String(),
            'scheduled_departure_at' => $trip->scheduled_departure_at?->toIso8601String(),
            'scheduledArrivalAt' => $trip->scheduled_arrival_at?->toIso8601String(),
            'scheduled_arrival_at' => $trip->scheduled_arrival_at?->toIso8601String(),
            'passengerUserIds' => $trip->passenger_user_ids ?? [],
            'passenger_user_ids' => $trip->passenger_user_ids ?? [],
            'workflowStage' => $trip->workflow_stage,
            'workflow_stage' => $trip->workflow_stage,
            'status' => $trip->status,
            'approvalStatus' => $trip->approval_status,
            'createdBy' => $trip->created_by,
            'createdAt' => $trip->created_at?->toIso8601String(),
            'created_at' => $trip->created_at?->toIso8601String(),
        ];

        if ($includeProgressSummary) {
            $progress = $this->progressTracker->build($trip);
            $current = collect($progress['steps'])->firstWhere('status', 'in_progress')
                ?? collect($progress['steps'])->last(fn (array $s) => $s['status'] === 'completed');
            $payload['progress'] = $progress;
            $payload['progressSummary'] = [
                'currentStepKey' => $progress['currentStepKey'],
                'currentStepLabel' => $current['label'] ?? null,
                'progressPercent' => $progress['progressPercent'],
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRulesPayload(): array
    {
        return [
            'scopes' => [
                [
                    'value' => TripBookingRules::SCOPE_WITHIN_STATE,
                    'label' => TripBookingRules::label(TripBookingRules::SCOPE_WITHIN_STATE),
                    'minimumLeadDays' => TripBookingRules::LEAD_DAYS_WITHIN_STATE,
                    'violationMessage' => TripBookingRules::violationMessage(TripBookingRules::SCOPE_WITHIN_STATE),
                ],
                [
                    'value' => TripBookingRules::SCOPE_OUTSIDE_STATE,
                    'label' => TripBookingRules::label(TripBookingRules::SCOPE_OUTSIDE_STATE),
                    'minimumLeadDays' => TripBookingRules::LEAD_DAYS_OUTSIDE_STATE,
                    'violationMessage' => TripBookingRules::violationMessage(TripBookingRules::SCOPE_OUTSIDE_STATE),
                ],
            ],
            'referenceDate' => now()->toDateString(),
        ];
    }
}
