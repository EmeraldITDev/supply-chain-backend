<?php

namespace App\Support;

use App\Models\Logistics\Vehicle;

/**
 * Resolves fleet vehicle routes that may pass either the numeric primary key
 * or a human-readable vehicle_code / plate_number (common SPA pattern).
 */
final class FleetVehicleLookup
{
    public static function byRouteKey(string|int $ref): ?Vehicle
    {
        $s = trim((string) $ref);
        if ($s === '') {
            return null;
        }

        if (ctype_digit($s)) {
            $byId = Vehicle::query()->find((int) $s);
            if ($byId) {
                return $byId;
            }
        }

        return Vehicle::query()
            ->where('vehicle_code', $s)
            ->orWhere('plate_number', $s)
            ->first();
    }
}
