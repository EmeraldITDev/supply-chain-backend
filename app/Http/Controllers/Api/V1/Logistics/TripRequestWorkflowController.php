<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Mail\TripExternalPassengerConfirmedMail;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Services\Logistics\TripRequestProgressTrackerService;
use App\Services\TripRequestWorkflowService;
use App\Support\ExternalPassengerRequest;
use App\Support\PassengerEligibility;
use App\Support\TripBookingRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            ->map(fn (Trip $trip) => $this->presentTripRequest($trip, includeProgressSummary: true, viewer: $user))
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
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true, viewer: $request->user()),
        ]);
    }

    /**
     * Permanently delete a staff trip request that is still in draft (creator only).
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user || ! PassengerEligibility::canCreateTripRequest($user)) {
            return $this->error('You are not allowed to delete trip requests', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        if ((int) $trip->created_by !== (int) $user->id) {
            return $this->error('Only the creator can delete this trip request', 'FORBIDDEN', 403);
        }

        if (! $this->isDeletableDraft($trip)) {
            return $this->error(
                'Only draft trip requests can be deleted. Submitted or in-progress trips cannot be removed.',
                'INVALID_STATE',
                422
            );
        }

        $trip->delete();

        return $this->success([
            'message' => 'Draft trip request deleted successfully',
            'deletedId' => $id,
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

        ExternalPassengerRequest::mergeIntoRequest($request);

        $validator = Validator::make($request->all(), array_merge([
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
        ], ExternalPassengerRequest::validationRules()));

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
            'external_passengers' => ExternalPassengerRequest::resolve($request),
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
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true, viewer: $user),
            'bookingRules' => $this->bookingRulesPayload(),
        ], 201);
    }

    /**
     * Update a draft trip request (creator only).
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user || ! PassengerEligibility::canCreateTripRequest($user)) {
            return $this->error('You are not allowed to update trip requests', 'FORBIDDEN', 403);
        }

        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        if ((int) $trip->created_by !== (int) $user->id) {
            return $this->error('Only the creator can update this trip request', 'FORBIDDEN', 403);
        }

        if (! $this->isDeletableDraft($trip)) {
            return $this->error(
                'Only draft trip requests can be edited.',
                'INVALID_STATE',
                422
            );
        }

        ExternalPassengerRequest::mergeIntoRequest($request);

        $validator = Validator::make($request->all(), array_merge([
            'destination' => 'sometimes|required|string|max:255',
            'purpose' => 'sometimes|required|string|max:500',
            'scheduled_departure_at' => 'sometimes|required|date',
            'scheduled_arrival_at' => 'nullable|date|after_or_equal:scheduled_departure_at',
            'origin' => 'nullable|string|max:255',
            'passenger_user_ids' => 'sometimes|required|array|min:1',
            'passenger_user_ids.*' => 'integer|exists:users,id',
            'bookingScope' => 'nullable|string',
            'booking_scope' => 'nullable|string',
            'tripType' => 'nullable|string',
            'trip_type' => 'nullable|string',
        ], ExternalPassengerRequest::validationRules()));

        $bookingScope = TripBookingRules::normalizeScope(
            $request->input('bookingScope', $request->input('booking_scope', $request->input('tripType', $trip->booking_scope)))
        );

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        if ($request->has('scheduled_departure_at') && $bookingScope !== null) {
            $leadCheck = TripBookingRules::validateDeparture($bookingScope, $request->scheduled_departure_at);
            if (! $leadCheck['valid']) {
                return $this->error($leadCheck['message'], 'BOOKING_LEAD_TIME_VIOLATION', 422, [
                    'bookingScope' => [$leadCheck['message']],
                    'scheduled_departure_at' => [$leadCheck['message']],
                    'minimum_trip_date' => [$leadCheck['minimum_trip_date']],
                ]);
            }
        }

        $fill = array_filter([
            'destination' => $request->input('destination'),
            'purpose' => $request->input('purpose'),
            'origin' => $request->input('origin'),
            'scheduled_departure_at' => $request->input('scheduled_departure_at'),
            'scheduled_arrival_at' => $request->input('scheduled_arrival_at'),
            'passenger_user_ids' => $request->input('passenger_user_ids'),
            'booking_scope' => $bookingScope,
        ], fn ($value) => $value !== null);

        if ($request->has('external_passengers') || $request->has('externalPassengers')) {
            $fill['external_passengers'] = ExternalPassengerRequest::resolve($request);
        }

        if (isset($fill['destination'])) {
            $fill['title'] = 'Trip request: ' . $fill['destination'];
        }

        $trip->fill($fill);
        $trip->save();

        return $this->success([
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true, viewer: $user),
        ]);
    }

    /**
     * Logistics manager confirms trip details; notifies external passengers by email.
     */
    public function confirm(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->scmRole(), ['logistics_manager', 'logistics_officer', 'admin'], true)) {
            return $this->error('Only logistics managers can confirm trip requests', 'FORBIDDEN', 403);
        }

        $trip = Trip::with('creator')->find($id);
        if (! $trip || ! str_starts_with((string) $trip->trip_code, 'TRQ-')) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        $metadata = is_array($trip->metadata) ? $trip->metadata : [];
        if (! empty($metadata['logistics_confirmed_at'])) {
            return $this->success([
                'trip' => $this->presentTripRequest($trip, includeProgressSummary: true, viewer: $user),
                'message' => 'Trip request was already confirmed',
            ]);
        }

        $metadata['logistics_confirmed_at'] = now()->toIso8601String();
        $metadata['logistics_confirmed_by'] = $user->id;
        $trip->metadata = $metadata;
        $trip->workflow_stage = Trip::WORKFLOW_LOGISTICS_REVIEW;
        $trip->updated_by = $user->id;
        $trip->save();

        $requester = $trip->creator ?? User::find($trip->created_by);
        if ($requester) {
            foreach ($trip->external_passengers ?? [] as $passenger) {
                if (empty($passenger['email'])) {
                    continue;
                }
                try {
                    Mail::to($passenger['email'])->send(
                        new TripExternalPassengerConfirmedMail($trip, $passenger, $requester)
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to email external trip passenger', [
                        'trip_id' => $trip->id,
                        'email' => $passenger['email'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->workflow->notifyStage(
            $trip,
            'trip_request_confirmed',
            "Trip request {$trip->trip_code} confirmed by logistics",
            ['procurement_manager', 'procurement']
        );

        return $this->success([
            'trip' => $this->presentTripRequest($trip->fresh(['creator']), includeProgressSummary: true, viewer: $user),
            'message' => 'Trip request confirmed; external passengers notified where applicable',
        ]);
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
        if (! $user || ! in_array($user->scmRole(), ['logistics_manager', 'logistics_officer', 'admin'], true)) {
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
        if (! $user || ! in_array($user->scmRole(), ['procurement_manager', 'procurement', 'admin'], true)) {
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
        if (! $user || ! in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'], true)) {
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
        if (! $user || ! in_array($user->scmRole(), ['procurement_manager', 'procurement', 'admin'], true)) {
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
        if (! $user || ! in_array($user->scmRole(), ['supply_chain_director', 'supply_chain', 'admin'], true)) {
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

        return in_array($user->id, $passengers, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function isDeletableDraft(Trip $trip): bool
    {
        if (! str_starts_with((string) $trip->trip_code, 'TRQ-')) {
            return false;
        }

        return strtolower((string) $trip->status) === Trip::STATUS_DRAFT
            && $trip->workflow_stage === Trip::WORKFLOW_TRIP_REQUEST;
    }

    private function presentTripRequest(Trip $trip, bool $includeProgressSummary = false, ?User $viewer = null): array
    {
        $scope = $trip->booking_scope;
        $canDelete = $viewer && $this->isDeletableDraft($trip) && (int) $trip->created_by === (int) $viewer->id;

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
            'passengers' => $this->internalPassengersPayload($trip),
            'externalPassengers' => $trip->external_passengers ?? [],
            'external_passengers' => $trip->external_passengers ?? [],
            'workflowStage' => $trip->workflow_stage,
            'workflow_stage' => $trip->workflow_stage,
            'status' => $trip->status,
            'approvalStatus' => $trip->approval_status,
            'createdBy' => $trip->created_by,
            'createdAt' => $trip->created_at?->toIso8601String(),
            'created_at' => $trip->created_at?->toIso8601String(),
            'canDelete' => $canDelete,
            'isDraft' => $this->isDeletableDraft($trip),
            'ui' => [
                'viewDetails' => [
                    'showButton' => true,
                    'label' => 'View Details',
                    'method' => 'GET',
                    'path' => '/api/trip-requests/' . $trip->id,
                ],
                'deleteDraft' => $canDelete ? [
                    'showButton' => true,
                    'label' => 'Delete draft',
                    'method' => 'DELETE',
                    'path' => '/api/trip-requests/' . $trip->id,
                    'confirmMessage' => 'Are you sure you want to delete this draft trip request? This cannot be undone.',
                ] : null,
            ],
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
     * @return list<array<string, mixed>>
     */
    private function internalPassengersPayload(Trip $trip): array
    {
        $ids = $trip->passenger_user_ids ?? [];
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'email', 'phone', 'department', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'department' => $u->department,
                'role' => $u->scmRole(),
            ])
            ->values()
            ->all();
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
