<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreFleetDriverRequest;
use App\Http\Requests\Logistics\UpdateFleetDriverRequest;
use App\Mail\DriverAssignedMail;
use App\Mail\DriverAssignmentManagerMail;
use App\Models\Logistics\FleetDriver;
use App\Models\Logistics\Vehicle;
use App\Models\User;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class FleetDriverController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $query = FleetDriver::query()->orderByDesc('id');
        if ($request->filled('q')) {
            $q = '%' . $request->get('q') . '%';
            $query->where(function ($b) use ($q): void {
                $b->where('name', 'like', $q)
                    ->orWhere('email', 'like', $q)
                    ->orWhere('phone_number', 'like', $q);
            });
        }

        $fleetDrivers = $query->get()->map(fn (FleetDriver $d) => [
            'id' => 'fleet_' . $d->id,
            'source' => 'fleet',
            'name' => $d->name,
            'email' => $d->email,
            'phone_number' => $d->phone_number,
            'license_number' => $d->license_number,
            'department' => $d->department,
            'role' => $d->role,
        ]);

        $staffQuery = User::query()
            ->whereIn('supply_chain_role', [
                'logistics_manager',
                'logistics_officer',
                'logistics',
                'employee',
                'regular_staff',
                'general_employee',
            ])
            ->whereNull('vendor_id');

        if ($request->filled('q')) {
            $q = '%' . $request->get('q') . '%';
            $staffQuery->where(function ($b) use ($q): void {
                $b->where('name', 'like', $q)
                    ->orWhere('email', 'like', $q)
                    ->orWhere('phone', 'like', $q);
            });
        }

        $staffDrivers = $staffQuery->get(['id', 'name', 'email', 'phone', 'department', 'supply_chain_role'])
            ->map(fn (User $u) => [
                'id' => 'user_' . $u->id,
                'user_id' => $u->id,
                'source' => 'staff',
                'name' => $u->name,
                'email' => $u->email,
                'phone_number' => $u->phone,
                'license_number' => null,
                'department' => $u->department,
                'role' => $u->supply_chain_role,
            ]);

        $drivers = $fleetDrivers->merge($staffDrivers)
            ->sortBy('name')
            ->values();

        return $this->success([
            'drivers' => [
                'data' => $drivers,
            ],
        ]);
    }

    public function show(int $id)
    {
        $driver = FleetDriver::find($id);
        if (!$driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        return $this->success(['driver' => $driver]);
    }

    public function store(StoreFleetDriverRequest $request)
    {
        $driver = FleetDriver::create($request->validated());
        $this->auditLogger->log('fleet_driver_created', $request->user(), 'fleet_driver', (string) $driver->id, $driver->toArray(), $request);

        return $this->success(['driver' => $driver], 201);
    }

    public function update(UpdateFleetDriverRequest $request, int $id)
    {
        $driver = FleetDriver::find($id);
        if (!$driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        $driver->fill($request->validated())->save();
        $this->auditLogger->log('fleet_driver_updated', $request->user(), 'fleet_driver', (string) $driver->id, $driver->toArray(), $request);

        return $this->success(['driver' => $driver]);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->scmRole(), ['logistics_manager', 'admin'], true)) {
            return $this->error('Only logistics managers or admins can delete drivers', 'FORBIDDEN', 403);
        }

        $driver = FleetDriver::find($id);
        if (!$driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        $driver->delete();
        $this->auditLogger->log('fleet_driver_deleted', $request->user(), 'fleet_driver', (string) $id, [], $request);

        return $this->success(['message' => 'Driver deleted']);
    }

    public function assign(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->scmRole(), ['logistics_manager', 'logistics_officer', 'admin'], true)) {
            return $this->error('Insufficient permissions', 'FORBIDDEN', 403);
        }

        $driver = FleetDriver::find($id);
        if (!$driver) {
            return $this->error('Driver not found', 'NOT_FOUND', 404);
        }

        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'nullable|exists:logistics_vehicles,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $vehicleLabel = null;
        if ($request->filled('vehicle_id')) {
            $vehicle = Vehicle::find($request->vehicle_id);
            $vehicleLabel = $vehicle?->plate_number ?? ('Vehicle #' . $request->vehicle_id);
            $metadata = $driver->metadata ?? [];
            $metadata['assigned_vehicle_id'] = (int) $request->vehicle_id;
            $driver->metadata = $metadata;
            $driver->save();
        }

        if ($driver->email) {
            Mail::to($driver->email)->send(new DriverAssignedMail($driver, $vehicleLabel));
        }

        $managers = User::whereIn('supply_chain_role', ['logistics_manager', 'logistics_officer'])
            ->whereNotNull('email')
            ->get();
        foreach ($managers as $manager) {
            Mail::to($manager->email)->send(new DriverAssignmentManagerMail($driver, $vehicleLabel));
        }

        $this->auditLogger->log('fleet_driver_assigned', $user, 'fleet_driver', (string) $driver->id, [
            'vehicle_id' => $request->vehicle_id,
        ], $request);

        return $this->success([
            'driver' => $driver,
            'notificationsSent' => true,
        ]);
    }
}
