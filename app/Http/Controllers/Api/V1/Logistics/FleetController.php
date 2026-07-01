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
use App\Services\FormattedIdGenerator;
use App\Services\Logistics\AuditLogger;
use App\Support\FleetVehicleLookup;
use App\Services\WorkflowNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FleetController extends ApiController
{
    public function __construct(
        private AuditLogger $auditLogger,
        private WorkflowNotificationService $workflowNotificationService,
        private FormattedIdGenerator $formattedIdGenerator
    ) {
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
        $query = Vehicle::query()
            ->select([
                'id', 'plate_number', 'name', 'type', 'make', 'model', 'year', 'color',
                'ownership', 'vendor_id', 'status', 'approval_status', 'passenger_capacity',
                'cargo_capacity', 'fuel_type', 'created_at', 'updated_at',
            ])
            ->with(['vendor:id,vendor_id,name'])
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

        if ($request->filled('ownership')) {
            $query->where('ownership', $request->ownership);
        }

        if ($request->filled('search') || $request->filled('q')) {
            $term = '%' . trim((string) ($request->input('search') ?: $request->input('q'))) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('plate_number', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('make', 'like', $term)
                    ->orWhere('model', 'like', $term);
            });
        }

        [$sortBy, $sortDirection] = $this->resolveSort(
            $request,
            ['created_at', 'updated_at', 'plate_number', 'status', 'name'],
            'created_at',
            'desc',
        );

        $perPage = $this->resolvePerPage($request);
        $paginator = $query->orderBy($sortBy, $sortDirection)->paginate($perPage);

        return $this->success([
            'vehicles' => $paginator->items(),
            'pagination' => $this->paginationPayload($paginator),
        ]);
    }

    public function show(string|int $id)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($id);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $vehicle->load([
            'vendor',
            'maintenances' => function ($q) {
                $q->orderByDesc('next_due_at')->orderByDesc('id');
            },
            'maintenances.documents',
            'maintenances.performer',
            'documents',
        ]);
        $vehicle->loadCount([
            'documents',
            'documents as active_documents_count' => function ($q) {
                $q->where('is_active', true);
            },
            'maintenances',
        ]);

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

    public function update(UpdateVehicleRequest $request, string|int $id)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($id);

        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $vehicle->fill($request->validated())->save();

        $this->auditLogger->log('vehicle_updated', $request->user(), 'vehicle', (string) $vehicle->id, $vehicle->toArray(), $request);

        return $this->success([
            'vehicle' => $vehicle,
        ]);
    }

    public function storeMaintenance(StoreMaintenanceRequest $request, string|int $id)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($id);

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

    public function listMaintenance(string|int $vehicleId)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($vehicleId);
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

    public function updateMaintenance(UpdateVehicleMaintenanceRequest $request, string|int $vehicleId, int $scheduleId)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($vehicleId);
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
        $nowStart = Carbon::now()->startOfDay();
        $until = Carbon::now()->addDays($days)->endOfDay();

        // Match status case-insensitively (migration default was lowercase "completed";
        // some rows may use mixed case). Include overdue rows so the dashboard is not
        // empty when work was missed but still relevant.
        $scheduledUpper = VehicleMaintenance::STATUS_SCHEDULED;
        $overdueUpper = VehicleMaintenance::STATUS_OVERDUE;

        $records = VehicleMaintenance::query()
            ->with('vehicle')
            ->whereNotNull('next_due_at')
            ->where(function ($q) use ($nowStart, $until, $scheduledUpper, $overdueUpper) {
                $q->where(function ($q2) use ($nowStart, $until, $scheduledUpper) {
                    $q2->whereRaw('UPPER(TRIM(COALESCE(status, \'\'))) = ?', [$scheduledUpper])
                        ->whereBetween('next_due_at', [$nowStart, $until]);
                })->orWhere(function ($q3) use ($nowStart, $overdueUpper) {
                    $q3->whereRaw('UPPER(TRIM(COALESCE(status, \'\'))) = ?', [$overdueUpper])
                        ->where('next_due_at', '<', $nowStart);
                })->orWhere(function ($q4) use ($nowStart, $scheduledUpper) {
                    // Still SCHEDULED in DB but past due (overdue job not run yet)
                    $q4->whereRaw('UPPER(TRIM(COALESCE(status, \'\'))) = ?', [$scheduledUpper])
                        ->where('next_due_at', '<', $nowStart);
                });
            })
            ->orderBy('next_due_at')
            ->get();

        return $this->success([
            'maintenance' => $records,
            'days' => $days,
        ]);
    }

    public function updateVehicleStatus(UpdateVehicleStatusRequest $request, string|int $id)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($id);
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

    public function destroy(string|int $id, Request $request)
    {
        $vehicle = FleetVehicleLookup::byRouteKey($id);

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
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function initiateSrf(Request $request, string|int $id)
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Authentication required.', 'UNAUTHENTICATED', 401);
        }

        $vehicle = FleetVehicleLookup::byRouteKey($id);
        if (!$vehicle) {
            return $this->error('Vehicle not found', 'NOT_FOUND', 404);
        }

        $vehicle->load(['maintenances', 'vendor']);

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

        $initialStage = 'supply_chain_director_review';
        $contractType = 'EMERALD';
        $department = $user->department ?? 'Logistics';
        $createdAt = now();

        try {
            $formattedId = $this->formattedIdGenerator->generate('SRF', [
                'contract_type' => $contractType,
                'department' => $department,
                'category' => $maintenanceType,
                'created_at' => $createdAt,
            ]);

            $srf = SRF::create([
                'srf_id' => SRF::generateSRFId(),
                'formatted_id' => $formattedId,
                'title' => 'Fleet Maintenance SRF - ' . ($vehicle->plate_number ?? $vehicle->vehicle_code),
                'service_type' => $maintenanceType,
                'contract_type' => $contractType,
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
                'duration' => $request->input('duration')
                    ?: ($latestMaintenance?->interval_months
                        ? sprintf('Recurring every %d month(s) — confirm turnaround with workshop', (int) $latestMaintenance->interval_months)
                        : 'Single service — confirm lead time with selected vendor'),
                'estimated_cost' => (float) $request->input('estimated_cost', $latestMaintenance?->cost ?? 0),
                'justification' => 'Auto-initiated from Fleet Maintenance dashboard.',
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'department' => $department,
                'date' => $createdAt,
                'status' => 'Pending',
                'current_stage' => $initialStage,
                'approval_history' => [[
                    'stage' => 'logistics_initiated',
                    'actor_id' => $user->id,
                    'actor_name' => $user->name,
                    'at' => $createdAt->toIso8601String(),
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
        } catch (\Throwable $e) {
            \Log::error('Fleet initiate SRF: database error', [
                'vehicle_id' => $vehicle->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(
                'Could not create the SRF. Ensure database migrations are applied (fleet columns on s_r_f_s).',
                'SRF_CREATE_FAILED',
                422
            );
        }

        try {
            $srf->loadMissing('requester');
            $this->workflowNotificationService->notifySRFSubmitted($srf);
        } catch (\Throwable) {
            // Do not fail SRF creation when notification fails.
        }

        return $this->success([
            'message' => 'SRF created and queued for Supply Chain Director review.',
            'srf_id' => $srf->srf_id,
            'database_id' => $srf->id,
            'formatted_id' => $srf->formatted_id,
            'srf' => [
                'id' => $srf->srf_id,
                'databaseId' => $srf->id,
                'formattedId' => $srf->formatted_id,
                'title' => $srf->title,
                'serviceType' => $srf->service_type,
                'department' => $srf->department,
                'status' => $srf->status,
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
