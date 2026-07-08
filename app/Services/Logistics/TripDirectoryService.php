<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;
use App\Models\User;
use App\Support\TripDisplayStatus;
use Illuminate\Http\Request;

class TripDirectoryService
{
    /**
     * @return array{0: \Illuminate\Database\Eloquent\Builder<Trip>, 1: bool}
     */
    public function buildIndexQuery(Request $request, ?User $viewer): array
    {
        $scope = strtolower((string) $request->input('scope', 'all'));

        $query = Trip::query()
            ->with(['vendor:id,vendor_id,name', 'vehicle:id,plate_number,name', 'creator:id,name,email,department'])
            ->orderByDesc('created_at');

        if ($scope === 'logistics') {
            $query->where('trip_code', 'like', 'TRIP-%');
        } elseif ($scope === 'requests') {
            $query->where('trip_code', 'like', 'TRQ-%');
        }

        if (! $request->boolean('include_drafts')) {
            $query->where(function ($q) {
                $q->where('trip_code', 'not like', 'TRQ-%')
                    ->orWhere('status', '!=', Trip::STATUS_DRAFT);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('workflow_stage')) {
            $query->where('workflow_stage', $request->workflow_stage);
        }

        if ($request->filled('display_status')) {
            $this->applyDisplayStatusFilter($query, (string) $request->display_status);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('trip_type')) {
            $query->where('trip_type', $request->trip_type);
        }

        if ($request->filled('search') || $request->filled('q')) {
            $term = '%' . trim((string) ($request->input('search') ?: $request->input('q'))) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('destination', 'like', $term)
                    ->orWhere('origin', 'like', $term)
                    ->orWhere('trip_code', 'like', $term)
                    ->orWhere('purpose', 'like', $term)
                    ->orWhere('title', 'like', $term);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('scheduled_departure_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('scheduled_departure_at', '<=', $request->date_to);
        }

        return [$query, $this->canManage($viewer)];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentListItem(Trip $trip, bool $canManage, ?User $viewer = null): array
    {
        $trip->loadMissing(['vendor', 'vehicle', 'driver', 'creator']);

        $linkedTrip = $this->resolveLinkedLogisticsTrip($trip);
        $displayStatus = TripDisplayStatus::resolve($trip, $linkedTrip);
        $isTripRequest = TripDisplayStatus::isTripRequest($trip);
        $isInvolved = $viewer && $this->isInvolved($viewer, $trip);

        $payload = [
            'id' => $trip->id,
            'tripCode' => $trip->trip_code,
            'trip_code' => $trip->trip_code,
            'recordType' => $isTripRequest ? 'trip_request' : 'logistics_trip',
            'record_type' => $isTripRequest ? 'trip_request' : 'logistics_trip',
            'title' => $trip->title,
            'purpose' => $trip->purpose,
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'status' => $trip->status,
            'workflowStage' => $trip->workflow_stage,
            'workflow_stage' => $trip->workflow_stage,
            'approvalStatus' => $trip->approval_status,
            'approval_status' => $trip->approval_status,
            'displayStatus' => $displayStatus,
            'display_status' => $displayStatus,
            'displayStatusLabel' => TripDisplayStatus::label($displayStatus),
            'display_status_label' => TripDisplayStatus::label($displayStatus),
            'operationalStatus' => TripDisplayStatus::label($displayStatus),
            'operational_status' => TripDisplayStatus::label($displayStatus),
            'scheduledDepartureAt' => $trip->scheduled_departure_at?->toIso8601String(),
            'scheduled_departure_at' => $trip->scheduled_departure_at?->toIso8601String(),
            'scheduledArrivalAt' => $trip->scheduled_arrival_at?->toIso8601String(),
            'scheduled_arrival_at' => $trip->scheduled_arrival_at?->toIso8601String(),
            'bookingScope' => $trip->booking_scope,
            'booking_scope' => $trip->booking_scope,
            'internationalTransportMode' => $trip->international_transport_mode,
            'international_transport_mode' => $trip->international_transport_mode,
            'requesterName' => $trip->creator?->name,
            'requester_name' => $trip->creator?->name,
            'requesterDepartment' => $trip->creator?->department,
            'requester_department' => $trip->creator?->department,
            'logisticsTripId' => $linkedTrip?->id,
            'logistics_trip_id' => $linkedTrip?->id,
            'linkedTripStatus' => $linkedTrip?->status,
            'linked_trip_status' => $linkedTrip?->status,
            'createdAt' => $trip->created_at?->toIso8601String(),
            'created_at' => $trip->created_at?->toIso8601String(),
            'viewer' => [
                'isInvolved' => $isInvolved,
                'canManage' => $canManage,
                'readOnly' => ! $canManage,
            ],
            'canManage' => $canManage,
            'readOnly' => ! $canManage,
            'detailPath' => $isTripRequest
                ? '/api/trip-requests/' . $trip->id
                : '/api/trips/' . $trip->id,
        ];

        if (! $isTripRequest) {
            $payload = array_merge($payload, $trip->driverApiFields(), [
                'vendor' => $trip->vendor,
                'vehicle' => $trip->vehicle,
            ]);
        }

        return $payload;
    }

    private function resolveLinkedLogisticsTrip(Trip $trip): ?Trip
    {
        if (! TripDisplayStatus::isTripRequest($trip)) {
            return null;
        }

        $linkedId = $trip->logisticsTripIdFromMetadata();

        return $linkedId ? Trip::query()->find($linkedId) : null;
    }

    private function canManage(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($user->scmRole(), [
            'logistics_manager', 'logistics_officer', 'procurement_manager', 'procurement',
            'supply_chain_director', 'supply_chain', 'admin',
        ], true);
    }

    private function isInvolved(User $user, Trip $trip): bool
    {
        if ((int) $trip->created_by === (int) $user->id) {
            return true;
        }

        if ($trip->driver_user_id && (int) $trip->driver_user_id === (int) $user->id) {
            return true;
        }

        return in_array($user->id, $trip->passenger_user_ids ?? [], true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Trip>  $query
     */
    private function applyDisplayStatusFilter($query, string $displayStatus): void
    {
        $displayStatus = strtolower(trim($displayStatus));

        if ($displayStatus === 'changes_requested') {
            $query->where('workflow_stage', Trip::WORKFLOW_CHANGES_REQUESTED);

            return;
        }

        if ($displayStatus === 'under_review') {
            $query->where('workflow_stage', Trip::WORKFLOW_DIRECTOR_REVIEW);

            return;
        }

        if ($displayStatus === 'pending' || $displayStatus === 'submitted') {
            $query->where('status', Trip::STATUS_SUBMITTED)
                ->where('workflow_stage', Trip::WORKFLOW_TRIP_REQUEST);

            return;
        }

        if ($displayStatus === 'draft') {
            $query->where('status', Trip::STATUS_DRAFT)
                ->where('trip_code', 'like', 'TRQ-%');

            return;
        }

        if ($displayStatus === 'rejected') {
            $query->where('status', Trip::STATUS_CANCELLED);
        }
    }
}
