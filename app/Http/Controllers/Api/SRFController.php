<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SRF;
use App\Models\Logistics\VehicleMaintenance;
use App\Services\FormattedIdGenerator;
use App\Services\LineItemBudgetService;
use App\Support\RequestLineItemParser;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SRFController extends Controller
{
    protected WorkflowNotificationService $workflowNotificationService;
    protected FormattedIdGenerator $formattedIdGenerator;

    public function __construct(
        WorkflowNotificationService $workflowNotificationService,
        FormattedIdGenerator $formattedIdGenerator
    )
    {
        $this->workflowNotificationService = $workflowNotificationService;
        $this->formattedIdGenerator = $formattedIdGenerator;
    }

    private function findSrfByAnyId(string $id)
    {
        return SRF::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('srf_id', $id);

            if (is_numeric($id)) {
                $query->orWhere('id', (int) $id);
            }
        })->first();
    }

    /**
     * Get all SRFs
     */
    public function index(Request $request)
    {
        $query = SRF::with('requester');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by requester (for employees to see only their own)
        $user = $request->user();

        // If user is a vendor, they typically don't need direct access to SRFs
        // Allow access but return empty array
        $isVendor = false;
        if ($user && ($user->role === 'vendor' || (method_exists($user, 'hasRole') && $user->hasRole('vendor')))) {
            $isVendor = true;
            // Vendors don't typically need SRFs - return empty array
            return response()->json([]);
        }

        if ($user && in_array($user->role, ['employee', 'general_employee', 'staff', 'regular_staff'], true)) {
            $query->where('requester_id', $user->id);
        }

        // Logistics manager/officer see SRFs they originated plus all SRFs
        // attached to the logistics department (so they can track the end-to-
        // end progress of fleet/maintenance requests they initiated).
        if ($user && in_array($user->role, ['logistics_manager', 'logistics_officer'], true)) {
            $logisticsDepartment = $user->department;
            $query->where(function ($q) use ($user, $logisticsDepartment) {
                $q->where('requester_id', $user->id)
                  ->orWhere('service_type', 'like', 'Fleet%')
                  ->orWhere('title', 'like', 'Fleet Maintenance SRF%');
                if (!empty($logisticsDepartment)) {
                    $q->orWhereRaw('LOWER(department) = ?', [strtolower((string) $logisticsDepartment)]);
                }
                $q->orWhereRaw('LOWER(department) LIKE ?', ['%logistics%']);
            });
        }

        $perPage = (int) $request->get('per_page', 50);
        $perPage = min($perPage, 200); // Cap at 200 to prevent abuse
        $includeLineItems = $request->boolean('include_line_items', true);
        if ($includeLineItems) {
            $query->with('items');
        }
        $srfs = $query->orderBy('date', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($srfs->items())->map(fn (SRF $srf) => $this->presentSrf($srf, $includeLineItems))->values(),
            'srfs' => collect($srfs->items())->map(fn (SRF $srf) => $this->presentSrf($srf, $includeLineItems))->values(),
            'pagination' => [
                'total' => $srfs->total(),
                'per_page' => $srfs->perPage(),
                'current_page' => $srfs->currentPage(),
                'last_page' => $srfs->lastPage(),
            ],
        ]);
    }

    /**
     * Normalises a single SRF model to the JSON shape consumed by the
     * frontend. Centralised so the new logistics columns (vehicle snapshot,
     * maintenance history, RFQ prefill) are exposed everywhere.
     */
    private function presentSrf(SRF $srf, bool $includeLineItems = false): array
    {
        $requesterName = $srf->requester_name
            ?: ($srf->relationLoaded('requester') && $srf->requester ? $srf->requester->name : null);

        $requesterObject = [
            'id' => (int) $srf->requester_id,
            'name' => $requesterName,
            'email' => $srf->relationLoaded('requester') && $srf->requester ? $srf->requester->email : null,
        ];

        $payload = [
            'id' => $srf->srf_id,
            'formattedId' => $srf->formatted_id,
            'formatted_id' => $srf->formatted_id,
            'legacyId' => $srf->srf_id,
            'legacy_id' => $srf->srf_id,
            'title' => $srf->title,
            'serviceType' => $srf->service_type,
            'service_type' => $srf->service_type,
            'contractType' => $srf->contract_type,
            'contract_type' => $srf->contract_type,
            'department' => $srf->department,
            'urgency' => $srf->urgency,
            'description' => $srf->description,
            'duration' => $srf->duration,
            'estimatedCost' => $srf->estimated_cost !== null ? (float) $srf->estimated_cost : null,
            'estimated_cost' => $srf->estimated_cost !== null ? (float) $srf->estimated_cost : null,
            'justification' => $srf->justification,
            // Plain name (legacy + list views)
            'requesterName' => $requesterName,
            'requester_name' => $requesterName,
            'requester' => $requesterObject,
            'requesterId' => (string) $srf->requester_id,
            'requester_id' => (string) $srf->requester_id,
            'date' => $srf->date ? $srf->date->format('Y-m-d') : null,
            // Use for "submitted at" in the UI — ISO-8601 with offset. Avoid parsing `date` (Y-m-d) as a datetime (midnight UTC shows as wrong local time).
            'createdAt' => $srf->created_at ? $srf->created_at->toIso8601String() : null,
            'created_at' => $srf->created_at ? $srf->created_at->toIso8601String() : null,
            'updatedAt' => $srf->updated_at ? $srf->updated_at->toIso8601String() : null,
            'updated_at' => $srf->updated_at ? $srf->updated_at->toIso8601String() : null,
            'status' => $srf->status,
            'currentStage' => $srf->current_stage,
            'current_stage' => $srf->current_stage,
            'approvalHistory' => $srf->approval_history ?? [],
            'approval_history' => $srf->approval_history ?? [],
            'rejectionReason' => $srf->rejection_reason,
            'rejection_reason' => $srf->rejection_reason,
            'remarks' => $srf->remarks,
            'origin' => $srf->origin,
            'vehicleId' => $srf->vehicle_id,
            'maintenanceId' => $srf->maintenance_id,
            'vehicleSnapshot' => $srf->vehicle_snapshot,
            'maintenanceHistory' => $srf->maintenance_history,
            'rfqPrefill' => $srf->rfq_prefill,
        ];

        if ($includeLineItems && $srf->relationLoaded('items')) {
            $progress = $this->buildProgressTimeline($srf);
            $currentStep = collect($progress)->firstWhere('status', 'in_progress')
                ?? collect($progress)->last(fn (array $s) => $s['status'] === 'completed');
            $payload['lineItems'] = $srf->items->map(fn ($item) => $this->presentLineItem($srf, $item, $progress, $currentStep))->values();
            $payload['line_items'] = $payload['lineItems'];
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $progress
     * @param  array<string, mixed>|null  $currentStep
     * @return array<string, mixed>
     */
    private function presentLineItem(SRF $srf, $item, array $progress, ?array $currentStep): array
    {
        return [
            'id' => $item->id,
            'srfId' => $srf->srf_id,
            'srf_id' => $srf->srf_id,
            'formattedId' => $srf->formatted_id,
            'itemName' => $item->item_name,
            'item_name' => $item->item_name,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'budget_amount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'quotedAmount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
            'quoted_amount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
            'specifications' => $item->specifications,
            'progressSummary' => [
                'currentStepKey' => $currentStep['key'] ?? null,
                'currentStepLabel' => $currentStep['label'] ?? null,
                'srfStatus' => $srf->status,
                'srfStage' => $srf->current_stage,
            ],
        ];
    }

    /**
     * Create new SRF
     *
     * Logistics Manager and Logistics Officer can author SRFs directly for
     * fleet/maintenance work. Department employees can still create SRFs if
     * they are flagged as the designated requisition creator.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $logisticsAuthors = ['logistics_manager', 'logistics_officer'];
        $isLogisticsAuthor = in_array($user->role, $logisticsAuthors, true);
        $isDepartmentEmployee = in_array($user->role, ['employee', 'staff', 'regular_staff'], true);

        if (!$isLogisticsAuthor && !$isDepartmentEmployee) {
            return response()->json([
                'success' => false,
                'error' => 'Only designated staff or logistics managers can create Service Request Forms.',
            ], 403);
        }

        if ($isDepartmentEmployee && !$user->designated_requisition_creator) {
            return response()->json([
                'success' => false,
                'error' => 'You are not authorised to create requisition requests for your department.',
            ], 403);
        }

        RequestLineItemParser::mergeIntoRequest($request);
        $lineItems = RequestLineItemParser::resolve($request);

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), array_merge([
            'title' => 'required|string|max:255',
            'serviceType' => 'required|string|max:255',
            'contractType' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'urgency' => 'required|in:Low,Medium,High,Critical',
            'description' => 'required|string',
            'duration' => 'required|string|max:255',
            'estimatedCost' => 'nullable|numeric|min:0',
            'estimated_cost' => 'nullable|numeric|min:0',
            'justification' => 'required|string',
            'invoice' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg|max:10240', // Optional invoice upload (10MB max)
        ], RequestLineItemParser::validationRules()));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $user = $request->user();

        // Handle invoice upload if provided
        $invoiceUrl = null;
        $invoiceShareUrl = null;
        $srfId = SRF::generateSRFId();
        $createdAt = now();

        $contractType = $request->contractType ?? 'EMERALD';
        $department = $request->department ?? $user->department ?? null;

        $formattedId = $this->formattedIdGenerator->generate('SRF', [
            'contract_type' => $contractType,
            'department' => $department,
            'category' => $request->serviceType,
            'created_at' => $createdAt,
        ]);

        if ($request->hasFile('invoice')) {
            $invoiceFile = $request->file('invoice');
            $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
            $invoiceFileName = "invoice_{$srfId}_" . time() . "." . $invoiceFile->getClientOriginalExtension();
            $invoicePath = "srfs/" . date('Y/m') . "/{$srfId}/{$invoiceFileName}";

            // Ensure directory structure exists (for S3, this is just the path)
            $directory = dirname($invoicePath);
            if ($disk !== 's3' && !\Storage::disk($disk)->exists($directory)) {
                \Storage::disk($disk)->makeDirectory($directory, 0755, true);
            }

            $invoiceFile->storeAs($directory, basename($invoicePath), $disk);

            // Get URL (temporary signed URL for S3, public URL for local)
            if ($disk === 's3') {
                try {
                    $invoiceUrl = \Storage::disk($disk)->temporaryUrl($invoicePath, now()->addDays(7));
                    $invoiceShareUrl = $invoiceUrl;
                } catch (\Exception $e) {
                    \Log::warning('S3 temporary URL generation failed, using regular URL', [
                        'error' => $e->getMessage(),
                        'path' => $invoicePath
                    ]);
                    $invoiceUrl = \Storage::disk($disk)->url($invoicePath);
                    $invoiceShareUrl = $invoiceUrl;
                }
            } else {
                $invoiceUrl = \Storage::disk($disk)->url($invoicePath);
                if (!filter_var($invoiceUrl, FILTER_VALIDATE_URL)) {
                    $baseUrl = config('app.url');
                    $invoiceUrl = rtrim($baseUrl, '/') . '/' . ltrim($invoiceUrl, '/');
                }
                $invoiceShareUrl = $invoiceUrl;
            }
        }

        $srf = SRF::create([
            'srf_id' => $srfId,
            'formatted_id' => $formattedId,
            'title' => $request->title,
            'service_type' => $request->serviceType,
            'contract_type' => $contractType,
            'urgency' => $request->urgency,
            'description' => $request->description,
            'duration' => $request->duration,
            'estimated_cost' => $request->input('estimatedCost', $request->input('estimated_cost')),
            'justification' => $request->justification,
            'requester_id' => $user->id,
            'requester_name' => $user->name,
            'department' => $department,
            'date' => $createdAt,
            'status' => 'Pending',
            'current_stage' => 'supply_chain_director_review',
            'approval_history' => [[
                'stage' => 'submitted',
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'at' => $createdAt->toIso8601String(),
                'note' => 'SRF submitted; awaiting Supply Chain Director review.',
            ]],
            'remarks' => 'pending_supply_chain_director_review',
            'invoice_url' => $invoiceUrl,
            'invoice_share_url' => $invoiceShareUrl,
        ]);

        if ($lineItems !== []) {
            app(LineItemBudgetService::class)->syncSrfItems($srf, $lineItems);
        }

        try {
            $srf->loadMissing('requester');
            $this->workflowNotificationService->notifySRFSubmitted($srf);
        } catch (\Exception $e) {
            \Log::error('Failed to send SRF notification', [
                'srf_id' => $srf->srf_id ?? $srf->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json($this->presentSrf($srf), 201);
    }

    /**
     * Detail view for a single SRF (includes vehicle + maintenance snapshot
     * and any linked RFQs/quotations). Used by logistics manager dashboards
     * to drill in from the SRF list.
     */
    public function show(Request $request, $id)
    {
        $srf = $this->findSrfByAnyId((string) $id);
        if (!$srf) {
            return response()->json([
                'success' => false,
                'error' => 'SRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $srf->load(['vehicle', 'maintenance', 'requester', 'items']);
        $payload = $this->presentSrf($srf);
        $payload['items'] = $srf->items->map(fn ($item) => [
            'id' => $item->id,
            'itemName' => $item->item_name,
            'item_name' => $item->item_name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'budget_amount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'quotedAmount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
            'quoted_amount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
        ])->values();
        $payload['line_items'] = $payload['items'];
        $payload['profitAndLoss'] = app(LineItemBudgetService::class)->srfProfitAndLoss($srf);

        // Best-effort lookup of RFQs / quotations that were spawned from
        // this SRF (the RFQ workflow currently only links to MRFs, so we
        // fall back to title/description matching for SRF-originated RFQs).
        $rfqs = collect();
        try {
            $rfqs = \App\Models\RFQ::with(['vendors', 'quotations.vendor'])
                ->where(function ($q) use ($srf) {
                    $q->where('title', 'like', '%' . $srf->title . '%')
                      ->orWhere('description', 'like', '%' . $srf->srf_id . '%');
                })
                ->get();
        } catch (\Throwable $e) {
            \Log::warning('SRF show: failed to load related RFQs', [
                'srf_id' => $srf->srf_id,
                'error' => $e->getMessage(),
            ]);
        }

        $payload['rfqs'] = $rfqs->map(function ($rfq) {
            return [
                'id' => $rfq->rfq_id,
                'title' => $rfq->title,
                'status' => $rfq->status,
                'workflowState' => $rfq->workflow_state,
                'deadline' => $rfq->deadline ? $rfq->deadline->toIso8601String() : null,
                'vendors' => $rfq->vendors->map(fn ($v) => [
                    'id' => $v->vendor_id,
                    'name' => $v->name,
                ])->values(),
                'quotations' => $rfq->quotations->map(fn ($q) => [
                    'id' => $q->quotation_id,
                    'vendor' => $q->vendor ? [
                        'id' => $q->vendor->vendor_id,
                        'name' => $q->vendor->name,
                    ] : null,
                    'totalAmount' => $q->total_amount !== null ? (float) $q->total_amount : null,
                    'currency' => $q->currency ?? 'NGN',
                    'status' => $q->status,
                    'submittedAt' => $q->submitted_at ? $q->submitted_at->toIso8601String() : null,
                ])->values(),
            ];
        })->values();

        $payload['progress'] = $this->buildProgressTimeline($srf);

        $liveMaintenance = collect();
        if ($srf->vehicle_id) {
            $liveMaintenance = VehicleMaintenance::where('vehicle_id', $srf->vehicle_id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'maintenance_type' => $m->maintenance_type,
                        'maintenanceType' => $m->maintenance_type,
                        'description' => $m->description,
                        'interval_months' => $m->interval_months,
                        'performed_at' => optional($m->performed_at)->toIso8601String(),
                        'next_due_at' => optional($m->next_due_at)->toIso8601String(),
                        'cost' => $m->cost !== null ? (float) $m->cost : null,
                        'status' => $m->status,
                        'metadata' => $m->metadata,
                    ];
                })->values();
        }
        $payload['liveMaintenanceRecords'] = $liveMaintenance;
        $payload['live_maintenance_records'] = $liveMaintenance;

        return response()->json($payload);
    }

    public function progressTracker(Request $request, $id)
    {
        $srf = $this->findSrfByAnyId((string) $id);
        if (! $srf) {
            return response()->json(['success' => false, 'error' => 'SRF not found', 'code' => 'NOT_FOUND'], 404);
        }

        $timeline = $this->buildProgressTimeline($srf);

        return response()->json([
            'success' => true,
            'srfId' => $srf->srf_id,
            'currentStage' => $srf->current_stage,
            'status' => $srf->status,
            'progress' => $timeline,
            'steps' => $timeline,
        ]);
    }

    public function showLineItem(Request $request, $id, $itemId)
    {
        $srf = $this->findSrfByAnyId((string) $id);
        if (! $srf) {
            return response()->json(['success' => false, 'error' => 'SRF not found', 'code' => 'NOT_FOUND'], 404);
        }

        $srf->load('items');
        $item = $srf->items->firstWhere('id', (int) $itemId);
        if (! $item) {
            return response()->json(['success' => false, 'error' => 'Line item not found', 'code' => 'NOT_FOUND'], 404);
        }

        $progress = $this->buildProgressTimeline($srf);
        $currentStep = collect($progress)->firstWhere('status', 'in_progress')
            ?? collect($progress)->last(fn (array $s) => $s['status'] === 'completed');

        return response()->json([
            'success' => true,
            'srf' => $this->presentSrf($srf),
            'lineItem' => $this->presentLineItem($srf, $item, $progress, $currentStep),
            'progress' => $progress,
            'steps' => $progress,
        ]);
    }

    public function lineItemProfitAndLoss(Request $request, $id)
    {
        $srf = $this->findSrfByAnyId((string) $id);
        if (!$srf) {
            return response()->json(['success' => false, 'error' => 'SRF not found', 'code' => 'NOT_FOUND'], 404);
        }

        $srf->load('items');

        $pnl = app(LineItemBudgetService::class)->srfProfitAndLoss($srf);

        return response()->json([
            'success' => true,
            'srfId' => $srf->srf_id,
            'items' => $pnl['items'],
            'summary' => $pnl['summary'],
            'data' => $pnl,
        ]);
    }

    /**
     * Build a simple step-by-step view of the SRF workflow used by the
     * logistics manager dashboard so they can see where the request is in
     * the procurement pipeline.
     */
    private function buildProgressTimeline(SRF $srf): array
    {
        $stage = strtolower((string) $srf->current_stage);
        $isRejected = strtolower((string) $srf->status) === 'rejected';

        $stages = [
            ['key' => 'logistics_initiated', 'label' => 'SRF initiated by Logistics'],
            ['key' => 'supply_chain_director_review', 'label' => 'Supply Chain Director review'],
            ['key' => 'procurement', 'label' => 'Procurement sourcing & RFQs'],
            ['key' => 'vendor_selected', 'label' => 'Vendor quotation selected'],
            ['key' => 'po_generated', 'label' => 'Purchase order issued'],
            ['key' => 'closed', 'label' => 'Closed'],
        ];

        $reached = false;
        $timeline = [];
        foreach ($stages as $step) {
            $isCurrent = $stage === $step['key'];
            $status = $reached ? 'pending' : ($isCurrent ? 'in_progress' : 'completed');
            if (!$reached && $isCurrent) {
                $reached = true;
            }
            $timeline[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'status' => $isRejected && $isCurrent ? 'rejected' : $status,
            ];
        }

        return $timeline;
    }

    /**
     * Update SRF
     */
    public function update(Request $request, $id)
    {
        $srf = $this->findSrfByAnyId((string) $id);

        if (!$srf) {
            return response()->json([
                'success' => false,
                'error' => 'SRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if (!in_array($srf->status, ['Pending', 'Rejected'])) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot update SRF in current status',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        RequestLineItemParser::mergeIntoRequest($request);
        $lineItems = RequestLineItemParser::resolve($request);

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), array_merge([
            'title' => 'sometimes|required|string|max:255',
            'serviceType' => 'sometimes|required|string|max:255',
            'urgency' => 'sometimes|required|in:Low,Medium,High,Critical',
            'description' => 'sometimes|required|string',
            'duration' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|nullable|numeric|min:0',
            'estimated_cost' => 'sometimes|nullable|numeric|min:0',
            'justification' => 'sometimes|required|string',
        ], RequestLineItemParser::validationRules()));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $updateData = [];
        if ($request->has('title')) $updateData['title'] = $request->title;
        if ($request->has('serviceType')) $updateData['service_type'] = $request->serviceType;
        if ($request->has('urgency')) $updateData['urgency'] = $request->urgency;
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('duration')) $updateData['duration'] = $request->duration;
        if ($request->has('estimatedCost')) {
            $updateData['estimated_cost'] = $request->estimatedCost;
        } elseif ($request->has('estimated_cost')) {
            $updateData['estimated_cost'] = $request->estimated_cost;
        }
        if ($request->has('justification')) $updateData['justification'] = $request->justification;

        if ($srf->status === 'Rejected') {
            $updateData['status'] = 'Pending';
            $updateData['rejection_reason'] = null;
        }

        $srf->update($updateData);

        if ($request->has('items') || $request->has('line_items')) {
            app(LineItemBudgetService::class)->syncSrfItems($srf, $lineItems);
        }

        $srf->refresh();
        $srf->load('items');

        $payload = $this->presentSrf($srf);
        $payload['items'] = $srf->items->map(fn ($item) => [
            'id' => $item->id,
            'itemName' => $item->item_name,
            'item_name' => $item->item_name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'budget_amount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
        ])->values();
        $payload['line_items'] = $payload['items'];

        return response()->json($payload);
    }

    /**
     * Remove an SRF that has not progressed beyond early procurement stages.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $srf = $this->findSrfByAnyId((string) $id);
        if (!$srf) {
            return response()->json([
                'success' => false,
                'error' => 'SRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $isAdmin = $user->role === 'admin';
        $statusLower = strtolower(trim((string) $srf->status));
        $stageLower = strtolower(trim((string) $srf->current_stage));

        if (!$isAdmin) {
            if (!in_array($statusLower, ['pending', 'rejected'], true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Only Pending or Rejected SRFs can be deleted.',
                    'code' => 'INVALID_STATUS',
                ], 422);
            }

            $deletableStages = ['supply_chain_director_review', 'procurement', 'rejected'];
            if (!in_array($stageLower, $deletableStages, true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'This SRF cannot be deleted at its current workflow stage.',
                    'code' => 'INVALID_STAGE',
                    'currentStage' => $srf->current_stage,
                ], 422);
            }

            $isRequester = (int) $srf->requester_id === (int) $user->id;
            $logisticsRoles = ['logistics_manager', 'logistics_officer'];
            $scdRoles = ['supply_chain_director', 'supply_chain'];
            $procurementRoles = ['procurement_manager', 'procurement'];

            $allowed = false;
            if ($isRequester) {
                $allowed = true;
            } elseif (in_array($user->role, $logisticsRoles, true)) {
                $allowed = true;
            } elseif (in_array($user->role, $scdRoles, true)
                && $stageLower === 'supply_chain_director_review'
                && $statusLower === 'pending') {
                $allowed = true;
            } elseif (in_array($user->role, $procurementRoles, true)
                && $stageLower === 'procurement'
                && $statusLower === 'pending') {
                $allowed = true;
            }

            if (!$allowed) {
                return response()->json([
                    'success' => false,
                    'error' => 'You are not allowed to delete this SRF.',
                    'code' => 'FORBIDDEN',
                ], 403);
            }
        }

        try {
            $srf->delete();

            return response()->json([
                'success' => true,
                'message' => 'SRF deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('SRF deletion failed', [
                'srf_id' => $srf->srf_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete SRF.',
                'code' => 'DELETE_FAILED',
            ], 500);
        }
    }

    /**
     * Supply Chain Director approves a fleet-initiated SRF; transitions it
     * from supply_chain_director_review → procurement so the Procurement
     * Manager can take over (issuing RFQs with the pre-filled context).
     */
    public function supplyChainDirectorApprove(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Only the Supply Chain Director can approve at this stage.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $srf = $this->findSrfByAnyId((string) $id);
        if (!$srf) {
            return response()->json([
                'success' => false,
                'error' => 'SRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if ($srf->current_stage !== 'supply_chain_director_review') {
            return response()->json([
                'success' => false,
                'error' => 'SRF is not currently awaiting Supply Chain Director approval.',
                'code' => 'INVALID_STAGE',
                'currentStage' => $srf->current_stage,
            ], 422);
        }

        $remarks = (string) $request->input('remarks', '');
        $history = $srf->approval_history ?? [];
        $history[] = [
            'stage' => 'supply_chain_director_approved',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'at' => now()->toIso8601String(),
            'note' => $remarks ?: 'Approved by Supply Chain Director.',
        ];

        $srf->update([
            'current_stage' => 'procurement',
            'status' => 'Pending',
            'remarks' => 'pending_procurement',
            'approval_history' => $history,
        ]);

        try {
            $this->workflowNotificationService->notifySRFSubmitted($srf->fresh('requester'));
        } catch (\Throwable $e) {
            \Log::warning('Failed to notify procurement of SRF approval', [
                'srf_id' => $srf->srf_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($this->presentSrf($srf->fresh()));
    }

    /**
     * Supply Chain Director rejects a fleet-initiated SRF and sends it back
     * to the originator (logistics).
     */
    public function supplyChainDirectorReject(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Only the Supply Chain Director can reject at this stage.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'A rejection reason is required.',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $srf = $this->findSrfByAnyId((string) $id);
        if (!$srf) {
            return response()->json([
                'success' => false,
                'error' => 'SRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $history = $srf->approval_history ?? [];
        $history[] = [
            'stage' => 'supply_chain_director_rejected',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'at' => now()->toIso8601String(),
            'note' => $request->input('reason'),
        ];

        $srf->update([
            'status' => 'Rejected',
            'current_stage' => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'remarks' => 'rejected_by_supply_chain_director',
            'approval_history' => $history,
        ]);

        return response()->json($this->presentSrf($srf->fresh()));
    }
}
