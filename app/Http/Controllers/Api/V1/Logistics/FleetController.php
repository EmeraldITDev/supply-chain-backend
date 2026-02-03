<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreMaintenanceRequest;
use App\Http\Requests\Logistics\StoreVehicleRequest;
use App\Http\Requests\Logistics\UpdateVehicleRequest;
use App\Models\Logistics\Vehicle;
use App\Models\Logistics\VehicleMaintenance;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;

class FleetController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function store(StoreVehicleRequest $request)
    {
        $vehicle = Vehicle::create($request->validated());

        $this->auditLogger->log('vehicle_created', $request->user(), 'vehicle', (string) $vehicle->id, $vehicle->toArray(), $request);

        return $this->success([
            'vehicle' => $vehicle,
        ], 201);
    }

    public function index(Request $request)
    {
        $query = Vehicle::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->success([
            'vehicles' => $query->paginate(20),
        ]);
    }

    public function show(int $id)
    {
        $vehicle = Vehicle::with('maintenances')->find($id);

        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        return $this->success([
            'vehicle' => $vehicle,
        ]);
    }

    public function update(UpdateVehicleRequest $request, int $id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $vehicle->fill($request->validated())->save();

        $this->auditLogger->log('vehicle_updated', $request->user(), 'vehicle', (string) $vehicle->id, $vehicle->toArray(), $request);

        return $this->success([
            'vehicle' => $vehicle,
        ]);
    }

    public function storeMaintenance(StoreMaintenanceRequest $request, int $id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $maintenance = VehicleMaintenance::create(array_merge(
            $request->validated(),
            [
                'vehicle_id' => $vehicle->id,
                'performed_by' => $request->user()?->id,
            ]
        ));

        $this->auditLogger->log('vehicle_maintenance_created', $request->user(), 'vehicle', (string) $vehicle->id, $maintenance->toArray(), $request);

        return $this->success([
            'maintenance' => $maintenance,
        ], 201);
    }
}
