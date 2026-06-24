<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Models\Logistics\Journey;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Services\Logistics\TripCommentService;
use App\Services\Logistics\TripRequestNotificationService;
use App\Services\Logistics\TripRequestProgressTrackerService;
use App\Services\RequesterEditWindowService;
use App\Services\TripRequestWorkflowService;
use App\Support\ExternalPassengerRequest;
use App\Support\PassengerEligibility;
use App\Support\TripBookingRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TripRequestWorkflowController extends ApiController
{
    public function __construct(
        private TripRequestWorkflowService $workflow,
        private TripRequestProgressTrackerService $progressTracker,
        private TripRequestNotificationService $tripRequestNotifications,
        private TripCommentService $commentService,
        private RequesterEditWindowService $requesterEditService,
    ) {
    }

    /**
     * List trip requests for staff (own requests) or logistics inbox (all pending).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated', 'UNAUTHENTICATED', 401);
        }

        $isLogisticsInbox = $this->isLogisticsInternal($user);

        if (! $isLogisticsInbox && ! PassengerEligibility::canCreateTripRequest($user)) {
            return $this->error('You are not allowed to view trip requests', 'FORBIDDEN', 403);
        }

        $perPage = min(100, max(1, (int) $request->input('limit', $request->input('per_page', 50))));

        $query = Trip::query()
            ->where('trip_code', 'like', 'TRQ-%')
            ->with('creator')
            ->orderByDesc('created_at');

        if ($isLogisticsInbox) {
            $statusFilter = strtolower((string) $request->input('status'));

            if ($statusFilter !== '' && ! in_array($statusFilter, ['submitted', 'pending'], true)) {
                $query->where('status', $statusFilter);
            } else {
                // Pending approval inbox: submitted requests still awaiting the first logistics
                // action. Once confirmed (workflow_stage advances) or rejected (status cancelled)
                // they drop out so the queue stays focused on actionable items.
                $query->where('status', Trip::STATUS_SUBMITTED)
                    ->where('workflow_stage', Trip::WORKFLOW_TRIP_REQUEST);
            }
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhereJsonContains('passenger_user_ids', $user->id);
            });

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
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

    /**
     * Organization-wide trip visibility list available to every authenticated staff member.
     *
     * Returns all staff trip requests (pending and approved) across all departments,
     * regardless of who submitted them. This is a separate, broader, read-only view
     * distinct from the Logistics Manager's actionable pending-approval inbox (index()).
     */
    public function allTrips(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated', 'UNAUTHENTICATED', 401);
        }

        $perPage = min(100, max(1, (int) $request->input('limit', $request->input('per_page', 50))));

        $query = Trip::query()
            ->where('trip_code', 'like', 'TRQ-%')
            ->with('creator')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $term = '%' . $request->input('q') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('destination', 'like', $term)
                    ->orWhere('origin', 'like', $term)
                    ->orWhere('purpose', 'like', $term)
                    ->orWhere('trip_code', 'like', $term);
            });
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

        // Organization-wide read-only visibility: any authenticated staff member may view full
        // trip detail. Action capabilities (approve/reject/assign/edit) are gated separately on
        // their own endpoints and surfaced via the `viewer`/`canManage` flags in the payload.
        if (! $request->user()) {
            return $this->error('Unauthenticated', 'UNAUTHENTICATED', 401);
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

        if (! $request->user()) {
            return $this->error('Unauthenticated', 'UNAUTHENTICATED', 401);
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

        $isDraft = $request->boolean('save_as_draft')
            || $request->boolean('saveAsDraft')
            || $request->boolean('isDraft');

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
            'status' => $isDraft ? Trip::STATUS_DRAFT : Trip::STATUS_SUBMITTED,
            'workflow_stage' => Trip::WORKFLOW_TRIP_REQUEST,
            'approval_status' => $isDraft ? 'draft' : 'submitted',
            'trip_type' => Trip::TYPE_PERSONNEL,
            'booking_scope' => $bookingScope,
            'created_by' => $user->id,
        ]);

        if (! $isDraft) {
            $this->tripRequestNotifications->notifySubmitted($trip, $user);
        }

        return $this->success([
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true, viewer: $user),
            'bookingRules' => $this->bookingRulesPayload(),
        ], 201);
    }

    /**
     * Update a trip request within the requester edit window (creator only).
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

        $editCheck = $this->requesterEditService->evaluateTripEdit($user, $trip);
        if (! $editCheck['allowed']) {
            return $this->error($editCheck['message'], $editCheck['code'], 403);
        }

        $before = $trip->only([
            'destination', 'purpose', 'origin', 'scheduled_departure_at',
            'scheduled_arrival_at', 'passenger_user_ids', 'booking_scope', 'external_passengers',
        ]);

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
            'remarks' => 'nullable|string|max:1000',
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
        $trip->refresh();

        $changedFields = $this->requesterEditService->detectChangedFieldLabels(
            $before,
            $trip->only(array_keys($before)),
            [
                'destination' => 'destination',
                'purpose' => 'purpose',
                'origin' => 'origin',
                'departure' => 'scheduled_departure_at',
                'arrival' => 'scheduled_arrival_at',
                'passengers' => 'passenger_user_ids',
                'booking scope' => 'booking_scope',
                'external passengers' => 'external_passengers',
            ]
        );

        $remarks = $request->input('remarks');
        $changeSummary = $this->requesterEditService->summarizeChangedFields($changedFields);
        $this->requesterEditService->recordTripEdit($trip, $user, $remarks, $changedFields);
        $this->tripRequestNotifications->notifyRequesterUpdated($trip, $user, $changeSummary);

        return $this->success([
            'trip' => $this->presentTripRequest($trip->load(['creator']), includeProgressSummary: true, viewer: $user),
        ]);
    }

    /**
     * Logistics manager approves a trip request, assigns vehicle/driver, and creates a linked logistics trip.
     */
    public function confirm(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user || ! $this->isLogisticsInternal($user)) {
            return $this->error('Only logistics managers can confirm trip requests', 'FORBIDDEN', 403);
        }

        $tripRequest = Trip::with('creator')->find($id);
        if (! $tripRequest || ! str_starts_with((string) $tripRequest->trip_code, 'TRQ-')) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        $metadata = is_array($tripRequest->metadata) ? $tripRequest->metadata : [];
        $existingLogisticsTripId = $metadata['logistics_trip_id'] ?? $metadata['logisticsTripId'] ?? null;

        if ($existingLogisticsTripId) {
            $logisticsTrip = Trip::with(['vendor', 'vehicle', 'driver'])->find($existingLogisticsTripId);

            return $this->success([
                'trip' => $this->presentTripRequest($tripRequest, includeProgressSummary: true, viewer: $user),
                'logistics_trip_id' => (int) $existingLogisticsTripId,
                'logisticsTripId' => (int) $existingLogisticsTripId,
                'logisticsTrip' => $logisticsTrip,
                'message' => 'Trip request was already confirmed',
            ]);
        }

        if ($tripRequest->status === Trip::STATUS_CANCELLED) {
            return $this->error('Cannot confirm a rejected trip request', 'INVALID_STATE', 422);
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
        $driverUserId = $driverType === 'internal' ? (int) $request->input('driver_user_id') : null;
        $externalDriver = $driverType === 'external' ? $request->input('external_driver') : null;

        $logisticsTrip = DB::transaction(function () use ($tripRequest, $user, $request, $metadata, $driverUserId, $externalDriver) {
            $logisticsTrip = Trip::create([
                'trip_code' => 'TRIP-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'title' => $tripRequest->title ?? ('Trip: ' . $tripRequest->destination),
                'purpose' => $tripRequest->purpose,
                'origin' => $tripRequest->origin,
                'destination' => $tripRequest->destination,
                'scheduled_departure_at' => $tripRequest->scheduled_departure_at,
                'scheduled_arrival_at' => $tripRequest->scheduled_arrival_at,
                'passenger_user_ids' => $tripRequest->passenger_user_ids,
                'external_passengers' => $tripRequest->external_passengers,
                'booking_scope' => $tripRequest->booking_scope,
                'trip_type' => Trip::TYPE_PERSONNEL,
                'status' => Trip::STATUS_SCHEDULED,
                'vehicle_id' => (int) $request->input('vehicle_id'),
                'driver_user_id' => $driverUserId,
                'external_driver' => $externalDriver,
                'notes' => $request->input('notes'),
                'created_by' => $user->id,
                'metadata' => [
                    'trip_request_id' => $tripRequest->id,
                    'trip_request_code' => $tripRequest->trip_code,
                ],
            ]);

            $tripRequest->metadata = array_merge($metadata, [
                'logistics_confirmed_at' => now()->toIso8601String(),
                'logistics_confirmed_by' => $user->id,
                'logistics_trip_id' => $logisticsTrip->id,
            ]);
            $tripRequest->workflow_stage = Trip::WORKFLOW_LOGISTICS_REVIEW;
            $tripRequest->status = Trip::STATUS_SUBMITTED;
            $tripRequest->updated_by = $user->id;
            $tripRequest->save();

            Journey::create([
                'trip_id' => $logisticsTrip->id,
                'status' => Journey::STATUS_NOT_STARTED,
                'created_by' => $user->id,
            ]);

            return $logisticsTrip;
        });

        $logisticsTrip->load(['vendor', 'vehicle', 'driver']);
        $this->tripRequestNotifications->notifyConfirmed($tripRequest->fresh(['creator']), $logisticsTrip, $user);

        return $this->success([
            'trip' => $this->presentTripRequest($tripRequest->fresh(['creator']), includeProgressSummary: true, viewer: $user),
            'logistics_trip_id' => $logisticsTrip->id,
            'logisticsTripId' => $logisticsTrip->id,
            'logisticsTrip' => $logisticsTrip,
            'message' => 'Trip request confirmed; logistics trip and journey created',
        ]);
    }

    /**
     * Logistics manager rejects a pending trip request.
     */
    public function reject(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user || ! $this->isLogisticsInternal($user)) {
            return $this->error('Only logistics managers can reject trip requests', 'FORBIDDEN', 403);
        }

        $trip = Trip::with('creator')->find($id);
        if (! $trip || ! str_starts_with((string) $trip->trip_code, 'TRQ-')) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        if ($trip->status === Trip::STATUS_CANCELLED) {
            return $this->success([
                'trip' => $this->presentTripRequest($trip, includeProgressSummary: true, viewer: $user),
                'message' => 'Trip request was already rejected',
            ]);
        }

        $metadata = is_array($trip->metadata) ? $trip->metadata : [];
        if (! empty($metadata['logistics_trip_id'])) {
            return $this->error('Cannot reject a trip request that has already been confirmed', 'INVALID_STATE', 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $reason = $request->input('reason');
        $trip->status = Trip::STATUS_CANCELLED;
        $trip->metadata = array_merge($metadata, [
            'rejected_at' => now()->toIso8601String(),
            'rejected_by' => $user->id,
            'rejection_reason' => $reason,
        ]);
        $trip->updated_by = $user->id;
        $trip->save();

        $requester = $trip->creator ?? User::find($trip->created_by);
        if ($requester) {
            $this->tripRequestNotifications->notifyRejected($trip, $requester, $reason);
        }

        return $this->success([
            'trip' => $this->presentTripRequest($trip->fresh(['creator']), includeProgressSummary: true, viewer: $user),
            'message' => 'Trip request rejected',
        ]);
    }

    public function getComments(Request $request, int $id)
    {
        $trip = Trip::find($id);
        if (! $trip) {
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        // Organization-wide read-only visibility: any authenticated staff member may read the
        // comment thread for full context. Posting remains restricted to involved parties (addComment).
        if (! $request->user()) {
            return $this->error('Unauthenticated', 'UNAUTHENTICATED', 401);
        }

        return $this->success([
            'comments' => $this->commentService->listForTrip($trip),
            'canComment' => $this->canAccessTripRequest($request->user(), $trip),
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
            return $this->error('Trip request not found', 'NOT_FOUND', 404);
        }

        if (! $this->canAccessTripRequest($user, $trip)) {
            return $this->error('You are not allowed to comment on this trip request', 'FORBIDDEN', 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $comment = $this->commentService->add($trip, $user, (string) $request->input('body'));
        $this->tripRequestNotifications->notifyComment($trip, $user, $comment->body, true);

        return $this->success([
            'comment' => $this->commentService->present($comment),
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
        if (! $user || ! $this->isLogisticsInternal($user)) {
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
            'po_number' => 'nullable|string|max:100',
            'unsigned_po_url' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();

        // Keep an existing number on regeneration; auto-generate in the canonical
        // PO-DDMMYY-SupplierToken-NNNN format when none is supplied.
        $poNumber = trim((string) ($validated['po_number'] ?? '')) !== ''
            ? $validated['po_number']
            : (trim((string) ($trip->po_number ?? '')) !== ''
                ? $trip->po_number
                : app(\App\Services\PoNumberGenerator::class)->generate($this->resolveTripSupplierName($trip)));

        $trip->po_number = $poNumber;
        $trip->unsigned_po_url = $validated['unsigned_po_url'];
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

    /**
     * Carrier/vendor name used as the PO supplier token for a trip.
     */
    private function resolveTripSupplierName(Trip $trip): string
    {
        $vendor = $trip->selectedVendor ?? $trip->vendor;
        if ($vendor && trim((string) $vendor->name) !== '') {
            return (string) $vendor->name;
        }

        return 'Vendor';
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

    private function isLogisticsInternal(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($user->scmRole(), ['logistics_manager', 'logistics_officer', 'admin'], true);
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
     * Whether the viewer is directly involved in the trip (requester, passenger, driver,
     * or a logistics/internal role). Drives whether action controls are surfaced; non-involved
     * staff still have full read-only visibility.
     */
    private function isInvolved(?User $user, Trip $trip): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isLogisticsInternal($user)) {
            return true;
        }

        if ((int) $trip->created_by === (int) $user->id) {
            return true;
        }

        if ($trip->driver_user_id && (int) $trip->driver_user_id === (int) $user->id) {
            return true;
        }

        return in_array($user->id, $trip->passenger_user_ids ?? [], true);
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
        $logisticsTripId = $trip->logisticsTripIdFromMetadata();

        $linkedTrip = $logisticsTripId
            ? Trip::with(['vehicle', 'driver'])->find($logisticsTripId)
            : null;

        $requester = $trip->relationLoaded('creator') ? $trip->creator : null;
        $canManage = $this->isLogisticsInternal($viewer);
        $isInvolved = $this->isInvolved($viewer, $trip);

        $displayStatus = $this->resolveDisplayStatus($trip, $linkedTrip);

        $payload = array_merge(
            $this->requesterEditService->metaForTrip($viewer, $trip),
            [
            'id' => $trip->id,
            'tripId' => $logisticsTripId,
            'trip_id' => $logisticsTripId,
            'logisticsTripId' => $logisticsTripId,
            'logistics_trip_id' => $logisticsTripId,
            'tripCode' => $trip->trip_code,
            'trip_code' => $trip->trip_code,
            'title' => $trip->title,
            'purpose' => $trip->purpose,
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'requesterName' => $requester?->name,
            'requester_name' => $requester?->name,
            'requesterDepartment' => $requester?->department,
            'requester_department' => $requester?->department,
            'requesterEmail' => $requester?->email,
            'requester_email' => $requester?->email,
            'viewer' => [
                'isInvolved' => $isInvolved,
                'canManage' => $canManage,
                'readOnly' => ! $canManage,
            ],
            'canManage' => $canManage,
            'readOnly' => ! $canManage,
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
            'displayStatus' => $displayStatus,
            'display_status' => $displayStatus,
            'displayStatusLabel' => $this->displayStatusLabel($displayStatus),
            'display_status_label' => $this->displayStatusLabel($displayStatus),
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
            ]
        );

        $metadata = is_array($trip->metadata) ? $trip->metadata : [];
        $payload['approvalHistory'] = $metadata['requester_edit_history'] ?? [];
        $payload['approval_history'] = $payload['approvalHistory'];

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

        if ($linkedTrip) {
            $payload['vehicle'] = $linkedTrip->vehicle;
            $payload['driver'] = $linkedTrip->driver;
            $payload['linkedTripStatus'] = $linkedTrip->status;
            $payload['linked_trip_status'] = $linkedTrip->status;
            $payload = array_merge($payload, $linkedTrip->driverApiFields());
        }

        return $payload;
    }

    /**
     * Resolve a single user-facing status for the org-wide trips list, combining the request's
     * own state with the linked logistics trip's operational progress once confirmed.
     */
    private function resolveDisplayStatus(Trip $trip, ?Trip $linkedTrip): string
    {
        if (strtolower((string) $trip->status) === Trip::STATUS_CANCELLED) {
            return 'rejected';
        }

        if ($linkedTrip) {
            return match (strtolower((string) $linkedTrip->status)) {
                Trip::STATUS_COMPLETED, Trip::STATUS_CLOSED => 'completed',
                Trip::STATUS_IN_PROGRESS => 'in_progress',
                Trip::STATUS_CANCELLED => 'rejected',
                default => 'approved',
            };
        }

        if ($this->isDeletableDraft($trip)) {
            return 'draft';
        }

        if (strtolower((string) $trip->status) === Trip::STATUS_SUBMITTED) {
            return 'pending';
        }

        return (string) $trip->status;
    }

    private function displayStatusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
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
