<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreFleetDriverRequest;
use App\Http\Requests\Logistics\UpdateFleetDriverRequest;
use App\Models\Logistics\FleetDriver;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;

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

        return $this->success([
            'drivers' => $query->paginate(30),
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
}
