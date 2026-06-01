<?php

namespace App\Services;

use App\Models\Logistics\Trip;
use App\Models\User;
use App\Notifications\LogisticsEventNotification;
use Illuminate\Support\Facades\Notification;

class TripRequestWorkflowService
{
    public function notifyStage(Trip $trip, string $eventType, string $message, array $roleFilters = []): void
    {
        $query = User::query();
        if ($roleFilters !== []) {
            $query->whereIn('role', $roleFilters);
        }

        $users = $query->whereNotNull('email')->get();
        foreach ($users as $user) {
            try {
                $user->notifyNow(new LogisticsEventNotification($eventType, [
                    'trip_id' => $trip->id,
                    'trip_code' => $trip->trip_code,
                    'workflow_stage' => $trip->workflow_stage,
                    'message' => $message,
                ]));
            } catch (\Throwable) {
                // Non-blocking
            }
        }
    }

    public function advance(Trip $trip, string $stage, ?string $message = null): Trip
    {
        $trip->workflow_stage = $stage;
        $trip->save();

        if ($message) {
            $roles = match ($stage) {
                Trip::WORKFLOW_LOGISTICS_REVIEW => ['logistics_manager', 'logistics_officer'],
                Trip::WORKFLOW_PROCUREMENT_REVIEW => ['procurement_manager', 'procurement'],
                Trip::WORKFLOW_SCD_APPROVAL, Trip::WORKFLOW_PO_PENDING_SIGN => ['supply_chain_director', 'supply_chain'],
                default => [],
            };
            $this->notifyStage($trip, 'trip_workflow_' . $stage, $message, $roles);
        }

        return $trip;
    }
}
