<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\Activity;
use App\Models\MRFApprovalHistory;
use App\Models\RFQ;
use App\Models\SRF;
use App\Models\Vendor;
use App\Models\Logistics\VehicleMaintenance;
use App\Support\LogisticsMrfRouting;
use App\Services\LineItemBudgetService;
use App\Services\AttachmentService;
use App\Support\PaymentMilestoneRequest;
use App\Support\PurchaseOrderCurrency;
use App\Support\RequestLineItemParser;
use Illuminate\Validation\ValidationException;
use App\Services\NotificationService;
use App\Services\FormattedIdGenerator;
use App\Services\WorkflowNotificationService;
use App\Services\FinanceAp\MrfProgressTrackerService;
use App\Services\PaymentScheduleService;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use App\Services\RequesterEditWindowService;
use App\Services\PurchaseOrderPdfService;
use App\Services\QuotationAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Dompdf\Dompdf;
use Dompdf\Options;

class MRFController extends Controller
{
    use ResolvesPaginatedLists;
    protected NotificationService $notificationService;
    protected WorkflowNotificationService $workflowNotificationService;
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;
    protected FormattedIdGenerator $formattedIdGenerator;

    public function __construct(
        NotificationService $notificationService,
        WorkflowNotificationService $workflowNotificationService,
        WorkflowStateService $workflowService,
        PermissionService $permissionService,
        FormattedIdGenerator $formattedIdGenerator
    ) {
        $this->notificationService = $notificationService;
        $this->workflowNotificationService = $workflowNotificationService;
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
        $this->formattedIdGenerator = $formattedIdGenerator;
    }

    private function findMrfByAnyId(string $id)
    {
        return MRF::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('mrf_id', $id);

            if (is_numeric($id)) {
                $query->orWhere('id', (int) $id);
            }
        })->first();
    }

    /**
     * Normalize quotation attachment JSON (legacy string / nested arrays) before hydrate.
     *
     * @param  mixed  $attachments
     * @return list<mixed>
     */
    private function normalizeQuotationAttachmentsPayload($attachments): array
    {
        if ($attachments === null || $attachments === '' || $attachments === []) {
            return [];
        }

        if (is_string($attachments)) {
            return [$attachments];
        }

        if (! is_array($attachments)) {
            return [];
        }

        $isAssoc = array_keys($attachments) !== range(0, count($attachments) - 1);
        if ($isAssoc) {
            return [$attachments];
        }

        $out = [];
        foreach ($attachments as $a) {
            if ($a === null || $a === '') {
                continue;
            }

            if (is_string($a)) {
                $out[] = $a;
                continue;
            }

            if (! is_array($a)) {
                continue;
            }

            $aIsAssoc = array_keys($a) !== range(0, count($a) - 1);
            if ($aIsAssoc) {
                $out[] = $a;
                continue;
            }

            foreach ($a as $inner) {
                if ($inner !== null && $inner !== '') {
                    $out[] = $inner;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Get the storage disk for documents
     */
    protected function getStorageDisk(): string
    {
        return config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
    }

    /**
     * Get file URL - for S3 uses temporary signed URL, for local uses public URL
     * Default expiration is 7 days to prevent URL expiration issues
     */
    protected function getFileUrl(string $filePath, string $disk, int $expirationHours = 168): string
    {
        if ($disk === 's3') {
            try {
                return Storage::disk($disk)->temporaryUrl($filePath, now()->addHours($expirationHours));
        } catch (\Exception $e) {
                Log::warning('S3 temporary URL generation failed, using regular URL', [
                    'error' => $e->getMessage(),
                    'path' => $filePath
                ]);
                return Storage::disk($disk)->url($filePath);
            }
        }

        // For local/public storage
        $url = Storage::disk($disk)->url($filePath);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $baseUrl = config('app.url');
            return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * Generate fresh PO URLs - Called on every API response to ensure URLs never expire
     * This regenerates signed URLs with 7-day expiration
     */
   protected function generateFreshPOUrls(MRF $mrf): array
    {
        $disk = $this->getStorageDisk();
        $freshUrls = [
            'unsigned_po_url'       => null,
            'unsigned_po_share_url' => null,
            'signed_po_url'         => null,
            'signed_po_share_url'   => null,
        ];

        try {
            // Prefer streaming URL so PDF always uses the current template (S3 file may be an old snapshot).
            $streamUrl = $mrf->freshUnsignedPoStreamUrl();
            if ($streamUrl) {
                $freshUrls['unsigned_po_url'] = $streamUrl;
                $freshUrls['unsigned_po_share_url'] = $streamUrl;
            } elseif (!empty($mrf->unsigned_po_url)) {
                $path = Storage::disk($disk)->exists($mrf->unsigned_po_url)
                    ? $mrf->unsigned_po_url
                    : $this->extractFilePathFromUrl($mrf->unsigned_po_url);

                if ($path && Storage::disk($disk)->exists($path)) {
                    $freshUrls['unsigned_po_url'] = $this->getFileUrl($path, $disk);
                    $freshUrls['unsigned_po_share_url'] = $freshUrls['unsigned_po_url'];
                }
            }

            // Signed PO
            if (!empty($mrf->signed_po_url)) {
                $path = Storage::disk($disk)->exists($mrf->signed_po_url)
                    ? $mrf->signed_po_url
                    : $this->extractFilePathFromUrl($mrf->signed_po_url);

                if ($path && Storage::disk($disk)->exists($path)) {
                    $freshUrls['signed_po_url']       = $this->getFileUrl($path, $disk);
                    $freshUrls['signed_po_share_url'] = $freshUrls['signed_po_url'];
                } else {
                    // Fallback: try signed prefix
                    $signedPath = 'purchase-orders/signed/' . basename($mrf->signed_po_url);
                    if (Storage::disk($disk)->exists($signedPath)) {
                        $freshUrls['signed_po_url']       = $this->getFileUrl($signedPath, $disk);
                        $freshUrls['signed_po_share_url'] = $freshUrls['signed_po_url'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to generate fresh PO URLs', [
                'mrf_id' => $mrf->mrf_id,
                'error'  => $e->getMessage()
            ]);
        }

        return $freshUrls;
    }

    /**
     * Extract file path from S3 URL or return the path if it's already a path
     * Also ensures the path has the proper directory prefix
     */
    private function extractFilePathFromUrl(string $urlOrPath): ?string
    {
        // If it's already a plain file path (no protocol), return as-is
        if (! str_contains($urlOrPath, '://')) {
            return $urlOrPath ?: null;
        }

        // For S3 URLs, extract just the path portion
        if (str_contains($urlOrPath, 's3')) {
            $parsed = parse_url($urlOrPath);

            if (! isset($parsed['path'])) {
                return null;
            }

            // Remove leading slash to get the raw S3 key
            $path = ltrim($parsed['path'], '/');

            // The S3 bucket name is the first segment of the path
            $bucketName = config('filesystems.disks.s3.bucket', '');

            if ($bucketName && str_starts_with($path, $bucketName.'/')) {
                $path = substr($path, strlen($bucketName) + 1);
            }

            return $path ?: null;
        }

        return null;
    }

    /**
     * Stream bytes from storage when an unsigned PO PDF already exists (avoids Dompdf render).
     */
    private function resolveStoredUnsignedPoBinary(MRF $mrf): ?string
    {
        $disk = $this->getStorageDisk();
        $candidates = [];

        if (! empty($mrf->unsigned_po_url)) {
            $candidates[] = $mrf->unsigned_po_url;
            $extracted = $this->extractFilePathFromUrl($mrf->unsigned_po_url);
            if ($extracted) {
                $candidates[] = $extracted;
            }
        }

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            try {
                if (Storage::disk($disk)->exists($candidate)) {
                    $binary = Storage::disk($disk)->get($candidate);
                    if (is_string($binary) && $binary !== '') {
                        return $binary;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
    /**
     * Lightweight MRF search for PO fast-track comboboxes (search required, max 20 rows).
     */
    private function dropdownMrfIndex(Request $request): \Illuminate\Http\JsonResponse
    {
        $search = trim((string) $request->input('search', $request->input('q', '')));
        if ($search === '') {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $like = '%'.$search.'%';
        $items = MRF::query()
            ->select(['id', 'mrf_id', 'formatted_id', 'title', 'po_number'])
            ->where(function ($q) use ($like) {
                $q->where('mrf_id', 'like', $like)
                    ->orWhere('formatted_id', 'like', $like)
                    ->orWhere('po_number', 'like', $like)
                    ->orWhere('title', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (MRF $mrf) => [
                'id' => $mrf->mrf_id,
                'mrfId' => $mrf->mrf_id,
                'formattedId' => $mrf->formatted_id,
                'title' => $mrf->title,
                'poNumber' => $mrf->po_number,
                'label' => trim(($mrf->formatted_id ?: $mrf->mrf_id).' — '.($mrf->title ?? '')),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Get all MRFs with optional filters
     */
    public function index(Request $request)
    {
        try {
        if ($request->boolean('dropdown') || $request->boolean('for_dropdown')) {
            return $this->dropdownMrfIndex($request);
        }

        $isPoList = $request->boolean('has_po') || $request->boolean('po_list');

        $query = MRF::query()
            ->select($isPoList ? MRF::resolveListApiSelect() : MRF::resolveTableListSelect());

        if ($isPoList) {
            $query->forPoList();
        }

        // PO tab status buckets vs raw MRF status column
        if ($request->filled('status') && strtolower((string) $request->status) !== 'all') {
            if ($isPoList) {
                $query->withPoLifecycleStatus((string) $request->status);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('workflow_state')) {
            $query->where('workflow_state', $request->workflow_state);
        }

        if ($request->filled('workflow_states')) {
            $states = array_filter(array_map('trim', explode(',', (string) $request->workflow_states)));
            if ($states !== []) {
                $query->workflowStates($states);
            }
        }

        if ($request->filled('pending_for_role')) {
            $query->pendingForRole((string) $request->pending_for_role);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        // Search indexed identifier / requester columns.
        // Prefer prefix LIKE (no leading %) so B-tree indexes remain usable.
        // Fall back to contains only for short tokens when prefix yields nothing
        // would be too aggressive; use prefix for all PO/MRF identifier searches.
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            if ($search !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $prefix = $escaped.'%';
                $query->where(function ($q) use ($prefix, $escaped) {
                    $q->where('mrf_id', 'like', $prefix)
                        ->orWhere('formatted_id', 'like', $prefix)
                        ->orWhere('po_number', 'like', $prefix)
                        ->orWhere('requester_name', 'like', $prefix);
                    // Exact substring still allowed for requester names (user expectation),
                    // but only as OR on the denormalised requester_name column.
                    if (strlen($escaped) >= 3) {
                        $q->orWhere('requester_name', 'like', '%'.$escaped.'%');
                    }
                });
            }
        }

        $defaultSort = $isPoList ? 'updated_at' : 'created_at';
        [$sortBy, $sortOrder] = $this->resolveSort(
            $request,
            ['date', 'estimated_cost', 'title', 'status', 'created_at', 'updated_at', 'po_draft_saved_at', 'po_generated_at'],
            $defaultSort,
            'desc',
        );

        // Legacy sortBy/sortOrder from older clients
        if ($request->filled('sortBy') && ! $request->filled('sort_by')) {
            $legacy = (string) $request->get('sortBy');
            if (in_array($legacy, ['date', 'estimated_cost', 'title', 'status', 'created_at'], true)) {
                $sortBy = $legacy;
            }
            $legacyOrder = strtolower((string) $request->get('sortOrder', 'desc'));
            if (in_array($legacyOrder, ['asc', 'desc'], true)) {
                $sortOrder = $legacyOrder;
            }
        }

        $query->orderBy($sortBy, $sortOrder);

        // Filter by requester (for employees to see only their own)
        $user = $request->user();

        // If user is a vendor, they typically don't need direct access to MRFs
        // But allow access and return empty array or MRFs related to their RFQs
        $isVendor = false;
        if ($user && ($user->scmRole() === 'vendor' || $user->hasScmRole('vendor'))) {
            $isVendor = true;
            // Vendors can see MRFs that are linked to RFQs assigned to them
            // For now, return empty array - vendors should access MRFs through RFQs
            $perPage = $this->resolvePerPage($request, 25, 100);
            return response()->json([
                'success' => true,
                'data' => [],
                'mrfs' => [],
                'pagination' => [
                    'page' => $this->resolvePage($request),
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                    'from' => null,
                    'to' => null,
                ],
            ]);
        }

        if ($user && in_array($user->scmRole(), ['employee', 'general_employee'])) {
            $query->where('requester_id', $user->id);
        }

        $listStarted = microtime(true);
        $paginator = $this->paginateWithCachedCount($query, $request, 'mrf');

        $items = collect($paginator->items())
            ->map(fn (MRF $mrf) => $mrf->toListApiArray())
            ->values()
            ->all();

        if ($isPoList) {
            Log::info('PO list query completed', [
                'elapsed_ms' => (int) round((microtime(true) - $listStarted) * 1000),
                'row_count' => count($items),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'status' => $request->input('status'),
                'search_len' => strlen(trim((string) $request->input('search', ''))),
            ]);
        }

        return response()->json($this->paginatedJsonResponse($paginator, $items));
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database errors (e.g., missing columns)
            $errorMessage = $e->getMessage();
            $errorCode = $e->getCode();

            Log::error('MRF index query error', [
                'error' => $errorMessage,
                'code' => $errorCode,
                'sql_state' => $e->errorInfo[0] ?? null,
            ]);

            // Check for column-related errors (MySQL, PostgreSQL, SQLite variations)
            $columnErrorPatterns = [
                "Unknown column",
                "doesn't exist",
                "does not exist",
                "column.*does not exist",
                "SQLSTATE[42S22]", // MySQL: Column not found
                "SQLSTATE[42703]", // PostgreSQL: Undefined column
            ];

            $isColumnError = false;
            foreach ($columnErrorPatterns as $pattern) {
                if (stripos($errorMessage, $pattern) !== false ||
                    preg_match('/' . $pattern . '/i', $errorMessage)) {
                    $isColumnError = true;
                    break;
                }
            }

            if ($isColumnError) {
                Log::warning('MRF index: Missing database columns detected. Migration may need to be run.', [
                    'error' => $errorMessage,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Database schema is out of date for MRF listing. Run pending migrations on the server.',
                    'code' => 'SCHEMA_OUT_OF_DATE',
                    'message' => config('app.debug') ? $errorMessage : 'A database migration is required. Please contact support.',
                ], 503);
            }

            // For other database errors, return error response
            return response()->json([
                'success' => false,
                'error' => 'Database error occurred',
                'code' => 'DATABASE_ERROR',
                'message' => config('app.debug') ? $errorMessage : 'A database error occurred. Please contact support.'
            ], 500);
        } catch (\Exception $e) {
            Log::error('MRF index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while fetching MRFs',
                'code' => 'INTERNAL_ERROR',
                'message' => config('app.debug') ? $e->getMessage() : 'An internal error occurred. Please try again later.'
            ], 500);
        }
    }

    /**
     * Get available actions for current user on an MRF
     */
    public function getAvailableActions(Request $request, $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $availableActions = $this->permissionService->getAvailableActions($user, $mrf);

        return response()->json([
            'success' => true,
            'data' => $availableActions
        ]);
    }

    /**
     * Get single MRF by ID
     */
    public function show(Request $request, $id)
    {
        $startedAt = microtime(true);
        $forPo = $request->boolean('for_po') || $request->boolean('forPo');

        $with = $forPo
            ? ['priceComparisons.vendor:id,vendor_id,name']
            : ['requester', 'directorApprover', 'executiveApprover', 'priceComparisons.vendor:id,vendor_id,name', 'items', 'attachments.uploader:id,name,email'];

        $mrf = MRF::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('mrf_id', $id);

            if (is_numeric((string) $id)) {
                $query->orWhere('id', (int) $id);
            }
        })
            ->with($with)
            ->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $paymentScheduleService = app(PaymentScheduleService::class);
        // Create/Edit PO hydrate only needs the milestone rows — use the light
        // fetch (no creator relation) to shave a remote round trip. The full
        // MRF detail view keeps the standard fetch.
        $paymentMilestones = $forPo
            ? $paymentScheduleService->paymentMilestonesForMrfLight($mrf)
            : $paymentScheduleService->paymentMilestonesForMrf($mrf);

        // Lightweight hydrate for Create PO / Edit PO form — skip unused relations & services.
        if ($forPo) {
            $priceComparisons = $mrf->priceComparisons->map(function ($row) {
                return [
                    'id' => $row->id,
                    'purchase_order_id' => $row->purchase_order_id,
                    'vendor_id' => $row->vendor?->vendor_id ?? $row->vendor_id,
                    'vendor_internal_id' => $row->vendor_id,
                    'vendor_name' => $row->vendor?->name,
                    'manual_vendor' => $row->manual_vendor ?? null,
                    'item_description' => $row->item_description,
                    'unit_price' => (float) $row->unit_price,
                    'quantity' => (float) $row->quantity,
                    'total_price' => (float) $row->total_price,
                    'is_selected' => (bool) $row->is_selected,
                    'selection_reason' => $row->selection_reason,
                ];
            })->values();

            $payload = array_merge(
                $mrf->scmTransactionApiFields(),
                $mrf->poFormApiFields(),
                [
                    'id' => $mrf->mrf_id,
                    'formattedId' => $mrf->formatted_id,
                    'formatted_id' => $mrf->formatted_id,
                    'title' => $mrf->title,
                    'status' => $mrf->status,
                    'currentStage' => $mrf->current_stage,
                    'workflowState' => $mrf->workflow_state,
                    'workflow_state' => $mrf->workflow_state,
                    'priceComparisons' => $priceComparisons,
                    'payment_milestones' => $paymentMilestones,
                    'paymentMilestones' => $paymentMilestones,
                ]
            );

            Log::info('PO detail fetch (for_po) completed', [
                'mrf_id' => $mrf->mrf_id,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'relation_count' => 1,
                'price_comparison_rows' => $priceComparisons->count(),
                'milestone_count' => count($paymentMilestones),
            ]);

            return response()->json($payload);
        }

        // Always mint fresh PO links — stored S3 temp URLs expire and yield AccessDenied.
        $freshPOUrls = $this->generateFreshPOUrls($mrf);
        $profitAndLoss = $request->boolean('include_pnl')
            ? app(LineItemBudgetService::class)->mrfProfitAndLoss($mrf)
            : null;
        $documentService = app(\App\Services\ProcurementDocumentService::class);
        $vendorId = $documentService->resolveVendorId($mrf);
        $vendorInvoiceDoc = $documentService->findActiveDocument(
            $mrf,
            \App\Models\ProcurementDocument::TYPE_VENDOR_INVOICE,
            $vendorId
        );
        $vendorInvoice = $vendorInvoiceDoc ? $documentService->transform($vendorInvoiceDoc) : null;
        $requesterEditService = app(RequesterEditWindowService::class);
        $attachments = app(AttachmentService::class)->payloadFor($mrf);

        $procurementDocuments = null;
        if ($request->boolean('include_documents')) {
            $procurementDocuments = $documentService->listGroupedForMrf($mrf);
        }

        $mappedItems = $mrf->items->map(fn ($item) => [
            'id' => $item->id,
            'itemName' => $item->item_name,
            'item_name' => $item->item_name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unitPrice' => $item->unit_price !== null ? (float) $item->unit_price : null,
            'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
            'totalPrice' => $item->total_price !== null
                ? (float) $item->total_price
                : ($item->unit_price !== null ? (float) $item->unit_price * (float) $item->quantity : null),
            'total_price' => $item->total_price !== null
                ? (float) $item->total_price
                : ($item->unit_price !== null ? (float) $item->unit_price * (float) $item->quantity : null),
            'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'budget_amount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            'quotedAmount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
            'quoted_amount' => $item->quoted_amount !== null ? (float) $item->quoted_amount : null,
        ])->values();

        Log::info('PO/MRF detail fetch completed', [
            'mrf_id' => $mrf->mrf_id,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'relation_count' => 6,
            'for_po' => false,
            'include_pnl' => $profitAndLoss !== null,
            'include_documents' => $procurementDocuments !== null,
        ]);

        return response()->json(array_merge(
            $mrf->scmTransactionApiFields(),
            $mrf->poFormApiFields(),
            $requesterEditService->metaForMrf($request->user(), $mrf),
            [
            'id' => $mrf->mrf_id,
            'formattedId' => $mrf->formatted_id,
            'formatted_id' => $mrf->formatted_id,
            'legacyId' => $mrf->mrf_id,
            'legacy_id' => $mrf->mrf_id,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'contractType' => $mrf->contract_type,
            'routedReason' => $mrf->routed_reason,
            'urgency' => $mrf->urgency,
            'description' => $mrf->description,
            'quantity' => $mrf->quantity,
            'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
            ...$mrf->currencyApiFields(),
            'justification' => $mrf->justification,
            'requester' => $mrf->requester_name,
            'requesterId' => (string) $mrf->requester_id,
            'department' => $mrf->department,
            'date' => $mrf->date ? $mrf->date->format('Y-m-d') : null,
            'createdAt' => $mrf->created_at?->toIso8601String(),
            'created_at' => $mrf->created_at?->toIso8601String(),
            'status' => $mrf->status,
            'currentStage' => $mrf->current_stage,
            'workflowState' => $mrf->workflow_state,
            ...app(\App\Services\MrfParallelFirstApprovalService::class)->apiFields($mrf),
            'approvalHistory' => $mrf->approval_history ?? [],
            'rejectionReason' => $mrf->rejection_reason,
            'isResubmission' => $mrf->is_resubmission,
            'remarks' => $mrf->remarks,
            // Executive approval - make it clearly visible
            'executiveApproved' => (bool) $mrf->executive_approved,
            'executiveApprovedAt' => $mrf->executive_approved_at ? $mrf->executive_approved_at->toIso8601String() : null,
            'executiveApprovedBy' => $mrf->executiveApprover ? [
                'id' => $mrf->executiveApprover->id,
                'name' => $mrf->executiveApprover->name,
                'email' => $mrf->executiveApprover->email,
            ] : null,
            'executiveRemarks' => $mrf->executive_remarks,
            'scd_approved_by' => $mrf->directorApprover?->name
                ?? $mrf->director_approved_by
                ?? $mrf->scd_approved_by
                ?? $mrf->supply_chain_approved_by
                ?? null,
            'scd_approved_at' => ($mrf->scd_approved_at ?? $mrf->director_approved_at ?? $mrf->supply_chain_approved_at)?->toIso8601String(),
            'scd_remarks' => $mrf->scd_remarks
                ?? $mrf->director_remarks
                ?? $mrf->supply_chain_remarks
                ?? $mrf->remarks
                ?? null,

            'chairmanApproved' => (bool) $mrf->chairman_approved,
            'chairmanApprovedAt' => $mrf->chairman_approved_at ? $mrf->chairman_approved_at->toIso8601String() : null,
            // PO information - allows Supply Chain to review/download unsigned PO
            'po_number' => $mrf->po_number,
            'poNumber' => $mrf->po_number,
            'unsigned_po_url' => $freshPOUrls['unsigned_po_url'] ?? $mrf->unsigned_po_url,
            'unsignedPoUrl' => $freshPOUrls['unsigned_po_url'] ?? $mrf->unsigned_po_url,
            'unsigned_po_share_url' => $freshPOUrls['unsigned_po_share_url'] ?? $mrf->unsigned_po_share_url,
            'unsignedPoShareUrl' => $freshPOUrls['unsigned_po_share_url'] ?? $mrf->unsigned_po_share_url,
            'signed_po_url' => $freshPOUrls['signed_po_url'] ?? $mrf->signed_po_url,
            'signedPoUrl' => $freshPOUrls['signed_po_url'] ?? $mrf->signed_po_url,
            'signed_po_share_url' => $freshPOUrls['signed_po_share_url'] ?? $mrf->signed_po_share_url,
            'signedPoShareUrl' => $freshPOUrls['signed_po_share_url'] ?? $mrf->signed_po_share_url,
            'po_generated_at' => $mrf->po_generated_at?->toIso8601String(),
            'poGeneratedAt' => $mrf->po_generated_at?->toIso8601String(),
            'po_signed_at' => $mrf->po_signed_at?->toIso8601String(),
            'poSignedAt' => $mrf->po_signed_at?->toIso8601String(),
            'custom_terms' => $mrf->custom_terms,
            'customTerms' => $mrf->custom_terms,
            'po_terms_mode' => $mrf->po_terms_mode,
            'poTermsMode' => $mrf->po_terms_mode,
            'priceComparisons' => $mrf->priceComparisons->map(function($row) {
                return [
                    'id' => $row->id,
                    'purchase_order_id' => $row->purchase_order_id,
                    'vendor_id' => $row->vendor?->vendor_id ?? $row->vendor_id,
                    'manual_vendor' => $row->manual_vendor ?? null,
                    'vendor_name' => $row->vendor?->name ?? $row->vendor_name ?? null,
                    'item_description' => $row->item_description,
                    'unit_price' => (float) $row->unit_price,
                    'quantity' => (float) $row->quantity,
                    'total_price' => (float) $row->total_price,
                    'is_selected' => (bool) $row->is_selected,
                    'selection_reason' => $row->selection_reason,
                ];
            })->values(),
            // Supporting attachment
            'attachmentUrl' => $mrf->attachment_url,
            'attachmentShareUrl' => $mrf->attachment_share_url,
            'attachment_url' => $mrf->attachment_url,
            'attachment_share_url' => $mrf->attachment_share_url,
            'attachmentName' => $mrf->attachment_name,
            'attachment_name' => $mrf->attachment_name,
            'attachments' => $attachments,
            'documents' => $attachments,
            'items' => $mappedItems,
            'line_items' => $mappedItems,
            'profitAndLoss' => $profitAndLoss,
            'payment_milestones' => $paymentMilestones,
            'paymentMilestones' => $paymentMilestones,
            'vendorInvoice' => $vendorInvoice,
            'vendor_invoice' => $vendorInvoice,
            'procurementDocuments' => $procurementDocuments,
            'procurement_documents' => $procurementDocuments,
            ]
        ));
    }

    /**
     * Per-line-item budget vs quoted P&L breakdown.
     */
    public function lineItemProfitAndLoss($id)
    {
        $mrf = $this->findMrfByAnyId((string) $id);
        if (!$mrf) {
            return response()->json(['success' => false, 'error' => 'MRF not found', 'code' => 'NOT_FOUND'], 404);
        }

        $mrf->load('items');

        $pnl = app(LineItemBudgetService::class)->mrfProfitAndLoss($mrf);

        return response()->json([
            'success' => true,
            'mrfId' => $mrf->mrf_id,
            'items' => $pnl['items'],
            'line_items' => $pnl['line_items'] ?? $pnl['items'],
            'lineItems' => $pnl['lineItems'] ?? $pnl['items'],
            'rows' => $pnl['rows'] ?? $pnl['items'],
            'summary' => $pnl['summary'],
            'profitAndLoss' => $pnl,
            'data' => $pnl,
        ]);
    }

    /**
     * Get full MRF details with all quotations (for procurement managers)
     * Provides end-to-end visibility including all vendor quotations
     */
    public function getFullDetails(Request $request, $id)
    {
        $user = $request->user();

        // Roles allowed to view the full procurement timeline (MRF +
        // quotations + selected vendor). Logistics manager/officer need
        // this so they can track end-to-end progress of fleet/logistics
        // requisitions they originated.
        $allowedRoles = [
            'procurement_manager',
            'procurement',
            'supply_chain_director',
            'supply_chain',
            'admin',
            'logistics_manager',
            'logistics_officer',
            'finance',
            'finance_officer',
            'executive',
        ];
        if (!in_array($user->scmRole(), $allowedRoles, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('mrf_id', $id);

            if (is_numeric((string) $id)) {
                $query->orWhere('id', (int) $id);
            }
        })
            ->with([
                'requester:id,name,email',
                'executiveApprover:id,name,email',
                'chairmanApprover:id,name,email',
                'selectedVendor:id,vendor_id,name,email,phone,rating',
                'rfqs' => fn ($query) => $query->with([
                    'quotations' => fn ($q) => $q->with([
                        'vendor:id,vendor_id,name,email,phone,rating',
                        'items.rfqItem',
                    ]),
                    'vendors:id,vendor_id,name',
                ]),
                'items',
                'attachments.uploader:id,name,email',
            ])
            ->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $mrf->loadMissing(['priceComparisons.vendor']);

        // Get all RFQs for this MRF
        $rfqs = $mrf->rfqs;
        $attachments = app(AttachmentService::class)->payloadFor($mrf);

        // Fleet / maintenance SRFs referenced from RFQ copy (e.g. "SRF-2026-001")
        $srfIdMatches = collect();
        foreach ($rfqs as $rfq) {
            foreach ([$rfq->title, $rfq->description, $rfq->notes ?? ''] as $text) {
                if ($text && preg_match_all('/SRF-\d{4}-\d+/i', (string) $text, $m)) {
                    foreach ($m[0] as $sid) {
                        $srfIdMatches->push(strtoupper((string) $sid));
                    }
                }
            }
        }
        $linkedFleetSrfsPayload = collect();
        if ($srfIdMatches->isNotEmpty()) {
            $linkedSrfs = SRF::query()
                ->whereIn('srf_id', $srfIdMatches->unique()->values()->all())
                ->with(['vehicle', 'maintenance'])
                ->get();

            $vehicleIds = $linkedSrfs->pluck('vehicle_id')->filter()->unique()->values();
            $maintenanceByVehicle = $vehicleIds->isEmpty()
                ? collect()
                : VehicleMaintenance::query()
                    ->whereIn('vehicle_id', $vehicleIds)
                    ->orderByDesc('created_at')
                    ->get()
                    ->groupBy('vehicle_id');

            $linkedFleetSrfsPayload = $linkedSrfs
                ->map(function (SRF $srf) use ($maintenanceByVehicle) {
                    $live = $srf->vehicle_id
                        ? ($maintenanceByVehicle->get($srf->vehicle_id) ?? collect())
                            ->map(function ($m) {
                                return [
                                    'id' => $m->id,
                                    'maintenance_type' => $m->maintenance_type,
                                    'description' => $m->description,
                                    'performed_at' => optional($m->performed_at)->toIso8601String(),
                                    'next_due_at' => optional($m->next_due_at)->toIso8601String(),
                                    'cost' => $m->cost !== null ? (float) $m->cost : null,
                                    'status' => $m->status,
                                ];
                            })->values()
                        : collect();

                    return [
                        'srf_id' => $srf->srf_id,
                        'formatted_id' => $srf->formatted_id,
                        'title' => $srf->title,
                        'current_stage' => $srf->current_stage,
                        'vehicle_id' => $srf->vehicle_id,
                        'maintenance_id' => $srf->maintenance_id,
                        'vehicle_snapshot' => $srf->vehicle_snapshot,
                        'maintenance_history' => $srf->maintenance_history,
                        'rfq_prefill' => $srf->rfq_prefill,
                        'live_maintenance_records' => $live,
                    ];
                })->values();
        }

        // Collect all quotations from all RFQs
        // Exclude rejected quotations from active view (they remain accessible for historical tracking)
        $quotationAttachmentService = app(QuotationAttachmentService::class);
        $allQuotations = collect();
        foreach ($rfqs as $rfq) {
            foreach ($rfq->quotations as $quotation) {
                // Skip rejected quotations from active view
                if ($quotation->status === 'Rejected' || $quotation->review_status === 'rejected') {
                    continue; // Rejected quotations are not shown in active view but remain in database for historical tracking
                }

                $deliveryDays = $quotation->delivery_days;

                if ($deliveryDays === null && $quotation->delivery_date) {
                    $deliveryDays = now()->startOfDay()->diffInDays(
                        \Carbon\Carbon::parse($quotation->delivery_date)->startOfDay(),
                        false
                    );

                    if ($deliveryDays < 0) {
                        $deliveryDays = 0;
                    }
                }

                $deliveryDays = (int) $deliveryDays;

                $allQuotations->push([
                    'id' => $quotation->quotation_id,
                    'rfqId' => $rfq->rfq_id,
                    'rfqTitle' => $rfq->getDisplayTitle(),
                    'quoteNumber' => $quotation->quote_number,
                    'vendor' => $quotation->vendor ? [
                        'id' => $quotation->vendor->vendor_id,
                        'name' => $quotation->vendor->name,
                        'email' => $quotation->vendor->email,
                        'phone' => $quotation->vendor->phone,
                        'rating' => (float) $quotation->vendor->rating,
                    ] : [
                        'id' => null,
                        'name' => $quotation->vendor_name ?? 'Unknown Vendor',
                    ],

                    'totalAmount' => (float) $quotation->total_amount,
                    'total_amount' => (float) $quotation->total_amount,
                    'total_order_value' => (float) $quotation->total_amount,
                    'totalOrderValue' => (float) $quotation->total_amount,
                    'price' => (float) ($quotation->price ?? $quotation->total_amount),

                    'currency' => $quotation->currency ?? 'NGN',

                    'deliveryDays' => $deliveryDays,
                    'delivery_days' => $deliveryDays,
                    'deliveryDate' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,
                    'delivery_date' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,

                    'paymentTerms' => $quotation->payment_terms ?? null,
                    'payment_terms' => $quotation->payment_terms ?? null,
                    'payment_terms_text' => $quotation->payment_terms ?? null,

                    'validityDays' => $quotation->validity_days,
                    'warrantyPeriod' => $quotation->warranty_period,
                    'notes' => $quotation->notes,
                    // signUrls=false — full-details is for metadata; signed URLs minted when opened.
                    'attachments' => $quotationAttachmentService->hydrateAttachments(
                        $this->normalizeQuotationAttachmentsPayload($quotation->attachments),
                        false
                    ),
                    'status' => $quotation->status,
                    'reviewStatus' => $quotation->review_status ?? 'pending',
                    'submittedAt' => $quotation->submitted_at ? $quotation->submitted_at->toIso8601String() : null,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mrf' => array_merge($mrf->scmTransactionApiFields(), [
                    'id' => $mrf->mrf_id,
                    'formattedId' => $mrf->formatted_id,
                    'formatted_id' => $mrf->formatted_id,
                    'legacyId' => $mrf->mrf_id,
                    'legacy_id' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'contractType' => $mrf->contract_type,
                    'urgency' => $mrf->urgency,
                    'description' => $mrf->description,
                    'quantity' => $mrf->quantity,
                    'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
                    ...$mrf->currencyApiFields(),
                    'justification' => $mrf->justification,
                    'requester' => [
                        'id' => $mrf->requester_id,
                        'name' => $mrf->requester_name,
                        'email' => $mrf->requester ? $mrf->requester->email : null,
                    ],
                    'department' => $mrf->department,
                    'date' => $mrf->date->format('Y-m-d'),
                    'status' => $mrf->status,
                    'workflowState' => $mrf->workflow_state,
                    // Executive approval - clearly visible
                    'executiveApproved' => (bool) $mrf->executive_approved,
                    'executiveApprovedAt' => $mrf->executive_approved_at ? $mrf->executive_approved_at->toIso8601String() : null,
                    'executiveApprovedBy' => $mrf->executiveApprover ? [
                        'id' => $mrf->executiveApprover->id,
                        'name' => $mrf->executiveApprover->name,
                        'email' => $mrf->executiveApprover->email,
                    ] : null,
                    'executiveRemarks' => $mrf->executive_remarks,
                    'chairmanApproved' => (bool) $mrf->chairman_approved,
                    'chairmanApprovedAt' => $mrf->chairman_approved_at ? $mrf->chairman_approved_at->toIso8601String() : null,
                    'attachments' => $attachments,
                    'documents' => $attachments,
                'rfqs' => $rfqs->map(function ($rfq) {
                    return [
                        'id' => $rfq->rfq_id,
                        'title' => $rfq->getDisplayTitle(),
                        'description' => $rfq->description,
                        'status' => $rfq->status,
                        'workflowState' => $rfq->workflow_state,
                        'deadline' => $rfq->deadline ? $rfq->deadline->format('Y-m-d') : null,
                        'vendors' => $rfq->vendors->map(function ($vendor) {
                            return [
                                'id' => $vendor->vendor_id,
                                'name' => $vendor->name,
                                'email' => $vendor->email,
                            ];
                        }),
                    ];
                }),
                'quotations' => $allQuotations,
                // Include selected quotation details for SCD approval view
                // This provides complete quotation information when MRF is in vendor_selected state
                'selectedQuotation' => (function() use ($mrf) {
                    $selectedQuotation = $mrf->selectedQuotation();
                    if (!$selectedQuotation) {
                        return null;
                    }
                    // Load relationships if not already loaded
                    if (!$selectedQuotation->relationLoaded('vendor')) {
                        $selectedQuotation->load('vendor');
                    }
                    if (!$selectedQuotation->relationLoaded('items')) {
                        $selectedQuotation->load('items.rfqItem');
                    }
                    $rfq = $mrf->rfqs->firstWhere('id', $selectedQuotation->rfq_id);
                    return [
                        'id' => $selectedQuotation->quotation_id,
                        'rfqId' => $rfq ? $rfq->rfq_id : null,
                        'rfqTitle' => $rfq ? $rfq->getDisplayTitle() : null,
                        'quoteNumber' => $selectedQuotation->quote_number,
                        'vendor' => $selectedQuotation->vendor ? [
                            'id' => $selectedQuotation->vendor->vendor_id,
                            'name' => $selectedQuotation->vendor->name,
                            'email' => $selectedQuotation->vendor->email,
                            'phone' => $selectedQuotation->vendor->phone,
                            'address' => $selectedQuotation->vendor->address,
                            'contactPerson' => $selectedQuotation->vendor->contact_person,
                            'rating' => (float) $selectedQuotation->vendor->rating,
                        ] : [
                            'id' => null,
                            'name' => $selectedQuotation->vendor_name ?? 'Unknown Vendor',
                        ],
                        'totalAmount' => (float) $selectedQuotation->total_amount,
                        'total_amount' => (float) $selectedQuotation->total_amount,
                        'total_order_value' => (float) $selectedQuotation->total_amount,
                        'totalOrderValue' => (float) $selectedQuotation->total_amount,
                        'currency' => $selectedQuotation->currency ?? 'NGN',
                        'price' => (float) ($selectedQuotation->price ?? $selectedQuotation->total_amount),

                        'deliveryDays' => $selectedQuotation->delivery_days ?? null,
                        'delivery_days' => $selectedQuotation->delivery_days ?? null,
                        'deliveryDate' => $selectedQuotation->delivery_date ? $selectedQuotation->delivery_date->format('Y-m-d') : null,
                        'delivery_date' => $selectedQuotation->delivery_date ? $selectedQuotation->delivery_date->format('Y-m-d') : null,

                        'paymentTerms' => $selectedQuotation->payment_terms ?? null,
                        'payment_terms' => $selectedQuotation->payment_terms ?? null,
                        'payment_terms_text' => $selectedQuotation->payment_terms ?? null,
                        'validityDays' => $selectedQuotation->validity_days,
                        'warrantyPeriod' => $selectedQuotation->warranty_period,
                        'notes' => $selectedQuotation->notes,
                        'scopeOfWork' => $selectedQuotation->notes, // Scope of work
                        'specifications' => $selectedQuotation->notes, // Specifications
                        'attachments' => app(QuotationAttachmentService::class)->hydrateAttachments((function($attachments) {
                            if ($attachments === null || $attachments === '' || $attachments === []) {
                                return [];
                            }

                            if (is_string($attachments)) {
                                return [$attachments];
                            }

                            if (!is_array($attachments)) {
                                return [];
                            }

                            $isAssoc = array_keys($attachments) !== range(0, count($attachments) - 1);
                            if ($isAssoc) {
                                return [$attachments];
                            }

                            $out = [];
                            foreach ($attachments as $a) {
                                if ($a === null || $a === '') {
                                    continue;
                                }

                                if (is_string($a)) {
                                    $out[] = $a;
                                    continue;
                                }

                                if (!is_array($a)) {
                                    continue;
                                }

                                $aIsAssoc = array_keys($a) !== range(0, count($a) - 1);
                                if ($aIsAssoc) {
                                    $out[] = $a;
                                    continue;
                                }

                                foreach ($a as $inner) {
                                    if ($inner !== null && $inner !== '') {
                                        $out[] = $inner;
                                    }
                                }
                            }

                            return array_values($out);
                        })($selectedQuotation->attachments), false), // All uploaded documents
                        'items' => $selectedQuotation->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'itemName' => $item->item_name,
                                'description' => $item->description,
                                'quantity' => $item->quantity,
                                'unit' => $item->unit,
                                'unitPrice' => (float) $item->unit_price,
                                'totalPrice' => (float) $item->total_price,
                                'specifications' => $item->specifications,
                            ];
                        }),
                        'status' => $selectedQuotation->status,
                        'reviewStatus' => $selectedQuotation->review_status ?? 'pending',
                        'submittedAt' => $selectedQuotation->submitted_at ? $selectedQuotation->submitted_at->toIso8601String() : null,
                    ];
                })(),
                'selectedVendor' => $mrf->selectedVendor ? [
                    'id' => $mrf->selectedVendor->vendor_id,
                    'name' => $mrf->selectedVendor->name,
                    'email' => $mrf->selectedVendor->email,
                    'phone' => $mrf->selectedVendor->phone,
                    'address' => $mrf->selectedVendor->address,
                ] : null,
                'statistics' => [
                    'totalQuotations' => $allQuotations->count(),
                    'totalRfqs' => $rfqs->count(),
                    'lowestBid' => $allQuotations->min('totalAmount'),
                    'highestBid' => $allQuotations->max('totalAmount'),
                    'averageBid' => $allQuotations->avg('totalAmount'),
                ],
                'priceComparisons' => $mrf->priceComparisons->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'purchase_order_id' => $row->purchase_order_id,
                        'vendor_id' => $row->vendor?->vendor_id ?? $row->vendor_id,
                        'vendor_internal_id' => $row->vendor_id,
                        'vendor_name' => $row->vendor?->name,
                        'item_description' => $row->item_description,
                        'unit_price' => (float) $row->unit_price,
                        'quantity' => (float) $row->quantity,
                        'total_price' => (float) $row->total_price,
                        'is_selected' => (bool) $row->is_selected,
                        'selection_reason' => $row->selection_reason,
                    ];
                })->values(),
                'linkedFleetSrfs' => $linkedFleetSrfsPayload,
                ]),
            ],
        ]);
    }

    /**
     * Get progress tracker for MRF (5 phases, document-driven steps, milestone payments).
     */
    public function getProgressTracker(Request $request, $id, MrfProgressTrackerService $tracker)
    {
        $mrf = MRF::where(function ($query) use ($id) {
            $query->where('formatted_id', $id)
                ->orWhere('mrf_id', $id);

            if (is_numeric((string) $id)) {
                $query->orWhere('id', (int) $id);
            }
        })
            ->with([
                'requester',
                'selectedVendor',
                'rfqs' => fn ($query) => $query->withCount('quotations'),
                'approvalHistory',
            ])
            ->first();

        if (! $mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $payload = $tracker->build($mrf);

        return response()->json([
            'success' => true,
            'data' => array_merge($mrf->scmTransactionApiFields(), $payload, [
                'formatted_id' => $mrf->formatted_id,
            ]),
        ]);
    }

    /**
     * Create new MRF
     * Only employees (staff) can create MRF
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

        // Roles that are allowed to author MRF requests directly. Logistics
        // Manager and Logistics Officer can create MRFs for their own
        // department (e.g. fleet parts, workshop consumables). Procurement
        // Manager can originate MRFs when driving PO / procurement workflows.
        // The designated_requisition_creator flag still gates non-logistics
        // employees.
        $logisticsAuthors = ['logistics_manager', 'logistics_officer'];
        $procurementAuthors = ['procurement_manager', 'procurement'];
        $privilegedAuthors = array_merge($logisticsAuthors, $procurementAuthors);
        $isPrivilegedAuthor = in_array($user->scmRole(), $privilegedAuthors, true);
        $isDepartmentEmployee = in_array($user->scmRole(), ['employee', 'staff', 'regular_staff'], true);

        if (!$isPrivilegedAuthor && !$isDepartmentEmployee) {
            return response()->json([
                'success' => false,
                'error' => 'Only designated staff, logistics managers, or procurement managers can create Material Request Forms.',
            ], 403);
        }

        // Department employees still need to be the designated requisition
        // creator. Logistics authors are department-managed: any logistics
        // manager/officer may originate an MRF.
        if ($isDepartmentEmployee && ! $user->designated_requisition_creator) {
            return response()->json([
                'success' => false,
                'error' => 'You are not authorised to create requisition requests for your department.',
            ], 403);
        }

        try {
            RequestLineItemParser::mergeIntoRequest($request);
            $lineItems = RequestLineItemParser::resolve($request);

            // Normalize urgency to proper case
            if ($request->has('urgency') && $request->urgency) {
                $request->merge([
                    'urgency' => ucfirst(strtolower($request->urgency))
                ]);
            }

            $validator = Validator::make($request->all(), array_merge([
                'title' => 'required|string|max:255',
                'category' => 'required|string|max:255',
                'contractType' => 'required|string|max:255',
                'urgency' => 'required|in:Low,Medium,High,Critical',
                'description' => 'required|string',
                'quantity' => 'required|string',
                'estimatedCost' => 'nullable|numeric|min:0',
                'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
                'justification' => 'required|string',
                'department' => 'nullable|string|max:255',
                'pfi' => 'nullable|file|mimes:pdf,doc,docx|max:10240', // Optional PFI upload (10MB max)
                ...AttachmentService::validationRules(),
                'source' => 'nullable|string|in:standard,po_generated',
                'is_po_linked' => 'nullable|boolean',
                'isPoLinked' => 'nullable|boolean',
                'linked_po_id' => 'nullable|string|max:64',
                'linkedPoId' => 'nullable|string|max:64',
                'suppress_notifications' => 'nullable|boolean',
            ], RequestLineItemParser::validationRules(), PaymentMilestoneRequest::validationRules()));

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'code' => 'VALIDATION_ERROR'
                ], 422);
            }

            PaymentMilestoneRequest::mergeIntoRequest($request);
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

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                    'code' => 'UNAUTHENTICATED'
                ], 401);
            }

            // Generate legacy MRF ID (kept for backward compatibility / existing routes)
            $mrfId = MRF::generateMRFId($request->contractType);
            $createdAt = now();

            $formattedId = $this->formattedIdGenerator->generate('MRF', [
                'contract_type' => $request->contractType,
                'department' => $request->department ?? $user->department ?? null,
                'category' => $request->category ?? null,
                'created_at' => $createdAt,
            ]);

            // Handle PFI upload if provided
            $pfiUrl = null;
            $pfiShareUrl = null;

            if ($request->hasFile('pfi')) {
                $pfiFile = $request->file('pfi');

                // Upload to S3 storage
                $disk = $this->getStorageDisk();
                $pfiFileName = "pfi_{$mrfId}_" . time() . "." . $pfiFile->getClientOriginalExtension();
                $pfiPath = "mrfs/" . date('Y/m') . "/{$mrfId}/{$pfiFileName}";

                // Ensure directory structure exists (for S3, this is just the path)
                $directory = dirname($pfiPath);
                if ($disk !== 's3' && !Storage::disk($disk)->exists($directory)) {
                    Storage::disk($disk)->makeDirectory($directory, 0755, true);
                }

                $pfiFile->storeAs($directory, basename($pfiPath), $disk);

                // Get URL (temporary signed URL for S3, public URL for local)
                $pfiUrl = $this->getFileUrl($pfiPath, $disk);
                    $pfiShareUrl = $pfiUrl;
            }

            // Handle supporting attachment upload if provided
            $attachmentUrl = null;
            $attachmentShareUrl = null;
            $attachmentName = null;
            $attachmentService = app(AttachmentService::class);
            $attachmentFiles = $attachmentService->filesFromRequest($request, ['invoice', 'attachment', 'attachments', 'documents']);

            $normalizedContractType = strtolower(trim((string) $request->contractType));

            // Standard Emerald contract types
            $standardContractTypes = ['emerald', 'oando', 'dangote', 'heritage'];
            $isStandardType = in_array($normalizedContractType, $standardContractTypes, true);

            // Parallel first approval: Executive and Supply Chain Director review simultaneously.
            $initialStage = 'parallel_first_approval';
            $initialWorkflowState = WorkflowStateService::STATE_PARALLEL_FIRST_APPROVAL;
            if (! $isStandardType) {
                $routedReason = 'parallel_first_approval_custom';
            } else {
                $routedReason = 'parallel_first_approval';
            }

            $mrfSource = strtolower((string) ($request->input('source') ?? 'standard'));
            if (! in_array($mrfSource, ['standard', 'po_generated'], true)) {
                $mrfSource = 'standard';
            }

            if ($mrfSource === 'standard' && MRF::inferPoGeneratedFromJustification($request->justification)) {
                $mrfSource = 'po_generated';
            }

            $isPoLinked = $request->boolean('is_po_linked')
                || $request->boolean('isPoLinked')
                || $mrfSource === 'po_generated';

            $linkedPoId = trim((string) ($request->input('linked_po_id') ?? $request->input('linkedPoId') ?? ''));

            try {
            $mrf = MRF::create([
                'mrf_id' => $mrfId,
                'formatted_id' => $formattedId,
                'title' => $request->title,
                'category' => $request->category,
                'contract_type' => $request->contractType,
                'routed_reason' => $routedReason,
                'urgency' => $request->urgency,
                'description' => $request->description,
                'quantity' => $request->quantity,
                'estimated_cost' => $request->input('estimatedCost') !== null && $request->input('estimatedCost') !== ''
                    ? (float) $request->input('estimatedCost')
                    : null,
                'currency' => PurchaseOrderCurrency::fromRequest($request) ?? PurchaseOrderCurrency::DEFAULT,
                'justification' => $request->justification,
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'department' => $request->department,
                'date' => $createdAt,
                'status' => 'pending',
                'current_stage' => $initialStage,
                    'workflow_state' => $initialWorkflowState,
                'approval_history' => [],
                'is_resubmission' => false,
                'source' => $mrfSource,
                'is_po_linked' => $isPoLinked,
                'linked_po_id' => $linkedPoId !== '' ? $linkedPoId : null,
                'pfi_url' => $pfiUrl,
                'pfi_share_url' => $pfiShareUrl,
                'attachment_url' => $attachmentUrl,
                'attachment_share_url' => $attachmentShareUrl,
                'attachment_name' => $attachmentName,
            ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a column not found error
                $errorMessage = $e->getMessage();
                if (str_contains($errorMessage, 'contract_type') ||
                    str_contains($errorMessage, 'column') ||
                    str_contains($errorMessage, 'does not exist') ||
                    str_contains($errorMessage, 'Unknown column')) {
                    Log::error('Database column missing - migration may not have been run', [
                        'error' => $errorMessage,
                        'mrf_id' => $mrfId
                    ]);
                    return response()->json([
                        'success' => false,
                        'error' => 'Database schema is not up to date. Please run migrations: php artisan migrate',
                        'code' => 'DATABASE_ERROR',
                        'details' => config('app.debug') ? $errorMessage : null
                    ], 500);
                }
                // Re-throw if it's a different error
                throw $e;
            }

            if ($isPoLinked && ! filled($mrf->linked_po_id)) {
                $mrf->update(['linked_po_id' => $mrf->mrf_id]);
                $mrf->refresh();
            }

            if ($attachmentFiles !== []) {
                $uploadedAttachments = $attachmentService->storeMany($mrf, $attachmentFiles, $user);
                $firstAttachment = $uploadedAttachments->first();

                if ($firstAttachment) {
                    $firstAttachmentPayload = $attachmentService->transform($firstAttachment);
                    $mrf->update([
                        'attachment_url' => $firstAttachmentPayload['downloadUrl'],
                        'attachment_share_url' => $firstAttachmentPayload['downloadUrl'],
                        'attachment_name' => $firstAttachmentPayload['fileName'],
                    ]);
                }
            }

            // Log activity
            try {
                Activity::create([
                    'type' => 'mrf_created',
                    'title' => 'MRF Created',
                    'description' => "MRF {$mrf->mrf_id} was created by {$user->name}",
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'entity_type' => 'mrf',
                    'entity_id' => $mrf->mrf_id,
                    'status' => 'pending',
                ]);
            } catch (\Exception $e) {
                \Log::warning('Failed to log MRF creation activity', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage()
                ]);
            }

            if ($lineItems !== []) {
                app(LineItemBudgetService::class)->syncMrfItems($mrf, $lineItems);
            }

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

            // Log routing decision in approval history if custom contract type
            if (!$isStandardType) {
                try {
                    MRFApprovalHistory::create([
                        'mrf_id' => $mrf->id,
                        'stage' => 'system',
                        'action' => 'auto_routed',
                        'performed_by' => $user->id,
                        'performer_name' => 'System',
                        'performer_role' => 'system',
                        'remarks' => "Auto-routed to parallel first approval (Executive + Supply Chain Director; non-standard contract type: {$normalizedContractType})"
                    ]);

                    Log::info('MRF auto-routed due to custom contract type', [
                        'mrf_id' => $mrf->mrf_id,
                        'contract_type' => $normalizedContractType,
                        'routed_to' => 'supply_chain_director'
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to log auto-routing in approval history', [
                        'mrf_id' => $mrf->mrf_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $suppressNotifications = $request->boolean('suppress_notifications')
                || $mrfSource === 'po_generated';

            if (! $suppressNotifications) {
                // Send notification to Executive
                try {
                    $this->notificationService->notifyMRFSubmitted($mrf);
                } catch (\Exception $e) {
                    // Log notification error but don't fail the request
                    \Log::error('Failed to send MRF notification', [
                        'mrf_id' => $mrf->mrf_id,
                        'error' => $e->getMessage()
                    ]);
                }
                try {
                    $this->workflowNotificationService->notifyMRFSubmitted($mrf);
                } catch (\Exception $e) {
                    \Log::error('Failed to send MRF created email notification', [
                        'event' => 'mrf_created',
                        'recipient' => null,
                        'model_id' => $mrf->mrf_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::info('MRF created notifications suppressed', [
                    'mrf_id' => $mrf->mrf_id,
                    'source' => $mrfSource,
                ]);
            }

            $mrf->load('attachments.uploader:id,name,email');
            $attachments = $attachmentService->payloadFor($mrf);

            return response()->json([
                'success' => true,
                'data' => array_merge($mrf->scmTransactionApiFields(), [
                'id' => $mrf->mrf_id,
                'formattedId' => $mrf->formatted_id,
                'formatted_id' => $mrf->formatted_id,
                'legacyId' => $mrf->mrf_id,
                'legacy_id' => $mrf->mrf_id,
                'title' => $mrf->title,
                'category' => $mrf->category,
                    'contractType' => $mrf->contract_type,
                'urgency' => $mrf->urgency,
                'description' => $mrf->description,
                'quantity' => $mrf->quantity,
                'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
                ...$mrf->currencyApiFields(),
                'justification' => $mrf->justification,
                'requester' => $mrf->requester_name,
                'requesterId' => (string) $mrf->requester_id,
                'department' => $mrf->department,
                'date' => $mrf->date->format('Y-m-d'),
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                    'workflowState' => $mrf->workflow_state,
                'approvalHistory' => $mrf->approval_history ?? [],
                'rejectionReason' => $mrf->rejection_reason,
                'isResubmission' => $mrf->is_resubmission,
                    'pfiUrl' => $mrf->pfi_url,
                    'pfiShareUrl' => $mrf->pfi_share_url,
                    'attachmentUrl' => $mrf->attachment_url,
                    'attachmentShareUrl' => $mrf->attachment_share_url,
                    'attachmentName' => $mrf->attachment_name,
                    'attachments' => $attachments,
                    'documents' => $attachments,
                    'payment_milestones' => app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf),
                    'paymentMilestones' => app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf),
                ]),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('MRF creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create MRF',
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the MRF',
                'code' => 'SERVER_ERROR'
            ], 500);
        }

    }

    /**
     * Update existing MRF
     * Staff can only edit their own MRF before submission (workflow_state = mrf_created)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $requesterEditService = app(RequesterEditWindowService::class);
        $editCheck = $requesterEditService->evaluateMrfEdit($user, $mrf);
        if (! $editCheck['allowed']) {
            return response()->json([
                'success' => false,
                'error' => $editCheck['message'],
                'code' => $editCheck['code'],
            ], 403);
        }

        $before = $mrf->only([
            'title', 'category', 'urgency', 'description', 'quantity',
            'estimated_cost', 'justification', 'department',
        ]);

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
            'category' => 'sometimes|required|string|max:255',
            'urgency' => 'sometimes|required|in:Low,Medium,High,Critical',
            'description' => 'sometimes|required|string',
            'quantity' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|nullable|numeric|min:0',
            'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
            'justification' => 'sometimes|required|string',
            'department' => 'sometimes|nullable|string|max:255',
            'remarks' => 'sometimes|nullable|string|max:1000',
            ...AttachmentService::validationRules(),
        ], RequestLineItemParser::validationRules(), PaymentMilestoneRequest::validationRules()));

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

        $attachmentService = app(AttachmentService::class);
        $attachmentFiles = $attachmentService->filesFromRequest($request, ['invoice', 'attachment', 'attachments', 'documents']);

        $updateData = [];
        if ($request->has('title')) $updateData['title'] = $request->title;
        if ($request->has('category')) $updateData['category'] = $request->category;
        if ($request->has('urgency')) $updateData['urgency'] = $request->urgency;
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('department')) $updateData['department'] = $request->department;
        if ($request->has('estimatedCost')) {
            $updateData['estimated_cost'] = $request->input('estimatedCost') === null || $request->input('estimatedCost') === ''
                ? null
                : (float) $request->input('estimatedCost');
        }
        if ($request->has('currency')) {
            $updateData['currency'] = PurchaseOrderCurrency::normalize($request->input('currency'));
        }
        if ($request->has('justification')) $updateData['justification'] = $request->justification;

        $mrf->update($updateData);

        if ($request->has('items') || $request->has('line_items')) {
            app(LineItemBudgetService::class)->syncMrfItems($mrf, $lineItems);
        }

        if (PaymentMilestoneRequest::provided($request)) {
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

        if ($attachmentFiles !== []) {
            $uploadedAttachments = $attachmentService->storeMany($mrf, $attachmentFiles, $user);
            $firstAttachment = $uploadedAttachments->first();

            if ($firstAttachment) {
                $firstAttachmentPayload = $attachmentService->transform($firstAttachment);
                $mrf->update([
                    'attachment_url' => $firstAttachmentPayload['downloadUrl'],
                    'attachment_share_url' => $firstAttachmentPayload['downloadUrl'],
                    'attachment_name' => $firstAttachmentPayload['fileName'],
                ]);
            }
        }

        $mrf->refresh();
        $mrf->load('items', 'attachments.uploader:id,name,email');

        $changedFields = $requesterEditService->detectChangedFieldLabels(
            $before,
            $mrf->only(array_keys($before)),
            [
                'title' => 'title',
                'category' => 'category',
                'urgency' => 'urgency',
                'description' => 'description',
                'quantity' => 'quantity',
                'estimated cost' => 'estimated_cost',
                'justification' => 'justification',
                'department' => 'department',
            ]
        );

        if ($request->has('items') || $request->has('line_items')) {
            $changedFields[] = 'line items';
        }

        if (PaymentMilestoneRequest::provided($request)) {
            $changedFields[] = 'payment milestones';
        }

        if ($attachmentFiles !== []) {
            $changedFields[] = 'attachments';
        }

        $remarks = $request->input('remarks');
        $changeSummary = $requesterEditService->summarizeChangedFields($changedFields);
        $requesterEditService->recordMrfEdit($mrf, $user, $remarks, $changedFields);
        $this->notificationService->notifyMRFRequesterUpdated($mrf, $user, $changeSummary);

        $requesterEditMeta = $requesterEditService->metaForMrf($user, $mrf);
        $attachments = $attachmentService->payloadFor($mrf);

        return response()->json(array_merge([
            'id' => $mrf->mrf_id,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'urgency' => $mrf->urgency,
            'description' => $mrf->description,
            'quantity' => $mrf->quantity,
            'estimatedCost' => $mrf->estimated_cost !== null ? (float) $mrf->estimated_cost : null,
            ...$mrf->currencyApiFields(),
            'justification' => $mrf->justification,
            'requester' => $mrf->requester_name,
            'requesterId' => (string) $mrf->requester_id,
            'date' => $mrf->date->format('Y-m-d'),
            'status' => $mrf->status,
            'currentStage' => $mrf->current_stage,
            'approvalHistory' => $mrf->approval_history ?? [],
            'rejectionReason' => $mrf->rejection_reason,
            'isResubmission' => $mrf->is_resubmission,
            'items' => $mrf->items->map(fn ($item) => [
                'id' => $item->id,
                'itemName' => $item->item_name,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
                'budget_amount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            ])->values(),
            'line_items' => $mrf->items->map(fn ($item) => [
                'id' => $item->id,
                'itemName' => $item->item_name,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'budgetAmount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
                'budget_amount' => $item->budget_amount !== null ? (float) $item->budget_amount : null,
            ])->values(),
            'payment_milestones' => app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf),
            'paymentMilestones' => app(PaymentScheduleService::class)->paymentMilestonesForMrf($mrf),
            'attachmentUrl' => $mrf->attachment_url,
            'attachmentShareUrl' => $mrf->attachment_share_url,
            'attachmentName' => $mrf->attachment_name,
            'attachments' => $attachments,
            'documents' => $attachments,
            'approvalHistory' => $mrf->approval_history ?? [],
        ], $requesterEditMeta));
    }

    /**
     * Approve MRF
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();

        // Check if user has permission (procurement or finance role)
        if (!in_array($user->scmRole(), ['procurement', 'finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if ($mrf->status !== 'Pending') {
            return response()->json([
                'success' => false,
                'error' => 'MRF is not in Pending status',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'approved_by' => $user->name,
            'approved_by_id' => $user->id,
            'approved_at' => now()->toIso8601String(),
            'remarks' => $request->remarks,
            'stage' => $mrf->current_stage,
        ];

        $mrf->update([
            'status' => 'Approved',
            'current_stage' => 'finance', // Move to next stage
            'approval_history' => $approvalHistory,
            'remarks' => $request->remarks,
        ]);

        // Send notification to requester
        $this->notificationService->notifyMRFApproved($mrf, $user, $request->remarks);
        try {
            $mrf->loadMissing('requester');
            $this->workflowNotificationService->notifyMRFApproved($mrf);
        } catch (\Exception $e) {
            \Log::error('Failed to send MRF approved email notification', [
                'event' => 'mrf_approved',
                'recipient' => $mrf->requester?->email,
                'model_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'MRF approved successfully',
            'mrf' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
            ]
        ]);
    }

    /**
     * Reject MRF
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();

        // Check if user has permission
        if (!in_array($user->scmRole(), ['procurement', 'finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $mrf->update([
            'status' => 'Rejected',
            'rejection_reason' => $request->reason,
        ]);

        // Send notification to requester
        $this->notificationService->notifyMRFRejected($mrf, $user, $request->reason);
        try {
            $mrf->loadMissing('requester');
            $this->workflowNotificationService->notifyMRFRejected($mrf, $request->reason);
        } catch (\Exception $e) {
            \Log::error('Failed to send MRF rejected email notification', [
                'event' => 'mrf_rejected',
                'recipient' => $mrf->requester?->email,
                'model_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'MRF rejected',
            'mrf' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'rejectionReason' => $mrf->rejection_reason,
            ]
        ]);
    }

    /**
     * Generate PO for approved MRF (Procurement Manager)
     */
    public function generatePO(Request $request, $id)
    {
        $user = $request->user();

        // Check if user has procurement permission
        if (!in_array($user->scmRole(), ['procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Procurement Managers can generate POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'po_number' => 'required|string|max:255',
            'unsigned_po' => 'required|file|mimes:pdf,doc,docx|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Handle file upload
        $unsignedPoPath = null;
        if ($request->hasFile('unsigned_po')) {
            $file = $request->file('unsigned_po');
            $fileName = time() . '_' . $file->getClientOriginalName();

            // Store in public disk (configured to storage/app/public)
            $disk = config('filesystems.documents_disk', 'public');
            $path = $file->storeAs('documents/pos', $fileName, $disk);
            $unsignedPoPath = $path;

            Log::info('PO file uploaded', [
                'mrf_id' => $id,
                'file_name' => $fileName,
                'path' => $path,
                'disk' => $disk
            ]);
        }

        // Update MRF with PO details
        $mrf->update([
            'po_number' => $request->po_number,
            'unsigned_po_url' => $unsignedPoPath,
            'status' => 'PO Generated',
            'current_stage' => 'supply_chain',
        ]);

        // Add to approval history
        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'action' => 'PO Generated',
            'by' => $user->name,
            'by_id' => $user->id,
            'at' => now()->toIso8601String(),
            'po_number' => $request->po_number,
        ];
        $mrf->approval_history = $approvalHistory;
        $mrf->save();

        // Notify Supply Chain Director
        try {
            $this->notificationService->notifyPOGenerated($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send PO generation notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Purchase Order generated successfully',
            'data' => [
                'id' => $mrf->mrf_id,
                'poNumber' => $mrf->po_number,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'unsignedPoUrl' => $unsignedPoPath,
            ]
        ], 200);
    }

    /**
     * Upload Signed PO (Supply Chain Director)
     */
    public function uploadSignedPO(Request $request, $id)
    {
        $user = $request->user();

        // Check if user has supply chain permission
        if (!in_array($user->scmRole(), ['supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can upload signed POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'signed_po' => 'required|file|mimes:pdf|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Handle file upload
        $signedPoPath = null;
        if ($request->hasFile('signed_po')) {
            $file = $request->file('signed_po');
            $fileName = time() . '_signed_' . $file->getClientOriginalName();

            $disk = config('filesystems.documents_disk', 'public');
            $path = $file->storeAs('documents/pos/signed', $fileName, $disk);
            $signedPoPath = $path;

            Log::info('Signed PO uploaded', [
                'mrf_id' => $id,
                'file_name' => $fileName,
                'path' => $path
            ]);
        }

        // Update MRF
        $mrf->update([
            'signed_po_url' => $signedPoPath,
            'status' => 'PO Signed',
            'current_stage' => 'finance',
        ]);

        // Add to approval history
        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'action' => 'PO Signed',
            'by' => $user->name,
            'by_id' => $user->id,
            'at' => now()->toIso8601String(),
        ];
        $mrf->approval_history = $approvalHistory;
        $mrf->save();

        // Notify Finance team
        try {
            $this->notificationService->notifyPOSigned($mrf, $user);
        } catch (\Exception $e) {
            Log::error('Failed to send PO signed notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Signed PO uploaded successfully',
            'data' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'signedPoUrl' => $signedPoPath,
            ]
        ]);
    }

    /**
     * Reject PO (Supply Chain Director)
     */
    public function rejectPO(Request $request, $id)
    {
        $user = $request->user();

        // Check if user has supply chain permission
        if (!in_array($user->scmRole(), ['supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can reject POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update MRF - send back to procurement
        $mrf->update([
            'status' => 'PO Rejected',
            'current_stage' => 'procurement',
            'rejection_reason' => $request->reason,
        ]);

        // Add to approval history
        $approvalHistory = $mrf->approval_history ?? [];
        $approvalHistory[] = [
            'action' => 'PO Rejected',
            'by' => $user->name,
            'by_id' => $user->id,
            'at' => now()->toIso8601String(),
            'reason' => $request->reason,
        ];
        $mrf->approval_history = $approvalHistory;
        $mrf->save();

        // Notify Procurement
        try {
            $this->notificationService->notifyPORejected($mrf, $user, $request->reason);
        } catch (\Exception $e) {
            Log::error('Failed to send PO rejection notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'PO rejected successfully',
            'data' => [
                'id' => $mrf->mrf_id,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'rejectionReason' => $mrf->rejection_reason,
            ]
        ]);
    }

    /**
     * Delete MRF
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Normalize status to lowercase for comparison
        $statusLower = strtolower(trim($mrf->status ?? ''));
        $currentStageLower = strtolower(trim($mrf->current_stage ?? ''));

        // Check if user is the requester
        $isRequester = $mrf->requester_id == $user->id;

        // Check if user is a procurement manager or admin (admin can always delete)
        $isAdmin = $user->scmRole() === 'admin';
        $isProcurementManager = in_array($user->scmRole(), ['procurement_manager', 'procurement', 'admin']);

        // Admin can always delete any MRF (force delete capability)
        if ($isAdmin) {
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($mrf) {
                    $mrf->delete();
                });

                Log::info('MRF force deleted by admin', [
                    'mrf_id' => $id,
                    'deleted_by' => $user->id,
                    'status' => $mrf->status,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'MRF deleted successfully (admin override)',
                ]);
            } catch (\Exception $e) {
                Log::error('MRF deletion failed', [
                    'mrf_id' => $id,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Failed to delete MRF: ' . $e->getMessage(),
                    'code' => 'DELETE_FAILED'
                ], 500);
            }
        }

        // For non-admin users, check deletion permissions
        $canDelete = false;

        // Check if MRF has PO or is too far in workflow
        // Only count signed PO as "too far" - unsigned PO can be deleted
        $hasSignedPO = !empty(trim($mrf->signed_po_url ?? ''));
        $hasUnsignedPO = !empty(trim($mrf->po_number ?? '')) || !empty(trim($mrf->unsigned_po_url ?? ''));
        $tooFarInWorkflow = in_array($statusLower, ['finance', 'paid', 'completed', 'chairman_payment']);

        // Procurement managers can delete MRFs in supply_chain if PO is not signed yet
        // This allows them to delete MRFs that are stuck in supply_chain
        if ($isProcurementManager) {
            // Can delete if:
            // 1. No PO at all, OR
            // 2. Has unsigned PO but not signed (supply_chain status), OR
            // 3. Is in early stages (pending, procurement, rejected)
            $isSupplyChain = in_array($statusLower, ['supply_chain', 'supply chain']) ||
                           in_array($currentStageLower, ['supply_chain', 'supply chain']);

            if (!$hasSignedPO) {
                // No signed PO - can delete if:
                // - No PO at all, OR
                // - Has unsigned PO and is in supply_chain (can delete and regenerate), OR
                // - Is in early stages
                if (!$hasUnsignedPO || $isSupplyChain ||
                    in_array($statusLower, ['pending', 'procurement', 'rejected', 'executive approval', 'executive_review', 'chairman_review'])) {
                    $canDelete = true;
                }
            }
        }

        if ($isRequester && !$canDelete) {
            // Requester can delete if no PO has been generated and not too far in workflow
            if (!$hasUnsignedPO && !$tooFarInWorkflow) {
                $canDelete = true;
            }
        }

        // Also allow deletion for pending/rejected statuses (original logic)
        if (!$canDelete && in_array($statusLower, ['pending', 'rejected'])) {
            $canDelete = true;
        }

        if (!$canDelete) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete MRF. Either you are not authorized, or the MRF has progressed too far in the workflow (PO generated or beyond procurement stage).',
                'code' => 'FORBIDDEN',
                'details' => [
                    'has_po' => $hasUnsignedPO || $hasSignedPO,
                    'status' => $mrf->status,
                    'current_stage' => $mrf->current_stage,
                    'is_requester' => $isRequester,
                    'is_procurement_manager' => $isProcurementManager
                ]
            ], 403);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($mrf) {
                $mrf->delete();
            });

            Log::info('MRF deleted', [
                'mrf_id' => $id,
                'deleted_by' => $user->id,
                'status' => $mrf->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'MRF deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('MRF deletion failed', [
                'mrf_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete MRF: ' . $e->getMessage(),
                'code' => 'DELETE_FAILED'
            ], 500);
        }
    }

    /**
     * Download unsigned PO PDF (authenticated API).
     */
    public function downloadPO(Request $request, $id)
    {
        return $this->streamUnsignedPOPdfResponse($id, true);
    }

    /**
     * Same PDF as downloadPO, reachable via temporary signed URL (e.g. opened from unsignedPoUrl in the browser).
     */
    public function downloadPOBySignedLink(Request $request, string $id)
    {
        return $this->streamUnsignedPOPdfResponse($id, false);
    }

    /**
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    private function streamUnsignedPOPdfResponse(string $id, bool $asAttachment)
    {
        $startedAt = microtime(true);
        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        if (empty($mrf->unsigned_po_url) || empty($mrf->po_number)) {
            return response()->json([
                'success' => false,
                'error' => 'PO not generated yet',
                'code' => 'NO_PO',
            ], 404);
        }

        try {
            // Always render from the canonical Emerald layout — never serve a
            // previously stored PDF blob, which may use a deprecated layout.
            $genStarted = microtime(true);
            $pdfContent = $this->generatePOPDFFromMRF($mrf);
            $source = 'emerald_regenerated';
            Log::info('PO download: Emerald PDF rendered', [
                'mrf_id' => $mrf->mrf_id,
                'pdf_ms' => (int) round((microtime(true) - $genStarted) * 1000),
            ]);

            try {
                $disk = $this->getStorageDisk();
                $poPath = 'purchase-orders/'.date('Y/m').'/po_'.$mrf->po_number.'_emerald_v2_'.time().'.pdf';
                Storage::disk($disk)->put($poPath, $pdfContent);
                $freshUrl = $this->getFileUrl($poPath, $disk);
                if (! empty($freshUrl)) {
                    $mrf->update([
                        'unsigned_po_url' => $freshUrl,
                        'unsigned_po_share_url' => $freshUrl,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('PO download: failed to refresh Emerald PDF on storage', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $filenameBase = $mrf->formatted_id ?: $mrf->po_number;
            $filename = "PO-{$filenameBase}.pdf";
            $disposition = $asAttachment ? 'attachment' : 'inline';

            Log::info('PO download completed', [
                'mrf_id' => $mrf->mrf_id,
                'source' => $source,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'bytes' => is_string($pdfContent) ? strlen($pdfContent) : 0,
            ]);

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', $disposition . '; filename="' . $filename . '"')
                ->header('X-PO-Source', $source)
                ->header('X-PO-Layout', 'emerald');
        } catch (\Exception $e) {
            Log::error('Failed to stream unsigned PO PDF', [
                'mrf_id' => $id,
                'error' => $e->getMessage(),
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate PO PDF: ' . $e->getMessage(),
                'code' => 'PDF_GENERATION_FAILED',
            ], 500);
        }
    }

    public function executiveReject(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $allowedRoles = ['executive', 'chairman', 'admin'];

        if (!in_array($user->scmRole(), $allowedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to reject this MRF.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $mrf = is_numeric($id)
            ? MRF::find($id)
            : MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'message' => 'MRF not found.'
            ], 404);
        }

        if ($mrf->workflow_state !== 'executive_review'
            && $mrf->workflow_state !== WorkflowStateService::STATE_PARALLEL_FIRST_APPROVAL) {
            return response()->json([
                'success' => false,
                'message' => 'Only MRFs awaiting executive or parallel first approval can be rejected by Executive.'
            ], 422);
        }

        $mrf->workflow_state = 'executive_rejected';
        $mrf->status = 'rejected';
        $mrf->current_stage = 'executive_rejected';
        $mrf->rejection_reason = $request->reason;
        $mrf->rejection_comments = $request->reason;
        $mrf->rejected_by = $user->id;
        $mrf->rejected_at = now();
        $mrf->executive_approved = false;
        $mrf->executive_approved_by = null;
        $mrf->executive_approved_at = null;
        $mrf->executive_remarks = $request->reason;
        $mrf->last_action_by_role = in_array($user->scmRole(), ['admin']) ? 'admin' : 'executive';

        $mrf->save();

        try {
            $mrf->load('requester');
            $this->notificationService->notifyMRFRejected($mrf, $user, $request->reason);
            $this->workflowNotificationService->notifyMRFRejected($mrf, $request->reason);
        } catch (\Exception $e) {
            \Log::error('Failed to send MRF rejected notification', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'MRF rejected successfully.',
            'data' => $mrf
        ]);
    }

    public function resubmit(Request $request, $id)
    {
        $user = $request->user();

        $mrf = is_numeric($id)
            ? MRF::find($id)
            : MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'message' => 'MRF not found'
            ], 404);
        }

        if ($mrf->status !== 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Only rejected MRFs can be resubmitted'
            ], 400);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string',
            'description' => 'sometimes|string',
            'quantity' => 'sometimes|integer|min:1',
            'estimated_cost' => 'sometimes|nullable|numeric|min:0',
            'justification' => 'sometimes|string',
            'category' => 'sometimes|string',
        ]);

        $mrf->fill($validated);

        $mrf->rejection_reason = null;
        $mrf->rejection_comments = null;
        $mrf->rejected_by = null;
        $mrf->rejected_at = null;

        $mrf->is_resubmission = true;

        // Resubmit into parallel first approval (Executive + Supply Chain Director)
        $mrf->workflow_state = WorkflowStateService::STATE_PARALLEL_FIRST_APPROVAL;
        $mrf->current_stage = 'parallel_first_approval';
        $normalizedContractType = strtolower(trim((string) $mrf->contract_type));
        $standardContractTypes = ['emerald', 'oando', 'dangote', 'heritage'];
        $isStandardType = in_array($normalizedContractType, $standardContractTypes, true);
        $mrf->routed_reason = $isStandardType ? 'parallel_first_approval' : 'parallel_first_approval_custom';
        $mrf->first_approval_by_role = null;

        $mrf->status = 'pending';

        $mrf->save();

        return response()->json([
            'success' => true,
            'message' => 'MRF resubmitted successfully',
            'data' => $mrf
        ]);
    }

    public function supplyChainDirectorReject(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $allowedRoles = ['supply_chain_director', 'director', 'admin'];

        if (!in_array($user->scmRole(), $allowedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to reject this MRF.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $mrf = is_numeric($id)
            ? MRF::find($id)
            : MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'message' => 'MRF not found.'
            ], 404);
        }

        if ($mrf->workflow_state !== 'supply_chain_director_review'
            && $mrf->workflow_state !== WorkflowStateService::STATE_PARALLEL_FIRST_APPROVAL) {
            return response()->json([
                'success' => false,
                'message' => 'Only MRFs awaiting Supply Chain Director or parallel first approval can be rejected.'
            ], 422);
        }

        $mrf->workflow_state = 'supply_chain_director_rejected';
        $mrf->status = 'rejected';
        $mrf->current_stage = 'rejected';
        $mrf->rejection_reason = $request->reason;
        $mrf->rejection_comments = $request->reason;
        $mrf->rejected_by = $user->id;
        $mrf->rejected_at = now();
        $mrf->remarks = $request->reason;
        $mrf->last_action_by_role = in_array($user->scmRole(), ['admin']) ? 'admin' : 'supply_chain_director';

        $mrf->save();

        return response()->json([
            'success' => true,
            'message' => 'MRF rejected successfully.',
            'data' => $mrf
        ]);
    }
    /**
     * Download signed PO PDF
     */
    public function downloadSignedPO(Request $request, $id)
    {
        $mrf = $this->findMrfByAnyId((string) $id);

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if (empty($mrf->signed_po_url)) {
            return response()->json([
                'success' => false,
                'error' => 'Signed PO not available',
                'code' => 'NO_SIGNED_PO'
            ], 404);
        }

        try {
            $disk = $this->getStorageDisk();

            // Extract file path using our helper method
            $filePath = $this->extractFilePathFromUrl($mrf->signed_po_url);

            if (empty($filePath)) {
                throw new \Exception('Could not extract file path from stored URL');
            }

            // Try the extracted path
            if (!Storage::disk($disk)->exists($filePath)) {
                // Try alternative paths
                $alternatives = [
                    'purchase-orders/signed/' . basename($filePath),
                    ltrim($filePath, '/'),
                    'purchase-orders/signed/' . ltrim($filePath, 'purchase-orders/signed/')
                ];

                $found = false;
                foreach ($alternatives as $alt) {
                    if (Storage::disk($disk)->exists($alt)) {
                        $filePath = $alt;
                        $found = true;
                        Log::info('Found signed PO at alternative path', [
                            'mrf_id' => $id,
                            'alternative_path' => $alt
                        ]);
                        break;
                    }
                }

                if (!$found) {
                    Log::error('Signed PO file not found in S3', [
                        'mrf_id' => $id,
                        'expected_path' => $filePath,
                        'tried_alternatives' => $alternatives,
                        'stored_url' => $mrf->signed_po_url
                    ]);
                    throw new \Exception('Signed PO file not found in storage. File may have been deleted or path is incorrect.');
                }
            }

            $pdfContent = Storage::disk($disk)->get($filePath);
            $filename = "PO_Signed_{$mrf->po_number}.pdf";

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        } catch (\Exception $e) {
            Log::error('Failed to download signed PO', [
                'mrf_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to download signed PO: ' . $e->getMessage(),
                'code' => 'DOWNLOAD_FAILED'
            ], 500);
        }
    }

    /**
     * Generate PO PDF from MRF data (Emerald layout: logo left, two-column info, green line table, signatures).
     */
    private function generatePOPDFFromMRF(MRF $mrf): string
    {
        $mrf->load([
            'requester:id,name,email',
            'items:id,mrf_id,item_name,description,quantity,unit,unit_price,total_price',
            'selectedVendor:id,vendor_id,name,email,phone,address,contact_person,contact_person_email',
            'priceComparisons:id,purchase_order_id,vendor_id,item_description,unit_price,quantity,total_price,is_selected',
            'priceComparisons.vendor:id,vendor_id,name,email,phone,address,contact_person',
            'paymentSchedule.milestones',
        ]);

        $rfq = RFQ::where('mrf_id', $mrf->id)->first();
        if ($rfq) {
            [$items, $currency, $paymentTerms, $vendorPdf] = $this->resolveUnsignedPoStreamContextFromRfq($mrf, $rfq);
        } else {
            [$items, $currency, $paymentTerms, $vendorPdf] = $this->resolveUnsignedPoStreamContextWithoutRfq($mrf);
        }

        if ($items->isEmpty()) {
            throw new \Exception('No items found for PO generation');
        }

        $company = [
            'name'    => env('COMPANY_NAME', 'Emerald Industrial Co. FZE'),
            'address' => env('COMPANY_ADDRESS', 'Plot A10, Calabar Free Trade Zone, Calabar, Cross River 540001 NG'),
            'email'   => env('COMPANY_EMAIL', 'info@emeraldcfze.com'),
            'phone'   => env('COMPANY_PHONE', ''),
            'tax_id'  => env('COMPANY_TAX_ID', ''),
            'website' => env('COMPANY_WEBSITE', 'https://emeraldcfze.com/'),
        ];

        $shipToAddress = $mrf->ship_to_address
            ?: config('app.ship_to_address', 'Emerald Industrial Co. FZE');

        $poDate = $mrf->po_generated_at ? \Carbon\Carbon::parse($mrf->po_generated_at)->format('d/m/Y') : now()->format('d/m/Y');

        $subtotal = 0;
        foreach ($items as $item) {
            $unitPrice = $item->unit_price ?? ($item->total_price ?? 0) / ($item->quantity ?? 1);
            $itemTotal = ($unitPrice * ($item->quantity ?? 1));
            $subtotal += $itemTotal;
        }

        $taxRate = $mrf->tax_rate ?? 0;
        $tax = $mrf->tax_amount ?? 0;

        if ($tax == 0 && $taxRate > 0) {
            $tax = ($subtotal * $taxRate) / 100;
        }

        $total = $subtotal + $tax;

        $paymentTerms = $mrf->po_payment_terms
            ?: $paymentTerms
            ?: 'Net 30 days';

        return app(PurchaseOrderPdfService::class)->renderMrfPdf([
            'po_number' => $mrf->po_number,
            'po_date' => $poDate,
            'company' => $company,
            'vendor' => $vendorPdf,
            'ship_to' => $shipToAddress,
            'items' => $items,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxRate,
            'total' => $total,
            'currency' => $currency,
            'payment_terms' => $paymentTerms,
            'invoice_submission_email' => $mrf->invoice_submission_email ?? config('scm.invoice_submission_email'),
            'invoice_submission_cc' => \App\Support\PurchaseOrderInvoiceCc::merge(
                $mrf->invoice_submission_cc,
                \App\Support\PurchaseOrderInvoiceCc::defaultCc(),
            ),
            'special_terms' => $mrf->po_special_terms,
            'custom_terms' => $mrf->custom_terms,
            'po_terms_mode' => $mrf->po_terms_mode,
            'po_type' => $mrf->po_type ?: 'goods',
            'contract_type' => $mrf->contract_type,
            'mrf_category' => $mrf->category,
            'mrf_department' => $mrf->department,
            'mrf_display_id' => $mrf->formatted_id ?: $mrf->mrf_id,
            'approved_by_name' => PurchaseOrderPdfService::EMERALD_PO_APPROVER_NAME,
        ]);
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: string, 2: string, 3: array<string, string>}
     */
    private function resolveUnsignedPoStreamContextFromRfq(MRF $mrf, RFQ $rfq): array
    {
        $mrf->loadMissing('priceComparisons.vendor');
        $poLineService = app(\App\Services\PriceComparisonPoLineService::class);
        $selectedComparisonRows = $poLineService->selectedSupplierRows($mrf);

        $quotation = null;
        if ($rfq->selected_quotation_id) {
            $quotation = \App\Models\Quotation::where('id', $rfq->selected_quotation_id)
                ->with(['vendor'])
                ->first();
        }

        if (!$quotation) {
            $quotation = \App\Models\Quotation::where('rfq_id', $rfq->id)
                ->where('status', 'Approved')
                ->with(['vendor'])
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($selectedComparisonRows->isNotEmpty()) {
            $vendor = $poLineService->resolveVendorFromRows($selectedComparisonRows);
            if (!$vendor && $quotation?->vendor) {
                $vendor = $quotation->vendor;
            }
            if (!$vendor) {
                throw new \Exception('No vendor found for the selected price comparison supplier');
            }
            $items = $poLineService->rowsToPoLineObjects($selectedComparisonRows);
        } else {
            if (!$quotation || !$quotation->vendor) {
                throw new \Exception('No approved quotation found for this MRF');
            }

            $vendor = $quotation->vendor;

            $items = \App\Models\QuotationItem::where('quotation_id', $quotation->id)->get();

            if ($items->isEmpty()) {
                $rfq->load('items');
                $items = $rfq->items;
            }

            if ($items->isEmpty()) {
                $items = $mrf->items;
            }
        }

        $vendorAddress = (string) ($vendor->address ?? '');
        $vendorPdf = [
            'name' => $vendor->vendor_name ?? $vendor->name ?? 'N/A',
            'address' => $vendorAddress,
            'contact_person' => $vendor->contact_person ?? '',
            'phone' => $vendor->phone ?? '',
            'email' => $vendor->email ?? '',
            'tax_id' => $vendor->tax_id ?? '',
        ];

        $currency = $quotation?->currency ?? $mrf->currency ?? 'NGN';
        $paymentTerms = $quotation?->payment_terms ?? '30days after invoice submission.';

        return [$items, $currency, $paymentTerms, $vendorPdf];
    }

    /**
     * Same source order as {@see \App\Http\Controllers\Api\MRFWorkflowController::buildSyntheticPoPayload}
     * so on-the-fly PO PDF matches POs generated with fast_track / allow_missing_rfq.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: string, 2: string, 3: array<string, string>}
     */
    private function resolveUnsignedPoStreamContextWithoutRfq(MRF $mrf): array
    {
        $vendorModel = null;
        if ($mrf->selected_vendor_id) {
            $vendorModel = Vendor::query()->find($mrf->selected_vendor_id);
        }

        $mrf->loadMissing('priceComparisons.vendor');
        $poLineService = app(\App\Services\PriceComparisonPoLineService::class);
        $selectedComparisonRows = $poLineService->selectedSupplierRows($mrf);

        $items = collect();
        if ($selectedComparisonRows->isNotEmpty()) {
            $comparisonVendor = $poLineService->resolveVendorFromRows($selectedComparisonRows);
            if ($comparisonVendor) {
                $vendorModel = $comparisonVendor;
            }
            $items = $poLineService->rowsToPoLineObjects($selectedComparisonRows);
        } else {
            $rows = $mrf->priceComparisons()->orderByDesc('is_selected')->orderBy('id')->get();
            if ($vendorModel && $rows->isNotEmpty()) {
                $forVendor = $rows->where('vendor_id', $vendorModel->id)->values();
                if ($forVendor->isNotEmpty()) {
                    $rows = $forVendor;
                }
            } elseif (! $vendorModel && $rows->isNotEmpty()) {
                $firstVid = $rows->first()->vendor_id;
                $vendorModel = Vendor::query()->find($firstVid);
                $rows = $rows->where('vendor_id', $firstVid)->values();
            }

            if ($rows->isNotEmpty()) {
                $items = $poLineService->rowsToPoLineObjects($rows);
            }
        }

        if ($items->isEmpty()) {
            foreach ($mrf->items as $it) {
                $items->push((object) [
                    'item_name' => $it->item_name ?? 'Item',
                    'description' => $it->description ?? '',
                    'quantity' => max(1, (int) ($it->quantity ?? 1)),
                    'unit' => $it->unit ?? 'unit',
                    'unit_price' => (float) ($it->unit_price ?? 0),
                    'total_price' => (float) ($it->total_price ?? (($it->unit_price ?? 0) * max(1, $it->quantity ?? 1))),
                    'specifications' => $it->specifications ?? '',
                ]);
            }
        }

        if ($items->isEmpty()) {
            $qty = max(1, (int) ($mrf->quantity ?? 1));
            $est = (float) ($mrf->estimated_cost ?? 0);
            $unit = $qty > 0 ? $est / $qty : $est;
            $items->push((object) [
                'item_name' => $mrf->title ?: 'Goods / services',
                'description' => (string) ($mrf->description ?? ''),
                'quantity' => $qty,
                'unit' => 'unit',
                'unit_price' => $unit,
                'total_price' => $est,
                'specifications' => '',
            ]);
        }

        $currency = (string) ($mrf->currency ?? 'NGN');
        $paymentTerms = '30 days after invoice submission.';

        if ($vendorModel) {
            $vendorPdf = [
                'name' => (string) ($vendorModel->vendor_name ?? $vendorModel->name ?? 'N/A'),
                'address' => (string) ($vendorModel->address ?? ''),
                'contact_person' => (string) ($vendorModel->contact_person ?? ''),
                'phone' => (string) ($vendorModel->phone ?? ''),
                'email' => (string) ($vendorModel->email ?? ''),
                'tax_id' => (string) ($vendorModel->tax_id ?? ''),
            ];
        } else {
            $vendorPdf = [
                'name' => 'Supplier (pending confirmation)',
                'address' => '',
                'contact_person' => '',
                'phone' => '',
                'email' => '',
                'tax_id' => '',
            ];
        }

        Log::info('Unsigned PO PDF: built context without RFQ row', [
            'mrf_numeric_id' => $mrf->id,
            'mrf_display_id' => $mrf->mrf_id,
            'line_count' => $items->count(),
        ]);

        return [$items, $currency, $paymentTerms, $vendorPdf];
    }

    private function renderPurchaseOrderDompdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', public_path());
        $options->set('pdfBackend', 'CPDF');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

}
