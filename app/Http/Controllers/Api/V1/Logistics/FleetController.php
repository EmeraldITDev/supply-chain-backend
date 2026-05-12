<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Requests\Logistics\StoreMaintenanceRequest;
use App\Http\Requests\Logistics\StoreVehicleRequest;
use App\Http\Requests\Logistics\UpdateVehicleMaintenanceRequest;
use App\Http\Requests\Logistics\UpdateVehicleRequest;
use App\Http\Requests\Logistics\UpdateVehicleStatusRequest;
use App\Models\SRF;
use App\Models\Logistics\Document;
use App\Models\Logistics\Vehicle;
use App\Models\Logistics\VehicleMaintenance;
use App\Services\Logistics\AuditLogger;
use App\Services\WorkflowNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        $vehicle = Vehicle::with([
                'vendor',
                'maintenances' => function ($q) {
                    $q->orderByDesc('next_due_at')->orderByDesc('id');
                },
                'maintenances.documents',
                'maintenances.performer',
                'documents',
            ])
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

        // Decorate maintenances with an explicit `documents` array (in case
        // the morph relation isn't auto-serialised) and include camelCase
        // aliases the frontend uses.
        $maintenances = $vehicle->maintenances->map(function ($m) {
            return [
                'id' => $m->id,
                'vehicle_id' => $m->vehicle_id,
                'maintenance_type' => $m->maintenance_type,
                'maintenanceType' => $m->maintenance_type,
                'interval_months' => $m->interval_months,
                'intervalMonths' => $m->interval_months,
                'description' => $m->description,
                'performed_at' => optional($m->performed_at)->toIso8601String(),
                'performedAt' => optional($m->performed_at)->toIso8601String(),
                'last_maintenance_date' => optional($m->performed_at)->toIso8601String(),
                'lastMaintenanceDate' => optional($m->performed_at)->toIso8601String(),
                'next_due_at' => optional($m->next_due_at)->toIso8601String(),
                'nextDueAt' => optional($m->next_due_at)->toIso8601String(),
                'next_maintenance_date' => optional($m->next_due_at)->toIso8601String(),
                'nextMaintenanceDate' => optional($m->next_due_at)->toIso8601String(),
                'cost' => $m->cost !== null ? (float) $m->cost : null,
                'status' => $m->status,
                'metadata' => $m->metadata,
                'performed_by' => $m->performer ? [
                    'id' => $m->performer->id,
                    'name' => $m->performer->name,
                ] : null,
                'documents' => $m->documents->map(function ($d) {
                    return [
                        'id' => $d->id,
                        'document_type' => $d->document_type,
                        'file_name' => $d->file_name,
                        'file_path' => $d->file_path,
                        'mime_type' => $d->mime_type,
                        'size' => $d->size,
                        'expires_at' => optional($d->expires_at)->toIso8601String(),
                        'created_at' => optional($d->created_at)->toIso8601String(),
                    ];
                })->values(),
                'created_at' => optional($m->created_at)->toIso8601String(),
                'updated_at' => optional($m->updated_at)->toIso8601String(),
            ];
        })->values();

        $vehicleArray = $vehicle->toArray();
        // Replace serialised maintenances with our richer presentation while
        // keeping both snake_case and camelCase keys for client compatibility.
        $vehicleArray['maintenances'] = $maintenances;
        $vehicleArray['maintenance_records'] = $maintenances;
        $vehicleArray['maintenanceRecords'] = $maintenances;

        return $this->success([
            'vehicle' => $vehicleArray,
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

        // Inline document attachments — the Maintenance form lets the user
        // pick one or more files. We attach them to the maintenance record
        // via the polymorphic documents table so they can later be deleted
        // via DELETE /api/documents/{id}.
        $uploaded = [];
        $files = $request->file('documents', []);
        if (!is_array($files)) {
            $files = [$files];
        }
        if ($request->hasFile('document')) {
            $files[] = $request->file('document');
        }
        if ($request->hasFile('attachment')) {
            $files[] = $request->file('attachment');
        }
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }
        $files = array_filter($files);

        foreach ($files as $file) {
            try {
                $disk = config('filesystems.logistics_documents_disk', config('filesystems.default'));
                $path = $file->store('logistics/maintenance', $disk);
                $uploaded[] = Document::create([
                    'documentable_type' => VehicleMaintenance::class,
                    'documentable_id' => $maintenance->id,
                    'document_type' => 'maintenance',
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()?->id,
                    'is_active' => true,
                    'metadata' => [],
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Maintenance document upload failed', [
                    'maintenance_id' => $maintenance->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->auditLogger->log(
            'vehicle_maintenance_created',
            $request->user(),
            'vehicle',
            (string) $vehicle->id,
            "Maintenance record added for vehicle {$vehicle->plate_number}",
            array_merge($maintenance->toArray(), ['attached_documents' => count($uploaded)]),
            $request
        );

        return $this->success([
            'maintenance' => $maintenance->fresh('documents'),
            'maintenance_record' => $maintenance->fresh('documents'),
            'vehicle_id' => $vehicle->id,
            'documents' => $uploaded,
        ], 201);
    }

    public function listMaintenance(int $vehicleId)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $records = VehicleMaintenance::query()
            ->with(['documents', 'performer'])
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

        $vehicle = Vehicle::with(['maintenances', 'vendor'])->find($id);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $maintenances = $vehicle->maintenances()
            ->orderByDesc('performed_at')
            ->orderByDesc('created_at')
            ->get();

        $latestMaintenance = $maintenances->first();

        $validator = Validator::make($request->all(), [
            'maintenance_type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'urgency' => 'nullable|in:Low,Medium,High,Critical',
            'estimated_cost' => 'nullable|numeric|min:0',
            'duration' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }

        $maintenanceType = $request->input('maintenance_type', $latestMaintenance?->maintenance_type ?? 'Vehicle Maintenance');
        $maintenanceDescription = $request->input('description', $latestMaintenance?->description ?? 'Vehicle flagged for maintenance from fleet dashboard.');

        $vehicleSnapshot = [
            'id' => $vehicle->id,
            'vehicle_code' => $vehicle->vehicle_code,
            'plate_number' => $vehicle->plate_number,
            'name' => $vehicle->name,
            'type' => $vehicle->type,
            'make' => $vehicle->make ?? $vehicle->make_model,
            'model' => $vehicle->make_model,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'fuel_type' => $vehicle->fuel_type,
            'capacity' => $vehicle->capacity,
            'passenger_capacity' => $vehicle->passenger_capacity,
            'status' => $vehicle->status,
            'status_inactive_reason' => $vehicle->status_inactive_reason,
            'last_service_at' => optional($vehicle->last_service_at)->toIso8601String(),
            'vendor' => $vehicle->vendor ? [
                'id' => $vehicle->vendor->id,
                'name' => $vehicle->vendor->name,
            ] : null,
        ];

        $maintenanceHistory = $maintenances->map(function ($m) {
            return [
                'id' => $m->id,
                'maintenance_type' => $m->maintenance_type,
                'description' => $m->description,
                'interval_months' => $m->interval_months,
                'performed_at' => optional($m->performed_at)->toIso8601String(),
                'next_due_at' => optional($m->next_due_at)->toIso8601String(),
                'cost' => $m->cost !== null ? (float) $m->cost : null,
                'status' => $m->status,
                'metadata' => $m->metadata,
            ];
        })->values()->all();

        $rfqPrefill = [
            'title' => sprintf('RFQ — %s (%s)', $maintenanceType, $vehicle->plate_number ?? $vehicle->vehicle_code ?? "Vehicle #{$vehicle->id}"),
            'scope_of_work' => $maintenanceDescription,
            'currency' => 'NGN',
            'estimated_budget' => $request->input('estimated_cost', $latestMaintenance?->cost),
            'required_specifications' => array_filter([
                $vehicleSnapshot['make'] ? 'Make: ' . $vehicleSnapshot['make'] : null,
                $vehicleSnapshot['model'] ? 'Model: ' . $vehicleSnapshot['model'] : null,
                $vehicleSnapshot['year'] ? 'Year: ' . $vehicleSnapshot['year'] : null,
                $vehicleSnapshot['plate_number'] ? 'Plate: ' . $vehicleSnapshot['plate_number'] : null,
                $vehicleSnapshot['fuel_type'] ? 'Fuel: ' . $vehicleSnapshot['fuel_type'] : null,
            ]),
            'line_items' => [[
                'item_name' => $maintenanceType,
                'description' => $maintenanceDescription,
                'quantity' => 1,
                'unit' => 'service',
            ]],
        ];

        // New default: SRFs from logistics go through the Supply Chain
        // Director first (for budget/justification review) before reaching
        // Procurement. This matches the QA workflow expectation.
        $initialStage = 'supply_chain_director_review';

        $srf = SRF::create([
            'srf_id' => SRF::generateSRFId(),
            'formatted_id' => null,
            'title' => 'Fleet Maintenance SRF - ' . ($vehicle->plate_number ?? $vehicle->vehicle_code),
            'service_type' => $maintenanceType,
            'contract_type' => 'EMERALD',
            'urgency' => $request->input('urgency', 'Medium'),
            'description' => trim(sprintf(
                "Vehicle: %s (%s)\nMake/Model: %s %s %s\nMaintenance Type: %s\nDetails: %s",
                $vehicle->plate_number ?? 'N/A',
                $vehicle->vehicle_code ?? 'N/A',
                $vehicleSnapshot['make'] ?? '',
                $vehicleSnapshot['model'] ?? '',
                $vehicleSnapshot['year'] ?? '',
                $maintenanceType,
                $maintenanceDescription
            )),
            'duration' => $request->input('duration', 'TBD'),
            'estimated_cost' => (float) $request->input('estimated_cost', $latestMaintenance?->cost ?? 0),
            'justification' => 'Auto-initiated from Fleet Maintenance dashboard.',
            'requester_id' => $user->id,
            'requester_name' => $user->name,
            'department' => $user->department ?? 'Logistics',
            'date' => now(),
            'status' => 'Pending',
            'current_stage' => $initialStage,
            'approval_history' => [[
                'stage' => 'logistics_initiated',
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'at' => now()->toIso8601String(),
                'note' => 'Fleet maintenance SRF auto-generated from dashboard.',
            ]],
            'remarks' => 'pending_supply_chain_director_review',
            'vehicle_id' => $vehicle->id,
            'maintenance_id' => $latestMaintenance?->id,
            'vehicle_snapshot' => $vehicleSnapshot,
            'maintenance_history' => $maintenanceHistory,
            'rfq_prefill' => $rfqPrefill,
            'origin' => 'fleet_dashboard',
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
                'status' => 'pending_supply_chain_director_review',
                'currentStage' => $srf->current_stage,
                'description' => $srf->description,
                'vehicleId' => $srf->vehicle_id,
                'maintenanceId' => $srf->maintenance_id,
                'vehicleSnapshot' => $srf->vehicle_snapshot,
                'maintenanceHistory' => $srf->maintenance_history,
                'rfqPrefill' => $srf->rfq_prefill,
            ],
        ], 201);
    }
}
