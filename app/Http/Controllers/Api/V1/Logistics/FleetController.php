<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreMaintenanceRequest;
use App\Http\Requests\Logistics\StoreVehicleRequest;
use App\Http\Requests\Logistics\UpdateVehicleRequest;
use App\Models\SRF;
use App\Models\Logistics\Vehicle;
use App\Models\Logistics\VehicleMaintenance;
use App\Services\Logistics\AuditLogger;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FleetController extends ApiController
{
    public function __construct(
        private AuditLogger $auditLogger,
        private WorkflowNotificationService $workflowNotificationService
    )
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
            "Created vehicle: " . $vehicle->plate_number, // Description (String)
            $vehicle->toArray(),                           // Payload (Array)
            $request                                       // Request object
        );

        return $this->success([
            'vehicle' => $vehicle,
        ], 201);
    }

    public function index(Request $request)
    {
        $query = Vehicle::with(['vendor']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $vehicles = $query->paginate(20);

        return $this->success([
            'vehicles' => $vehicles,
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

    public function destroy(int $id, Request $request)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $this->auditLogger->log(
            'vehicle_deleted', 
            $request->user(), 
            'vehicle', 
            (string)$vehicle->id, 
            "Deleted vehicle: {$vehicle->plate_number}", // 5th: Description (String)
            $vehicle->toArray(),                          // 6th: Payload (Array) - NOT $request
            $request                                      // 7th: Request Object
        );
    
        $vehicle->delete();

        return $this->success([
            'message' => 'Vehicle deleted successfully',
            'vehicle_id' => $id,
        ]);
    }

    public function initiateSrf(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'logistics_officer') {
            return $this->error('Only logistics officers can initiate SRF from fleet maintenance.', 'FORBIDDEN', 403);
        }

        $vehicle = Vehicle::with('maintenances')->find($id);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $maintenance = $vehicle->maintenances()
            ->orderByDesc('performed_at')
            ->orderByDesc('created_at')
            ->first();

        $validator = Validator::make($request->all(), [
            'maintenance_type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'urgency' => 'nullable|in:Low,Medium,High,Critical',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $maintenanceType = $request->input('maintenance_type', $maintenance?->maintenance_type ?? 'Vehicle Maintenance');
        $maintenanceDescription = $request->input('description', $maintenance?->description ?? 'Vehicle flagged for maintenance from fleet dashboard.');

        $srf = SRF::create([
            'srf_id' => SRF::generateSRFId(),
            'formatted_id' => null,
            'title' => 'Fleet Maintenance SRF - ' . ($vehicle->plate_number ?? $vehicle->vehicle_code),
            'service_type' => $maintenanceType,
            'contract_type' => 'EMERALD',
            'urgency' => $request->input('urgency', 'Medium'),
            'description' => trim(sprintf(
                "Vehicle ID: %s\nPlate Number: %s\nMaintenance Type: %s\nFlagged Description: %s",
                $vehicle->id,
                $vehicle->plate_number ?? 'N/A',
                $maintenanceType,
                $maintenanceDescription
            )),
            'duration' => 'TBD',
            'estimated_cost' => (float) ($maintenance?->cost ?? 0),
            'justification' => 'Auto-initiated from Fleet Maintenance dashboard.',
            'requester_id' => $user->id,
            'requester_name' => $user->name,
            'department' => $user->department,
            'date' => now(),
            'status' => 'Pending',
            'current_stage' => 'procurement',
            'approval_history' => [],
            'remarks' => 'pending_procurement',
        ]);

        try {
            $srf->loadMissing('requester');
            $this->workflowNotificationService->notifySRFSubmitted($srf);
        } catch (\Throwable) {
            // Do not fail SRF creation when notification fails.
        }

        return $this->success([
            'srf' => [
                'id' => $srf->srf_id,
                'title' => $srf->title,
                'serviceType' => $srf->service_type,
                'department' => $srf->department,
                'status' => 'pending_procurement',
                'currentStage' => $srf->current_stage,
                'description' => $srf->description,
            ],
        ], 201);
    }
}
