<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreMaintenanceRequest;
use App\Http\Requests\Logistics\StoreVehicleRequest;
use App\Http\Requests\Logistics\UpdateVehicleMaintenanceRequest;
use App\Http\Requests\Logistics\UpdateVehicleRequest;
use App\Http\Requests\Logistics\UpdateVehicleStatusRequest;
use App\Models\SRF;
use App\Models\Logistics\Vehicle;
use App\Models\Logistics\VehicleMaintenance;
use App\Services\Logistics\AuditLogger;
use App\Services\WorkflowNotificationService;
use Carbon\Carbon;
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
        $query = Vehicle::with(['vendor'])
            ->withCount([
                'documents',
                'documents as active_documents_count' => function ($q) {
                    $q->where('is_active', true);
                },
                'maintenances',
            ]);

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
        $vehicle = Vehicle::with(['maintenances', 'vendor'])
            ->withCount([
                'documents',
                'documents as active_documents_count' => function ($q) {
                    $q->where('is_active', true);
                },
                'maintenances',
            ])
            ->find($id);

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

        $payload = $request->validated();
        if (!empty($payload['interval_months']) && !empty($payload['performed_at']) && empty($payload['next_due_at'])) {
            $payload['next_due_at'] = Carbon::parse($payload['performed_at'])
                ->addMonths((int) $payload['interval_months'])
                ->toDateTimeString();
        }
        if (!empty($payload['status'])) {
            $payload['status'] = strtoupper((string) $payload['status']);
        } elseif (!empty($payload['next_due_at'])) {
            $payload['status'] = Carbon::parse($payload['next_due_at'])->greaterThan(Carbon::now())
                ? VehicleMaintenance::STATUS_SCHEDULED
                : VehicleMaintenance::STATUS_OVERDUE;
        } else {
            $payload['status'] = VehicleMaintenance::STATUS_COMPLETED;
        }

        $maintenance = VehicleMaintenance::create(array_merge(
            $payload,
            [
                'vehicle_id' => $vehicle->id,
                'performed_by' => $request->user()?->id,
            ]
        ));

        $this->auditLogger->log('vehicle_maintenance_created', $request->user(), 'vehicle', (string) $vehicle->id, $maintenance->toArray(), $request);

        return $this->success([
            'maintenance' => $maintenance,
            'maintenance_record' => $maintenance,
            'vehicle_id' => $vehicle->id,
        ], 201);
    }

    public function listMaintenance(int $vehicleId)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $records = VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('next_due_at')
            ->orderByDesc('id')
            ->get();

        return $this->success([
            'vehicle_id' => $vehicle->id,
            'maintenance' => $records,
            'maintenance_records' => $records,
        ]);
    }

    public function updateMaintenance(UpdateVehicleMaintenanceRequest $request, int $vehicleId, int $scheduleId)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $maintenance = VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('id', $scheduleId)
            ->first();

        if (!$maintenance) {
            return $this->error('Maintenance record not found', 'NOT_FOUND', 404);
        }

        $payload = $request->validated();
        if (isset($payload['status'])) {
            $payload['status'] = strtoupper((string) $payload['status']);
        }
        if (!empty($payload['interval_months']) && !empty($payload['performed_at']) && empty($payload['next_due_at'])) {
            $payload['next_due_at'] = Carbon::parse($payload['performed_at'])
                ->addMonths((int) $payload['interval_months'])
                ->toDateTimeString();
        }

        $maintenance->fill($payload)->save();

        $this->auditLogger->log('vehicle_maintenance_updated', $request->user(), 'vehicle', (string) $vehicle->id, $maintenance->toArray(), $request);

        return $this->success([
            'maintenance' => $maintenance->fresh(),
        ]);
    }

    public function upcomingMaintenance(Request $request)
    {
        $days = max(1, min(365, (int) $request->get('days', 14)));
        $until = Carbon::now()->addDays($days)->endOfDay();

        $records = VehicleMaintenance::query()
            ->with('vehicle')
            ->where('status', VehicleMaintenance::STATUS_SCHEDULED)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', $until)
            ->where('next_due_at', '>=', Carbon::now()->startOfDay())
            ->orderBy('next_due_at')
            ->get();

        return $this->success([
            'maintenance' => $records,
            'days' => $days,
        ]);
    }

    public function updateVehicleStatus(UpdateVehicleStatusRequest $request, int $id)
    {
        $vehicle = Vehicle::find($id);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $vehicle->status = $request->status;
        if ($request->status === Vehicle::STATUS_ACTIVE) {
            $vehicle->status_inactive_reason = null;
        }
        $vehicle->save();

        $this->auditLogger->log(
            'vehicle_status_override',
            $request->user(),
            'vehicle',
            (string) $vehicle->id,
            'Manual fleet vehicle status override',
            [
                'status' => $vehicle->status,
                'reason' => $request->reason,
                'override_by' => $request->input('override_by', $request->user()?->name),
            ],
            $request
        );

        return $this->success([
            'vehicle' => $vehicle->fresh(),
        ]);
    }

    /**
     * Get fleet alerts for expiring/overdue items
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAlerts(Request $request)
    {
        $days_threshold = max(1, min(365, (int) $request->get('days_threshold', 30)));

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
        $today = $now->copy()->startOfDay();

        foreach ($vehicles as $vehicle) {
            // Check for expiring documents
            foreach ($vehicle->documents as $document) {
                if ($document->is_active === false) {
                    continue;
                }
                if ($document->expires_at) {
                    // Signed days: negative = already expired, positive = days until expiry
                    $days_until_expiry = (int) $today->diffInDays($document->expires_at->copy()->startOfDay(), false);

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
                    $days_overdue = (int) $maintenance->next_due_at->diffInDays($now);

                    $maintSeverity = $days_overdue > 30 ? 'critical' : 'warning';

                    $alerts['overdue_maintenance'][] = [
                        'id' => $maintenance->id,
                        'vehicle_id' => $vehicle->id,
                        'vehicle_code' => $vehicle->vehicle_code,
                        'plate_number' => $vehicle->plate_number,
                        'maintenance_type' => $maintenance->maintenance_type,
                        'description' => $maintenance->description,
                        'next_due_at' => $maintenance->next_due_at,
                        'days_overdue' => $days_overdue,
                        'severity' => $maintSeverity,
                        'status' => $maintenance->status,
                    ];

                    $alerts['summary'][$maintSeverity]++;
                    $alerts['summary']['total_alerts']++;
                }
            }

            // Check vehicle status changes
            if (in_array($vehicle->status, [Vehicle::STATUS_UNDER_MAINTENANCE, Vehicle::STATUS_INACTIVE, 'MAINTENANCE', 'OUT_OF_SERVICE'], true)) {
                $statusSeverity = $vehicle->status === Vehicle::STATUS_INACTIVE || $vehicle->status === 'OUT_OF_SERVICE' ? 'critical' : 'warning';

                $alerts['status_changes'][] = [
                    'id' => $vehicle->id,
                    'vehicle_code' => $vehicle->vehicle_code,
                    'plate_number' => $vehicle->plate_number,
                    'current_status' => $vehicle->status,
                    'updated_at' => $vehicle->updated_at,
                    'severity' => $statusSeverity,
                ];

                $alerts['summary'][$statusSeverity]++;
                $alerts['summary']['total_alerts']++;
            }
        }

        // Sort alerts by severity and date
        usort($alerts['expiring_documents'], function ($a, $b) {
            $severity_order = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $sa = $severity_order[$a['severity']] ?? 2;
            $sb = $severity_order[$b['severity']] ?? 2;
            if ($sa !== $sb) {
                return $sa <=> $sb;
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
        $allowedRoles = ['logistics_officer', 'logistics_manager', 'procurement_manager', 'supply_chain_director', 'admin'];
        $hasAllowedRole = $user && (
            in_array($user->role ?? null, $allowedRoles, true)
            || (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles))
        );
        if (!$hasAllowedRole) {
            return $this->error(
                'You do not have permission to initiate SRF from fleet maintenance.',
                'FORBIDDEN',
                403
            );
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
