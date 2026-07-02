<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\RFQ;
use App\Models\MRF;
use App\Models\Vendor;
use App\Services\FormattedIdGenerator;
use App\Services\PaymentScheduleService;
use App\Services\RfqAttachmentService;
use App\Services\WorkflowNotificationService;
use App\Support\PaymentMilestoneRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RFQController extends Controller
{
    use ResolvesPaginatedLists;

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

    private function findRfqByAnyId(string $id)
    {
        return RFQ::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('rfq_id', $id);

            if (is_numeric($id)) {
                $query->orWhere('id', (int) $id);
            }
        })->first();
    }

    /**
     * Get all RFQs
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = RFQ::with([
            'mrf:id,mrf_id,formatted_id,title,estimated_cost',
            'creator:id,name,email',
            'vendors:id,vendor_id,name',
        ])->withCount(['quotations', 'vendors']);

        // If user is a vendor, only show RFQs assigned to them
        $isVendor = false;
        $vendor = null;
        if ($user && ($user->scmRole() === 'vendor' || (method_exists($user, 'hasRole') && $user->hasRole('vendor')))) {
            $isVendor = true;
            // Get vendor from user
            if ($user->vendor_id) {
                $vendor = Vendor::find($user->vendor_id);
            }
            if (!$vendor) {
                $vendor = Vendor::where('email', $user->email)->first();
            }
            if ($vendor) {
                // Filter RFQs where this vendor is in the vendors relationship
                $query->whereHas('vendors', function($q) use ($vendor) {
                    $q->where('vendors.id', $vendor->id);
                });
            } else {
                // Vendor user but no vendor record - return empty
                return response()->json([]);
            }
        }

        // Filter by status
        if ($request->filled('status') && strtolower((string) $request->status) !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('deadline', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('deadline', '<=', $request->date_to);
        }

        // Search indexed identifier columns (rfq_id, formatted_id, linked MRF ids).
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            if ($search !== '') {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('rfq_id', 'like', $like)
                        ->orWhere('formatted_id', 'like', $like)
                        ->orWhereHas('mrf', function ($mrf) use ($like) {
                            $mrf->where('mrf_id', 'like', $like)
                                ->orWhere('formatted_id', 'like', $like);
                        });
                });
            }
        }

        [$sortBy, $sortOrder] = $this->resolveSort(
            $request,
            ['created_at', 'updated_at', 'deadline', 'estimated_cost', 'status', 'title'],
            'created_at',
            'desc',
        );

        $perPage = $this->resolvePerPage($request, 25, 100);
        $paginator = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        $items = collect($paginator->items())->map(function ($rfq) {
            $mrfEstimatedCost = $rfq->mrf ? (float) $rfq->mrf->estimated_cost : null;
            $rfqEstimatedCost = $rfq->estimated_cost !== null ? (float) $rfq->estimated_cost : null;
            $estimatedBudget = ($mrfEstimatedCost !== null && $mrfEstimatedCost > 0)
                ? $mrfEstimatedCost
                : $rfqEstimatedCost;

            return [
                'id' => $rfq->rfq_id,
                'formattedId' => $rfq->formatted_id,
                'formatted_id' => $rfq->formatted_id,
                'legacyId' => $rfq->rfq_id,
                'legacy_id' => $rfq->rfq_id,
                'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
                'mrfFormattedId' => $rfq->mrf?->formatted_id,
                'mrf_formatted_id' => $rfq->mrf?->formatted_id,
                'mrfTitle' => $rfq->mrf_title ?? ($rfq->mrf ? $rfq->mrf->title : null),
                'title' => $rfq->title,
                'category' => $rfq->category,
                'description' => $rfq->description,
                'quantity' => $rfq->quantity,
                'estimatedCost' => $rfq->estimated_cost !== null ? (float) $rfq->estimated_cost : null,
                'estimated_budget' => $estimatedBudget,
                'estimatedBudget' => $estimatedBudget,
                ...$rfq->extendedDetailApiFields(),
                'paymentSchedule' => null,
                'payment_schedule' => null,
                'supportingDocuments' => [],
                'supporting_documents' => [],
                'deadline' => $rfq->deadline->format('Y-m-d'),
                'status' => $rfq->status,
                'workflowState' => $rfq->workflow_state,
                'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
                'vendorsCount' => (int) ($rfq->vendors_count ?? $rfq->vendors->count()),
                'vendors_count' => (int) ($rfq->vendors_count ?? $rfq->vendors->count()),
                'quotationsCount' => (int) ($rfq->quotations_count ?? 0),
                'quotations_count' => (int) ($rfq->quotations_count ?? 0),
                'createdAt' => $rfq->created_at->toIso8601String(),
            ];
        })->values()->all();

        return response()->json($this->paginatedJsonResponse($paginator, $items));
    }

    /**
     * Get single RFQ
     */
    public function show(Request $request, $id)
    {
        $rfq = RFQ::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('rfq_id', $id);

            if (is_numeric((string) $id)) {
                $query->orWhere('id', (int) $id);
            }
        })
            ->with(['mrf.paymentSchedule.milestones', 'creator', 'vendors', 'items'])
            ->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $mrfEstimatedCost = $rfq->mrf ? (float) $rfq->mrf->estimated_cost : null;
        $rfqEstimatedCost = $rfq->estimated_cost !== null ? (float) $rfq->estimated_cost : null;
        $estimatedBudget = ($mrfEstimatedCost !== null && $mrfEstimatedCost > 0)
            ? $mrfEstimatedCost
            : $rfqEstimatedCost;

        return response()->json([
            'id' => $rfq->rfq_id,
            'formattedId' => $rfq->formatted_id,
            'formatted_id' => $rfq->formatted_id,
            'legacyId' => $rfq->rfq_id,
            'legacy_id' => $rfq->rfq_id,
            'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
            'mrfFormattedId' => $rfq->mrf?->formatted_id,
            'mrf_formatted_id' => $rfq->mrf?->formatted_id,
            'mrfTitle' => $rfq->mrf_title ?? ($rfq->mrf ? $rfq->mrf->title : null),
            'title' => $rfq->title,
            'category' => $rfq->category,
            'description' => $rfq->description,
            'quantity' => $rfq->quantity,
            'estimatedCost' => $rfq->estimated_cost !== null ? (float) $rfq->estimated_cost : null,
            'estimated_budget' => $estimatedBudget,
            'estimatedBudget' => $estimatedBudget,
            ...$rfq->extendedDetailApiFields(),
            'paymentSchedule' => $this->paymentSchedulePayload($rfq->mrf),
            'payment_schedule' => $this->paymentSchedulePayload($rfq->mrf),
            'payment_milestones' => $rfq->mrf
                ? app(PaymentScheduleService::class)->paymentMilestonesForMrf($rfq->mrf)
                : [],
            'paymentMilestones' => $rfq->mrf
                ? app(PaymentScheduleService::class)->paymentMilestonesForMrf($rfq->mrf)
                : [],
            'supportingDocuments' => $this->supportingDocumentsPayload($rfq),
            'supporting_documents' => $this->supportingDocumentsPayload($rfq),
            'deadline' => $rfq->deadline?->format('Y-m-d'),
            'status' => $rfq->status,
            'workflowState' => $rfq->workflow_state,
            'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
            'items' => $rfq->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'specifications' => $item->specifications,
                ];
            }),
            'createdAt' => $rfq->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Create new RFQ
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mrfId' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $exists = MRF::where(function ($q) use ($value) {
                        $q->where('mrf_id', $value)->orWhere('formatted_id', $value);
                        if (is_numeric((string) $value)) {
                            $q->orWhere('id', (int) $value);
                        }
                    })->exists();

                    if (!$exists) {
                        $fail('The selected mrf id is invalid.');
                    }
                }
            ],
            'title' => 'required|string',
            'category' => 'nullable|string|max:255',
            'description' => 'required|string',
            'quantity' => 'required|string',
            'estimatedCost' => 'nullable|numeric|min:0',
            'deadline' => 'required|date',
            'vendorIds' => 'required|array|min:1',
            'vendorIds.*' => 'required|string|exists:vendors,vendor_id',
            'paymentTerms' => 'nullable|string',
            'payment_terms' => 'nullable|string',
            'deliveryTerms' => 'nullable|string',
            'delivery_terms' => 'nullable|string',
            'technicalRequirements' => 'nullable|string',
            'technical_requirements' => 'nullable|string',
            'additionalNotes' => 'nullable|string',
            'additional_notes' => 'nullable|string',
            'termsAndConditions' => 'nullable|string',
            'terms_and_conditions' => 'nullable|string',
            'notes' => 'nullable|string',
            'supportingDocuments' => 'nullable|array',
            'supportingDocuments.*' => 'nullable|string|url', // URLs to supporting documents
        ], PaymentMilestoneRequest::validationRules());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        PaymentMilestoneRequest::mergeIntoRequest($request);
        if (PaymentMilestoneRequest::provided($request)) {
            try {
                PaymentMilestoneRequest::validatePercentages(PaymentMilestoneRequest::resolve($request));
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        $user = $request->user();

        // Get MRF if provided
        $mrf = null;
        $mrfTitle = null;
        $mrfCategory = null;
        if ($request->mrfId) {
            $mrf = MRF::where(function ($query) use ($request) {
                $query->where('formatted_id', $request->mrfId)
                    ->orWhere('mrf_id', $request->mrfId);

                if (is_numeric((string) $request->mrfId)) {
                    $query->orWhere('id', (int) $request->mrfId);
                }
            })
                ->first();
            $mrfTitle = $mrf ? $mrf->title : null;
            $mrfCategory = $mrf ? $mrf->category : null;
        }

        // Use category from request, or fallback to MRF category
        $category = $request->category ?? $mrfCategory;

        // Prepare supporting documents array
        $supportingDocuments = $request->supportingDocuments ?? [];

        // If MRF has supporting documents (e.g., PFI), include them
        if ($mrf && $mrf->pfi_url) {
            // Ensure PFI is included in supporting documents if not already present
            $pfiExists = false;
            foreach ($supportingDocuments as $doc) {
                if (is_array($doc) && isset($doc['url']) && $doc['url'] === $mrf->pfi_url) {
                    $pfiExists = true;
                    break;
                } elseif (is_string($doc) && $doc === $mrf->pfi_url) {
                    $pfiExists = true;
                    break;
                }
            }

            if (!$pfiExists) {
                $supportingDocuments[] = [
                    'url' => $mrf->pfi_url,
                    'shareUrl' => $mrf->pfi_share_url,
                    'type' => 'PFI',
                    'name' => 'Proforma Invoice'
                ];
            }
        }

        // Handle estimated_cost: cast to float and use MRF's value as fallback if provided value is 0 or empty
        $estimatedCost = $request->has('estimatedCost')
            ? ($request->estimatedCost !== null ? (float) $request->estimatedCost : null)
            : ($mrf?->estimated_cost !== null ? (float) $mrf->estimated_cost : null);

        // Generate meaningful RFQ title if not provided or generic
        $rfqTitle = $request->title;
        if (empty($rfqTitle) || in_array(strtolower(trim($rfqTitle)), ['rfq', 'request', 'quotation request', '']))
        {
            // Generate title from MRF details: "{Product/Service} – {Contract Type} RFQ"
            $productName = $category ?? $mrfTitle ?? 'Supply';
            $contractType = $mrf && $mrf->contract_type ? ucfirst($mrf->contract_type) : null;

            if ($contractType) {
                $rfqTitle = "{$productName} – {$contractType} RFQ";
            } else {
                $rfqTitle = "{$productName} RFQ";
            }
        }

        $createdAt = now();

        $formattedId = $this->formattedIdGenerator->generate('RFQ', [
            'contract_type' => $mrf?->contract_type,
            'department' => $mrf?->department ?? $mrf?->requester?->department ?? null,
            'category' => $category,
            'created_at' => $createdAt,
        ]);

        $rfq = RFQ::create([
            'rfq_id' => RFQ::generateRFQId(),
            'formatted_id' => $formattedId,
            'mrf_id' => $mrf ? $mrf->id : null,
            'mrf_title' => $mrfTitle,
            'title' => $rfqTitle,
            'category' => $category,
            'description' => $request->description,
            'quantity' => $request->quantity,
            'estimated_cost' => $estimatedCost,
            'deadline' => $request->deadline,
            'payment_terms' => $this->resolvePaymentTermsForMrf($mrf, $request->paymentTerms ?? $request->payment_terms),
            'delivery_terms' => $request->input('delivery_terms') ?? $request->input('deliveryTerms'),
            'technical_requirements' => $request->input('technical_requirements') ?? $request->input('technicalRequirements'),
            'additional_notes' => $request->input('additional_notes') ?? $request->input('additionalNotes') ?? $request->notes,
            'terms_and_conditions' => $request->input('terms_and_conditions') ?? $request->input('termsAndConditions'),
            'notes' => $request->notes ?? $request->input('additional_notes') ?? $request->input('additionalNotes'),
            'supporting_documents' => !empty($supportingDocuments) ? $supportingDocuments : null,
            'status' => 'Open',
            'workflow_state' => 'open',
            'created_by' => $user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        // Attach vendors (syncWithoutDetaching ensures existing vendors are preserved)
        $vendorIds = Vendor::whereIn('vendor_id', $request->vendorIds)->pluck('id');
        $rfq->vendors()->syncWithoutDetaching($vendorIds);

        $rfq->load('vendors');

        // Log activity - RFQ sent to vendors
        try {
            Activity::create([
                'type' => 'rfq_sent',
                'title' => 'RFQ Sent to Vendors',
                'description' => "RFQ {$rfq->rfq_id} was sent to " . count($vendorIds) . " vendor(s)",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'rfq',
                'entity_id' => $rfq->rfq_id,
                'status' => 'open',
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log RFQ sent activity', ['error' => $e->getMessage()]);
        }

        try {
            $rfq->loadMissing('vendors');
            $this->workflowNotificationService->notifyRFQSent($rfq);
        } catch (\Exception $e) {
            \Log::error('Failed to send RFQ sent email notifications', [
                'event' => 'rfq_sent',
                'recipient' => null,
                'model_id' => $rfq->rfq_id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($mrf && PaymentMilestoneRequest::provided($request)) {
            try {
                app(PaymentScheduleService::class)->applyFromRequest($mrf, $user, $request);
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        return response()->json([
            'id' => $rfq->rfq_id,
            'formattedId' => $rfq->formatted_id,
            'formatted_id' => $rfq->formatted_id,
            'legacyId' => $rfq->rfq_id,
            'legacy_id' => $rfq->rfq_id,
            'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
            'mrfFormattedId' => $rfq->mrf?->formatted_id,
            'mrf_formatted_id' => $rfq->mrf?->formatted_id,
            'mrfTitle' => $rfq->mrf_title,
            'title' => $rfq->title,
            'category' => $rfq->category,
            'description' => $rfq->description,
            'quantity' => $rfq->quantity,
            'estimatedCost' => $rfq->estimated_cost !== null ? (float) $rfq->estimated_cost : null,
            ...$rfq->extendedDetailApiFields(),
            'paymentSchedule' => $this->paymentSchedulePayload($rfq->mrf),
            'payment_schedule' => $this->paymentSchedulePayload($rfq->mrf),
            'payment_milestones' => $mrf
                ? app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf)
                : [],
            'paymentMilestones' => $mrf
                ? app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf)
                : [],
            'supportingDocuments' => $this->supportingDocumentsPayload($rfq),
            'supporting_documents' => $this->supportingDocumentsPayload($rfq),
            'deadline' => $rfq->deadline->format('Y-m-d'),
            'status' => $rfq->status,
            'workflowState' => $rfq->workflow_state,
            'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
            'createdAt' => $rfq->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Upload supporting documents for an RFQ (multipart).
     *
     * POST /api/rfqs/{id}/attachments
     */
    public function uploadAttachments(Request $request, $id)
    {
        $rfq = $this->findRfqByAnyId((string) $id);

        if (! $rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $files = [];
        if ($request->hasFile('attachments')) {
            $uploaded = $request->file('attachments');
            $files = is_array($uploaded) ? $uploaded : [$uploaded];
        } elseif ($request->hasFile('file')) {
            $files = [$request->file('file')];
        }

        if ($files === []) {
            return response()->json([
                'success' => false,
                'error' => 'No attachment files provided',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $attachmentService = app(RfqAttachmentService::class);
        $stored = $attachmentService->storeUploadedAttachments($files, $rfq->rfq_id);

        $existing = is_array($rfq->supporting_documents) ? $rfq->supporting_documents : [];
        $rfq->update([
            'supporting_documents' => array_values(array_merge($existing, $stored)),
        ]);

        $payload = array_map(static fn (array $row) => [
            'id' => $row['id'],
            'filename' => $row['filename'],
            'url' => $row['url'],
        ], $stored);

        return response()->json([
            'success' => true,
            'data' => $payload,
        ], 201);
    }

    /**
     * Update RFQ
     */
    public function update(Request $request, $id)
    {
        $rfq = $this->findRfqByAnyId((string) $id);

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), array_merge([
            'description' => 'sometimes|required|string',
            'quantity' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|nullable|numeric|min:0',
            'deadline' => 'sometimes|required|date',
            'status' => 'sometimes|in:Open,Closed,Awarded,Cancelled',
            'vendorIds' => 'sometimes|array|min:1',
            'vendorIds.*' => 'exists:vendors,vendor_id',
        ], PaymentMilestoneRequest::validationRules()));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        PaymentMilestoneRequest::mergeIntoRequest($request);
        if (PaymentMilestoneRequest::provided($request)) {
            try {
                PaymentMilestoneRequest::validatePercentages(PaymentMilestoneRequest::resolve($request));
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        $updateData = [];
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('estimatedCost')) {
            $updateData['estimated_cost'] = $request->estimatedCost !== null ? (float) $request->estimatedCost : null;
        }
        if ($request->has('deadline')) $updateData['deadline'] = $request->deadline;
        if ($request->has('status')) $updateData['status'] = $request->status;

        $rfq->update($updateData);

        // Update vendors if provided
        if ($request->has('vendorIds')) {
            $vendorIds = Vendor::whereIn('vendor_id', $request->vendorIds)->pluck('id');
            $rfq->vendors()->sync($vendorIds);
        }

        $rfq->load(['mrf', 'vendors']);

        if ($rfq->mrf && PaymentMilestoneRequest::provided($request)) {
            try {
                app(PaymentScheduleService::class)->applyFromRequest($rfq->mrf, $request->user(), $request);
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $e->errors(),
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        return response()->json([
            'id' => $rfq->rfq_id,
            'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
            'mrfTitle' => $rfq->mrf_title,
            'description' => $rfq->description,
            'quantity' => $rfq->quantity,
            'estimatedCost' => (float) $rfq->estimated_cost,
            'deadline' => $rfq->deadline->format('Y-m-d'),
            'status' => $rfq->status,
            'payment_milestones' => $rfq->mrf
                ? app(PaymentScheduleService::class)->paymentMilestonesForMrf($rfq->mrf)
                : [],
            'paymentMilestones' => $rfq->mrf
                ? app(PaymentScheduleService::class)->paymentMilestonesForMrf($rfq->mrf)
                : [],
            'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
            'createdAt' => $rfq->created_at->toIso8601String(),
        ]);
    }

    private function paymentSchedulePayload(?MRF $mrf): ?array
    {
        if (! $mrf) {
            return null;
        }

        if ($mrf->relationLoaded('paymentSchedule') && $mrf->paymentSchedule) {
            return app(PaymentScheduleService::class)->toApiArray($mrf->paymentSchedule);
        }

        $schedule = app(PaymentScheduleService::class)->findForMrf($mrf);

        return $schedule ? app(PaymentScheduleService::class)->toApiArray($schedule) : null;
    }

    private function resolvePaymentTermsForMrf(?MRF $mrf, ?string $fallback): ?string
    {
        if (! $mrf) {
            return $fallback;
        }

        $schedule = app(PaymentScheduleService::class)->findForMrf($mrf);

        if ($schedule) {
            return app(PaymentScheduleService::class)->summaryText($schedule);
        }

        return $fallback;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function supportingDocumentsPayload(RFQ $rfq): array
    {
        return app(RfqAttachmentService::class)->hydrateSupportingDocuments($rfq->supporting_documents);
    }
}
