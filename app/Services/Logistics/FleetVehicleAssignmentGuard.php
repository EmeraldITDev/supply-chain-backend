<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Vehicle;
use App\Models\Logistics\VehicleMaintenance;
use Carbon\Carbon;

class FleetVehicleAssignmentGuard
{
    public function warningDays(): int
    {
        return max(1, (int) env('FLEET_MAINT_ASSIGN_WARNING_DAYS', 7));
    }

    /**
     * @return array{hard_block: bool, warning: bool, message: ?string, allow_override: bool}
     */
    public function evaluate(Vehicle $vehicle): array
    {
        $status = strtoupper((string) $vehicle->status);

        if ($status === Vehicle::STATUS_INACTIVE) {
            return [
                'hard_block' => true,
                'warning' => false,
                'message' => 'This vehicle is inactive and cannot be assigned to a trip.',
                'allow_override' => false,
            ];
        }

        $days = $this->warningDays();
        $today = Carbon::today();
        $until = $today->copy()->addDays($days);

        $upcoming = VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', VehicleMaintenance::STATUS_SCHEDULED)
            ->whereNotNull('next_due_at')
            ->whereBetween('next_due_at', [$today->startOfDay(), $until->endOfDay()])
            ->exists();

        if ($upcoming) {
            return [
                'hard_block' => false,
                'warning' => true,
                'message' => "This vehicle has maintenance scheduled within {$days} days. Proceed with assignment?",
                'allow_override' => true,
            ];
        }

        return [
            'hard_block' => false,
            'warning' => false,
            'message' => null,
            'allow_override' => true,
        ];
    }
}
