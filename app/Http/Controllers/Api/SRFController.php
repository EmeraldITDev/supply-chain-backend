<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SRF;
use App\Services\FormattedIdGenerator;
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

        if ($user && in_array($user->role, ['employee', 'general_employee'])) {
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

        $srfs = $query->orderBy('date', 'desc')->get();

        return response()->json($srfs->map(fn ($srf) => $this->presentSrf($srf)));
    }

    /**
     * Normalises a single SRF model to the JSON shape consumed by the
     * frontend. Centralised so the new logistics columns (vehicle snapshot,
     * maintenance history, RFQ prefill) are exposed everywhere.
     */
    private function presentSrf(SRF $srf): array
    {
        return [
            'id' => $srf->srf_id,
            'formattedId' => $srf->formatted_id,
            'formatted_id' => $srf->formatted_id,
            'legacyId' => $srf->srf_id,
            'legacy_id' => $srf->srf_id,
            'title' => $srf->title,
            'serviceType' => $srf->service_type,
            'contractType' => $srf->contract_type,
            'department' => $srf->department,
            'urgency' => $srf->urgency,
            'description' => $srf->description,
            'duration' => $srf->duration,
            'estimatedCost' => (float) $srf->estimated_cost,
            'justification' => $srf->justification,
            'requester' => $srf->requester_name,
            'requesterId' => (string) $srf->requester_id,
            'date' => $srf->date ? $srf->date->format('Y-m-d') : null,
            'status' => $srf->status,
            'currentStage' => $srf->current_stage,
            'approvalHistory' => $srf->approval_history ?? [],
            'rejectionReason' => $srf->rejection_reason,
            'remarks' => $srf->remarks,
            'origin' => $srf->origin,
            'vehicleId' => $srf->vehicle_id,
            'maintenanceId' => $srf->maintenance_id,
            'vehicleSnapshot' => $srf->vehicle_snapshot,
            'maintenanceHistory' => $srf->maintenance_history,
            'rfqPrefill' => $srf->rfq_prefill,
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
        $isDepartmentEmployee = $user->role === 'employee';

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

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'serviceType' => 'required|string|max:255',
            'contractType' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'urgency' => 'required|in:Low,Medium,High,Critical',
            'description' => 'required|string',
            'duration' => 'required|string',
            'estimatedCost' => 'required|numeric|min:0',
            'justification' => 'required|string',
            'invoice' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg|max:10240', // Optional invoice upload (10MB max)
        ]);

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
            'estimated_cost' => $request->estimatedCost,
            'justification' => $request->justification,
            'requester_id' => $user->id,
            'requester_name' => $user->name,
            'department' => $department,
            'date' => $createdAt,
            'status' => 'Pending',
            'current_stage' => 'procurement',
            'approval_history' => [],
            'invoice_url' => $invoiceUrl,
            'invoice_share_url' => $invoiceShareUrl,
        ]);

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

        $payload = $this->presentSrf($srf->load(['vehicle', 'maintenance', 'requester']));

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

        return response()->json($payload);
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

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'serviceType' => 'sometimes|required|string|max:255',
            'urgency' => 'sometimes|required|in:Low,Medium,High,Critical',
            'description' => 'sometimes|required|string',
            'duration' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|required|numeric|min:0',
            'justification' => 'sometimes|required|string',
        ]);

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
        if ($request->has('estimatedCost')) $updateData['estimated_cost'] = $request->estimatedCost;
        if ($request->has('justification')) $updateData['justification'] = $request->justification;

        if ($srf->status === 'Rejected') {
            $updateData['status'] = 'Pending';
            $updateData['rejection_reason'] = null;
        }

        $srf->update($updateData);
        $srf->refresh();

        return response()->json($this->presentSrf($srf));
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
