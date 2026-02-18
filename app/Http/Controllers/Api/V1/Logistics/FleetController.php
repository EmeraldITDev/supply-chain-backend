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

        $this->auditLogger->log(
            'vehicle_created', 
            $request->user(), 
            'vehicle', 
            (string) $vehicle->id, 
            "Added a new vehicle: {$vehicle->make} {$vehicle->model}", // String for description
            $vehicle->toArray(),                                       // Array for payload
            $request
        );

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

    /**
     * Get fleet alerts for expiring/overdue items
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAlerts(Request $request)
    {
        $userId = $request->user()?->id;
        $days_threshold = $request->get('days_threshold', 30); // Alert if expiring within 30 days by default

        // Get all vehicles accessible to user
        $query = Vehicle::with(['maintenances', 'documents']);

        // If user is from a vendor, only show their vehicles
        if ($request->user()?->hasRole('vendor')) {
            $query->where('vendor_id', $request->user()->vendor_id ?? $request->user()->id);
        }

        $vehicles = $query->get();

        $alerts = [
            'expiring_documents' => [],
            'overdue_maintenance' => [],
            'status_changes' => [],
            'recent_incidents' => [],
            'summary' => [
                'total_alerts' => 0,
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
            ]
        ];

        $now = now();
        $threshold_date = $now->clone()->addDays($days_threshold);

        foreach ($vehicles as $vehicle) {
            // Check for expiring documents
            foreach ($vehicle->documents as $document) {
                if ($document->expires_at) {
                    $days_until_expiry = $now->diffInDays($document->expires_at);
                    
                    if ($days_until_expiry <= $days_threshold) {
                        $severity = $days_until_expiry < 0 ? 'critical' : ($days_until_expiry <= 7 ? 'warning' : 'info');
                        
                        $alerts['expiring_documents'][] = [
                            'id' => $document->id,
                            'vehicle_id' => $vehicle->id,
                            'vehicle_code' => $vehicle->vehicle_code,
                            'plate_number' => $vehicle->plate_number,
                            'document_type' => $document->document_type,
                            'file_name' => $document->file_name,
                            'expires_at' => $document->expires_at,
                            'days_until_expiry' => $days_until_expiry,
                            'severity' => $severity,
                            'expired' => $days_until_expiry < 0,
                        ];
                        
                        $alerts['summary'][$severity]++;
                        $alerts['summary']['total_alerts']++;
                    }
                }
            }

            // Check for overdue maintenance
            foreach ($vehicle->maintenances as $maintenance) {
                if ($maintenance->next_due_at && $maintenance->next_due_at < $now) {
                    $days_overdue = $maintenance->next_due_at->diffInDays($now);
                    
                    $alerts['overdue_maintenance'][] = [
                        'id' => $maintenance->id,
                        'vehicle_id' => $vehicle->id,
                        'vehicle_code' => $vehicle->vehicle_code,
                        'plate_number' => $vehicle->plate_number,
                        'maintenance_type' => $maintenance->maintenance_type,
                        'description' => $maintenance->description,
                        'next_due_at' => $maintenance->next_due_at,
                        'days_overdue' => $days_overdue,
                        'severity' => $days_overdue > 30 ? 'critical' : 'warning',
                        'status' => $maintenance->status,
                    ];
                    
                    $alerts['summary']['critical']++;
                    $alerts['summary']['total_alerts']++;
                }
            }

            // Check vehicle status changes
            if ($vehicle->status === 'MAINTENANCE' || $vehicle->status === 'OUT_OF_SERVICE') {
                $alerts['status_changes'][] = [
                    'id' => $vehicle->id,
                    'vehicle_code' => $vehicle->vehicle_code,
                    'plate_number' => $vehicle->plate_number,
                    'current_status' => $vehicle->status,
                    'updated_at' => $vehicle->updated_at,
                    'severity' => $vehicle->status === 'OUT_OF_SERVICE' ? 'critical' : 'warning',
                ];
                
                $alerts['summary']['warning']++;
                $alerts['summary']['total_alerts']++;
            }
        }

        // Sort alerts by severity and date
        usort($alerts['expiring_documents'], function ($a, $b) {
            $severity_order = ['critical' => 0, 'warning' => 1, 'info' => 2];
            if ($severity_order[$a['severity']] !== $severity_order[$b['severity']]) {
                return $severity_order[$a['severity']] <=> $severity_order[$b['severity']];
            }
            return $a['days_until_expiry'] <=> $b['days_until_expiry'];
        });

        usort($alerts['overdue_maintenance'], function ($a, $b) {
            return $b['days_overdue'] <=> $a['days_overdue'];
        });

        return $this->success([
            'alerts' => $alerts,
            'query_parameters' => [
                'days_threshold' => $days_threshold,
                'queried_at' => $now,
            ]
        ]);
    }
}
