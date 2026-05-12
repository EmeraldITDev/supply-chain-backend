<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Models\Logistics\Vehicle;
use Illuminate\Http\Request;

class LogisticsDashboardController extends ApiController
{
    /**
     * Fleet / logistics dashboard aggregates used by the Emerald SCM frontend.
     */
    public function stats(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        $query = Vehicle::query();
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('vendor')) {
            $query->where('vendor_id', $user->vendor_id ?? $user->id);
        }

        $total = (clone $query)->count();
        $active = (clone $query)->where('status', Vehicle::STATUS_ACTIVE)->count();
        $inactive = (clone $query)->where('status', Vehicle::STATUS_INACTIVE)->count();
        $underMaintenance = (clone $query)->where('status', Vehicle::STATUS_UNDER_MAINTENANCE)->count();

        $payload = [
            'vehicles' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'under_maintenance' => $underMaintenance,
            ],
            // Common camelCase aliases for SPA consumers
            'totalVehicles' => $total,
            'activeVehicles' => $active,
            'inactiveVehicles' => $inactive,
            'underMaintenanceVehicles' => $underMaintenance,
            'generated_at' => now()->toIso8601String(),
        ];

        return $this->success($payload);
    }
}
