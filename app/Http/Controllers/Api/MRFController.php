<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\Activity;
use App\Services\NotificationService;
use App\Services\WorkflowStateService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Dompdf\Dompdf;
use Dompdf\Options;

class MRFController extends Controller
{
    protected NotificationService $notificationService;
    protected WorkflowStateService $workflowService;
    protected PermissionService $permissionService;

    public function __construct(
        NotificationService $notificationService,
        WorkflowStateService $workflowService,
        PermissionService $permissionService
    ) {
        $this->notificationService = $notificationService;
        $this->workflowService = $workflowService;
        $this->permissionService = $permissionService;
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
     */
    protected function getFileUrl(string $filePath, string $disk, int $expirationHours = 24): string
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
     * Get all MRFs with optional filters
     */
    public function index(Request $request)
    {
        try {
        $query = MRF::with('requester');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search in title/description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sortBy', 'date');
        $sortOrder = $request->get('sortOrder', 'desc');
        
        $allowedSortFields = ['date', 'estimated_cost', 'title', 'status', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('date', 'desc');
        }

        // Filter by requester (for employees to see only their own)
        $user = $request->user();
        
        // If user is a vendor, they typically don't need direct access to MRFs
        // But allow access and return empty array or MRFs related to their RFQs
        $isVendor = false;
        if ($user && ($user->role === 'vendor' || (method_exists($user, 'hasRole') && $user->hasRole('vendor')))) {
            $isVendor = true;
            // Vendors can see MRFs that are linked to RFQs assigned to them
            // For now, return empty array - vendors should access MRFs through RFQs
            return response()->json([]);
        }
        
        if ($user && in_array($user->role, ['employee', 'general_employee'])) {
            $query->where('requester_id', $user->id);
        }

        $mrfs = $query->get();

        return response()->json($mrfs->map(function($mrf) {
            return [
                'id' => $mrf->mrf_id,
                'title' => $mrf->title,
                'category' => $mrf->category,
                'contractType' => $mrf->contract_type,
                'urgency' => $mrf->urgency,
                'description' => $mrf->description,
                'quantity' => $mrf->quantity,
                'estimatedCost' => (float) $mrf->estimated_cost,
                'justification' => $mrf->justification,
                'requester' => $mrf->requester_name,
                'requesterId' => (string) $mrf->requester_id,
                'department' => $mrf->department,
                'date' => $mrf->date ? $mrf->date->format('Y-m-d') : null,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'workflowState' => $mrf->workflow_state,
                'approvalHistory' => $mrf->approval_history ?? [],
                'rejectionReason' => $mrf->rejection_reason,
                'isResubmission' => $mrf->is_resubmission,
                'pfiUrl' => $mrf->pfi_url,
                'pfiShareUrl' => $mrf->pfi_share_url,
                'grnRequested' => $mrf->grn_requested,
                'grnRequestedAt' => $mrf->grn_requested_at?->toIso8601String(),
                'grnCompleted' => $mrf->grn_completed,
                'grnCompletedAt' => $mrf->grn_completed_at?->toIso8601String(),
                'grnUrl' => $mrf->grn_url,
                'grnShareUrl' => $mrf->grn_share_url,
                'executive_approved' => $mrf->executive_approved ?? false,
                'executive_approved_at' => $mrf->executive_approved_at?->toIso8601String(),
                'executive_remarks' => $mrf->executive_remarks,
                'scd_approved_by' => $mrf->director_approved_by ?? $mrf->scd_approved_by ?? $mrf->supply_chain_approved_by ?? null,
                'scd_approved_at' => ($mrf->scd_approved_at ?? $mrf->director_approved_at ?? $mrf->supply_chain_approved_at)?->toIso8601String(),
                'scd_remarks' => $mrf->scd_remarks ?? $mrf->director_remarks ?? $mrf->supply_chain_remarks ?? $mrf->remarks ?? null,
                'chairman_approved' => $mrf->chairman_approved ?? false,
                'chairman_approved_at' => $mrf->chairman_approved_at?->toIso8601String(),
                'chairman_remarks' => $mrf->chairman_remarks,
                // PO information (both formats)
                'po_number' => $mrf->po_number,
                'poNumber' => $mrf->po_number,
                'unsigned_po_url' => $mrf->unsigned_po_url,
                'unsignedPOUrl' => $mrf->unsigned_po_url,
                'unsigned_po_share_url' => $mrf->unsigned_po_share_url,
                'unsignedPOShareUrl' => $mrf->unsigned_po_share_url,
                'signed_po_url' => $mrf->signed_po_url,
                'signedPOUrl' => $mrf->signed_po_url,
                'po_generated_at' => $mrf->po_generated_at?->toIso8601String(),
                'poGeneratedAt' => $mrf->po_generated_at?->toIso8601String(),
            ];
        }));
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
            
            // Return empty array if it's a column error (migration not run yet)
            if ($isColumnError) {
                Log::warning('MRF index: Missing database columns detected. Migration may need to be run.', [
                    'error' => $errorMessage,
                ]);
                return response()->json([]);
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
        $mrf = MRF::where('mrf_id', $id)->first();

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
    public function show($id)
    {
        $mrf = MRF::where('mrf_id', $id)->with(['requester', 'directorApprover'])->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'id' => $mrf->mrf_id,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'contractType' => $mrf->contract_type,
            'urgency' => $mrf->urgency,
            'description' => $mrf->description,
            'quantity' => $mrf->quantity,
            'estimatedCost' => (float) $mrf->estimated_cost,
            'justification' => $mrf->justification,
            'requester' => $mrf->requester_name,
            'requesterId' => (string) $mrf->requester_id,
            'department' => $mrf->department,
            'date' => $mrf->date ? $mrf->date->format('Y-m-d') : null,
            'status' => $mrf->status,
            'currentStage' => $mrf->current_stage,
            'workflowState' => $mrf->workflow_state,
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
            'unsigned_po_url' => $mrf->unsigned_po_url,
            'unsignedPoUrl' => $mrf->unsigned_po_url,
            'unsigned_po_share_url' => $mrf->unsigned_po_share_url,
            'unsignedPoShareUrl' => $mrf->unsigned_po_share_url,
            'signed_po_url' => $mrf->signed_po_url,
            'signedPoUrl' => $mrf->signed_po_url,
            'signed_po_share_url' => $mrf->signed_po_share_url,
            'signedPoShareUrl' => $mrf->signed_po_share_url,
            'po_generated_at' => $mrf->po_generated_at?->toIso8601String(),
            'poGeneratedAt' => $mrf->po_generated_at?->toIso8601String(),
            'po_signed_at' => $mrf->po_signed_at?->toIso8601String(),
            'poSignedAt' => $mrf->po_signed_at?->toIso8601String(),
        ]);
    }

    /**
     * Get full MRF details with all quotations (for procurement managers)
     * Provides end-to-end visibility including all vendor quotations
     */
    public function getFullDetails(Request $request, $id)
    {
        $user = $request->user();

        // Check role - procurement managers and above
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'supply_chain_director', 'supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)
            ->with([
                'requester',
                'executiveApprover',
                'chairmanApprover',
                'selectedVendor',
                'rfqs.quotations.vendor',
                'rfqs.quotations.items.rfqItem',
                'rfqs.selectedQuotation.vendor',
                'rfqs.selectedQuotation.items.rfqItem',
                'rfqs.vendors',
                'items',
            ])
            ->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get all RFQs for this MRF
        $rfqs = $mrf->rfqs;
        
        // Collect all quotations from all RFQs
        // Exclude rejected quotations from active view (they remain accessible for historical tracking)
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
                    'attachments' => $quotation->attachments ?? [],
                    'status' => $quotation->status,
                    'reviewStatus' => $quotation->review_status ?? 'pending',
                    'submittedAt' => $quotation->submitted_at ? $quotation->submitted_at->toIso8601String() : null,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mrf' => [
                    'id' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'contractType' => $mrf->contract_type,
                    'urgency' => $mrf->urgency,
                    'description' => $mrf->description,
                    'quantity' => $mrf->quantity,
                    'estimatedCost' => (float) $mrf->estimated_cost,
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
                ],
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
                        'attachments' => $selectedQuotation->attachments ?? [], // All uploaded documents
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
            ],
        ]);
    }

    /**
     * Get progress tracker for MRF
     * Shows the complete workflow sequence with status
     */
    public function getProgressTracker(Request $request, $id)
    {
        $mrf = MRF::where('mrf_id', $id)
            ->with(['requester', 'selectedVendor', 'rfqs'])
            ->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $isEmeraldContract = strtolower(trim((string) $mrf->contract_type)) === 'emerald';

        // Determine step statuses based on workflow state
        $steps = [
            [
                'step' => 1,
                'name' => 'MRF Created by Employee',
                'status' => 'completed',
                'completedAt' => $mrf->created_at ? $mrf->created_at->toIso8601String() : null,
                'completedBy' => $mrf->requester ? [
                    'id' => $mrf->requester->id,
                    'name' => $mrf->requester->name,
                ] : null,
                'description' => 'General employee submitted Material Request Form',
            ],
            [
                'step' => 2,
                'name' => $isEmeraldContract ? 'Executive Approval (bunmi.babajide@emeraldcfze.com)' : 'Supply Chain Director Initial Approval',
                'status' => $isEmeraldContract
                    ? (
                        in_array($mrf->workflow_state, ['executive_rejected']) || strtolower($mrf->status ?? '') === 'rejected'
                            ? 'rejected'
                            : (
                                $mrf->workflow_state === 'executive_review'
                                    ? 'pending'
                                    : (
                                        in_array($mrf->workflow_state, [
                                            'executive_approved',
                                            'procurement_review',
                                            'procurement_approved',
                                            'rfq_issued',
                                            'quotations_received',
                                            'quotations_evaluated',
                                            'vendor_selected',
                                            'invoice_approved',
                                            'po_generated',
                                            'po_signed',
                                            'closed'
                                        ]) ? 'completed' : 'not_started'
                                    )
                            )
                    )
                    : ($mrf->workflow_state === 'supply_chain_director_review' ? 'pending' :
                        (in_array($mrf->workflow_state, ['supply_chain_director_approved', 'procurement_review', 'procurement_approved', 'rfq_issued', 'quotations_received', 'quotations_evaluated', 'vendor_selected', 'invoice_approved', 'po_generated', 'po_signed', 'closed']) ? 'completed' : 'not_started')),
                        
                'completedAt' => null,
                'description' => $isEmeraldContract
                    ? 'Executive performs first approval for Emerald contract MRFs'
                    : 'Supply Chain Director performs first approval for non-Emerald contract MRFs',
            ],
            [
                'step' => 3,
                'name' => 'Procurement Manager Sources Quotations',
                'status' => in_array($mrf->workflow_state, ['procurement_review', 'procurement_approved', 'rfq_issued', 'quotations_received']) ? 'pending' : 
                           (in_array($mrf->workflow_state, ['quotations_evaluated', 'vendor_selected', 'invoice_approved', 'po_generated', 'po_signed', 'closed']) ? 'completed' : 'not_started'),
                'description' => 'Procurement manager sources and evaluates onboarded vendor quotations',
            ],
            [
                'step' => 4,
                'name' => 'RFQ Issued to Vendors',
                'status' => $mrf->rfqs()->exists() ? 'completed' : 
                           ($mrf->workflow_state === 'rfq_issued' ? 'pending' : 'not_started'),
                'completedAt' => $mrf->rfqs()->exists() ? $mrf->rfqs()->first()->created_at->toIso8601String() : null,
                'rfqCount' => $mrf->rfqs()->count(),
                'description' => 'Requests for Quotation sent to identified vendors',
            ],
            [
                'step' => 5,
                'name' => 'Supply Chain Director Final Quote Approval',
                'status' => in_array($mrf->workflow_state, ['invoice_approved', 'po_generated', 'po_signed', 'closed']) ? 'completed' : 
                           ($mrf->workflow_state === 'vendor_selected' ? 'pending' : 'not_started'),
                'quotationCount' => $mrf->quotations()->count(),
                'description' => 'Selected vendor/quotation is submitted for final Supply Chain Director approval',
            ],
            [
                'step' => 6,
                'name' => 'Purchase Order Generated',
                'status' => $mrf->po_number ? 'completed' : 
                           ($mrf->workflow_state === 'po_generated' ? 'pending' : 'not_started'),
                'completedAt' => $mrf->po_generated_at ? $mrf->po_generated_at->toIso8601String() : null,
                'poNumber' => $mrf->po_number,
                'description' => 'PO created from selected quotation',
            ],
            [
                'step' => 7,
                'name' => 'Process Complete',
                'status' => in_array($mrf->workflow_state, ['po_signed', 'closed']) ? 'completed' : 'not_started',
                'completedAt' => $mrf->po_signed_at ? $mrf->po_signed_at->toIso8601String() : null,
                'description' => 'MRF process ends after PO creation',
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'mrfId' => $mrf->mrf_id,
                'title' => $mrf->title,
                'currentStep' => collect($steps)->where('status', 'pending')->first()['step'] ?? 
                                 (collect($steps)->where('status', 'completed')->last()['step'] ?? 1),
                'steps' => $steps,
                'currentWorkflowState' => $mrf->workflow_state,
            ],
        ]);
    }

    /**
     * Create new MRF
     * Only employees (staff) can create MRF
     */
    public function store(Request $request)
    {
        // Only employees can create MRF
        $user = $request->user();
        if (!$user || $user->role !== 'employee') {
            return response()->json([
                'success' => false,
                'error' => 'Only staff members can create Material Request Forms. Please contact your administrator.',
            ], 403);
        }

        try {
            // Normalize urgency to proper case
            if ($request->has('urgency') && $request->urgency) {
                $request->merge([
                    'urgency' => ucfirst(strtolower($request->urgency))
                ]);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'category' => 'required|string|max:255',
                'contractType' => 'required|string|max:255',
                'urgency' => 'required|in:Low,Medium,High,Critical',
                'description' => 'required|string',
                'quantity' => 'required|string',
                'estimatedCost' => 'required|numeric|min:0',
                'justification' => 'required|string',
                'department' => 'nullable|string|max:255',
                'pfi' => 'nullable|file|mimes:pdf,doc,docx|max:10240', // Optional PFI upload (10MB max)
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

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                    'code' => 'UNAUTHENTICATED'
                ], 401);
            }

            // Generate MRF ID with contract type
            $mrfId = MRF::generateMRFId($request->contractType);
            
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

            $normalizedContractType = strtolower(trim((string) $request->contractType));
            $isEmeraldContract = $normalizedContractType === 'emerald';
            $initialStage = $isEmeraldContract ? 'executive_review' : 'supply_chain_director_review';
            $initialWorkflowState = $isEmeraldContract
                ? WorkflowStateService::STATE_EXECUTIVE_REVIEW
                : WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW;

            try {
            $mrf = MRF::create([
                'mrf_id' => $mrfId,
                'title' => $request->title,
                'category' => $request->category,
                'contract_type' => $request->contractType,
                'urgency' => $request->urgency,
                'description' => $request->description,
                'quantity' => $request->quantity,
                'estimated_cost' => $request->estimatedCost,
                'justification' => $request->justification,
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'department' => $request->department,
                'date' => now(),
                'status' => 'pending',
                'current_stage' => $initialStage,
                    'workflow_state' => $initialWorkflowState,
                'approval_history' => [],
                'is_resubmission' => false,
                'pfi_url' => $pfiUrl,
                'pfi_share_url' => $pfiShareUrl,
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

            return response()->json([
                'success' => true,
                'data' => [
                'id' => $mrf->mrf_id,
                'title' => $mrf->title,
                'category' => $mrf->category,
                    'contractType' => $mrf->contract_type,
                'urgency' => $mrf->urgency,
                'description' => $mrf->description,
                'quantity' => $mrf->quantity,
                'estimatedCost' => (float) $mrf->estimated_cost,
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
                ]
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
        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if user can edit this MRF
        if (!$this->permissionService->canEditMRF($user, $mrf)) {
            return response()->json([
                'success' => false,
                'error' => 'You cannot edit this MRF. MRFs cannot be edited after submission.',
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
            'category' => 'sometimes|required|string|max:255',
            'urgency' => 'sometimes|required|in:Low,Medium,High,Critical',
            'description' => 'sometimes|required|string',
            'quantity' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|required|numeric|min:0',
            'justification' => 'sometimes|required|string',
            'department' => 'sometimes|nullable|string|max:255',
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
        if ($request->has('category')) $updateData['category'] = $request->category;
        if ($request->has('urgency')) $updateData['urgency'] = $request->urgency;
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('department')) $updateData['department'] = $request->department;
        if ($request->has('estimatedCost')) $updateData['estimated_cost'] = $request->estimatedCost;
        if ($request->has('justification')) $updateData['justification'] = $request->justification;

        // If updating from Rejected, reset status to Pending
        if ($mrf->status === 'Rejected') {
            $updateData['status'] = 'Pending';
            $updateData['rejection_reason'] = null;
            $updateData['is_resubmission'] = true;
        }

        $mrf->update($updateData);
        $mrf->refresh();

        return response()->json([
            'id' => $mrf->mrf_id,
            'title' => $mrf->title,
            'category' => $mrf->category,
            'urgency' => $mrf->urgency,
            'description' => $mrf->description,
            'quantity' => $mrf->quantity,
            'estimatedCost' => (float) $mrf->estimated_cost,
            'justification' => $mrf->justification,
            'requester' => $mrf->requester_name,
            'requesterId' => (string) $mrf->requester_id,
            'date' => $mrf->date->format('Y-m-d'),
            'status' => $mrf->status,
            'currentStage' => $mrf->current_stage,
            'approvalHistory' => $mrf->approval_history ?? [],
            'rejectionReason' => $mrf->rejection_reason,
            'isResubmission' => $mrf->is_resubmission,
        ]);
    }

    /**
     * Approve MRF
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user has permission (procurement or finance role)
        if (!in_array($user->role, ['procurement', 'finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

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
        if (!in_array($user->role, ['procurement', 'finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

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
        if (!in_array($user->role, ['procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Procurement Managers can generate POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

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
        if (!in_array($user->role, ['supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can upload signed POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

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
        if (!in_array($user->role, ['supply_chain', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Supply Chain Directors can reject POs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $mrf = MRF::where('mrf_id', $id)->first();

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
        $mrf = MRF::where('mrf_id', $id)->first();

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
        $isAdmin = $user->role === 'admin';
        $isProcurementManager = in_array($user->role, ['procurement_manager', 'procurement', 'admin']);
        
        // Admin can always delete any MRF (force delete capability)
        if ($isAdmin) {
            try {
                // Delete related records first (cascade should handle this, but let's be explicit)
                $mrf->rfqs()->delete();
                $mrf->items()->delete();
                $mrf->approvalHistory()->delete();

        $mrf->delete();
                
                Log::info('MRF force deleted by admin', [
                    'mrf_id' => $id,
                    'deleted_by' => $user->id,
                    'status' => $mrf->status
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'MRF deleted successfully (admin override)'
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
                    'has_po' => $hasPO,
                    'status' => $mrf->status,
                    'current_stage' => $mrf->current_stage,
                    'is_requester' => $isRequester,
                    'is_procurement_manager' => $isProcurementManager
                ]
            ], 403);
        }

        try {
            $mrf->delete();
            
            Log::info('MRF deleted', [
                'mrf_id' => $id,
                'deleted_by' => $user->id,
                'status' => $mrf->status
            ]);

        return response()->json([
            'success' => true,
            'message' => 'MRF deleted successfully'
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
     * Download unsigned PO PDF
     */
    public function downloadPO(Request $request, $id)
    {
        $mrf = MRF::where('mrf_id', $id)->first();

        if (!$mrf) {
            return response()->json([
                'success' => false,
                'error' => 'MRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if (empty($mrf->unsigned_po_url) || empty($mrf->po_number)) {
            return response()->json([
                'success' => false,
                'error' => 'PO not generated yet',
                'code' => 'NO_PO'
            ], 404);
        }

        try {
            // Generate PDF on-the-fly from MRF data
            $pdfContent = $this->generatePOPDFFromMRF($mrf);
            
            $filename = "PO_{$mrf->po_number}.pdf";
            
            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        } catch (\Exception $e) {
            Log::error('Failed to download PO', [
                'mrf_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate PO PDF: ' . $e->getMessage(),
                'code' => 'PDF_GENERATION_FAILED'
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

        if (!in_array($user->role, $allowedRoles)) {
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

        if ($mrf->workflow_state !== 'executive_review') {
            return response()->json([
                'success' => false,
                'message' => 'Only MRFs in executive_review can be rejected by Executive.'
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
        $mrf->last_action_by_role = in_array($user->role, ['admin']) ? 'admin' : 'executive';

        $mrf->save();

        try {
            $mrf->load('requester');
            $this->notificationService->notifyMRFRejected($mrf, $request->remarks ?? null);
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
            'estimated_cost' => 'sometimes|numeric|min:0',
            'justification' => 'sometimes|string',
            'category' => 'sometimes|string',
        ]);
    
        $mrf->fill($validated);
    
        $mrf->rejection_reason = null;
        $mrf->rejection_comments = null;
        $mrf->rejected_by = null;
        $mrf->rejected_at = null;
    
        $mrf->is_resubmission = true;
    
        if (strtolower(trim((string) $mrf->contract_type)) === 'emerald') {
            $mrf->workflow_state = 'executive_review';
            $mrf->current_stage = 'executive_review';
        } else {
            $mrf->workflow_state = 'supply_chain_director_review';
            $mrf->current_stage = 'supply_chain_director_review';
        }
    
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

        if (!in_array($user->role, $allowedRoles)) {
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

        if ($mrf->workflow_state !== 'supply_chain_director_review') {
            return response()->json([
                'success' => false,
                'message' => 'Only MRFs in supply_chain_director_review can be rejected by Supply Chain Director.'
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
        $mrf->last_action_by_role = in_array($user->role, ['admin']) ? 'admin' : 'supply_chain_director';

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
        $mrf = MRF::where('mrf_id', $id)->first();

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
            $urlPath = parse_url($mrf->signed_po_url, PHP_URL_PATH);
            
            // Try to extract file path
            $baseUrl = Storage::disk($disk)->url('');
            $filePath = str_replace($baseUrl, '', $mrf->signed_po_url);
            $filePath = ltrim(str_replace('/storage/', '', $filePath), '/');
            
            if (empty($filePath) || !Storage::disk($disk)->exists($filePath)) {
                // Try alternative path extraction
                $filePath = ltrim($urlPath, '/');
                if (!Storage::disk($disk)->exists($filePath)) {
                    throw new \Exception('Signed PO file not found in storage');
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
     * Generate PO PDF from MRF data (matches example format)
     */
    private function generatePOPDFFromMRF(MRF $mrf): string
    {
        // Load relationships
        $mrf->load(['requester', 'items']);
        
        // Get RFQ and quotation data
        $rfq = \App\Models\RFQ::where('mrf_id', $mrf->mrf_id)->first();
        if (!$rfq) {
            throw new \Exception('RFQ not found for this MRF');
        }

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

        if (!$quotation || !$quotation->vendor) {
            throw new \Exception('No approved quotation found for this MRF');
        }

        $vendor = $quotation->vendor;
        
        // Get items from quotation_items, RFQ items, or MRF items
        $items = \App\Models\QuotationItem::where('quotation_id', $quotation->id)->get();
        
        if ($items->isEmpty()) {
            $rfq->load('items');
            $items = $rfq->items;
        }
        
        if ($items->isEmpty()) {
            $items = $mrf->items;
        }

        if ($items->isEmpty()) {
            throw new \Exception('No items found for PO generation');
        }

        // Company information (from config or default)
        $company = [
            'name' => config('app.company_name', 'Emerald Industrial Co. FZE'),
            'address' => config('app.company_address', 'Plot A10, Calabar Free Trade Zone, Calabar, Cross River 540001 NG'),
            'email' => config('app.company_email', 'temitope.lawal@emeraldcfze.com'),
            'website' => config('app.company_website', 'https://emeraldcfze.com/'),
        ];

        // Ship to address (use MRF field or fallback to config)
        $shipToAddress = $mrf->ship_to_address ?? config('app.ship_to_address', 'Sapetro Towers, Victoria Island, Lagos, Lagos 100001 NGA');

        // Format date
        $poDate = $mrf->po_generated_at ? \Carbon\Carbon::parse($mrf->po_generated_at)->format('d/m/Y') : now()->format('d/m/Y');
        
        // Build vendor address
        $vendorAddress = '';
        if (!empty($vendor->address)) {
            $vendorAddress = $vendor->address;
        }

        // Calculate totals
        $subtotal = 0;
        $currency = $quotation->currency ?? 'NGN';

        foreach ($items as $item) {
            $unitPrice = $item->unit_price ?? ($item->total_price ?? 0) / ($item->quantity ?? 1);
            $itemTotal = ($unitPrice * ($item->quantity ?? 1));
            $subtotal += $itemTotal;
        }

        // Calculate tax (use MRF tax_amount if set, otherwise calculate from tax_rate)
        $taxRate = $mrf->tax_rate ?? 0;
        $tax = $mrf->tax_amount ?? 0;
        
        // If tax_amount is not set but tax_rate is, calculate it
        if ($tax == 0 && $taxRate > 0) {
            $tax = ($subtotal * $taxRate) / 100;
        }

        $total = $subtotal + $tax;

        // Build HTML template matching example format
        $html = $this->buildPOPDFHTML([
            'po_number' => $mrf->po_number,
            'po_date' => $poDate,
            'company' => $company,
            'vendor' => [
                'name' => $vendor->vendor_name ?? $vendor->name ?? 'N/A',
                'address' => $vendorAddress,
            ],
            'ship_to' => $shipToAddress,
            'items' => $items,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate' => $taxRate,
            'total' => $total,
            'currency' => $currency,
            'payment_terms' => $quotation->payment_terms ?? '30days after invoice submission.',
            'invoice_submission_email' => $mrf->invoice_submission_email ?? 'accountpayables@emeraldcfze.com',
            'invoice_submission_cc' => $mrf->invoice_submission_cc ?? 'douglas.anuforo@emeraldcfze.com',
            'special_terms' => $mrf->po_special_terms,
        ]);

        // Generate PDF using dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Build PO PDF HTML template matching example format
     */
    private function buildPOPDFHTML(array $data): string
    {
        $poNumber = $data['po_number'];
        $poDate = $data['po_date'];
        $company = $data['company'];
        $vendor = $data['vendor'];
        $shipTo = $data['ship_to'];
        $items = $data['items'];
        $subtotal = $data['subtotal'];
        $tax = $data['tax'];
        $taxRate = $data['tax_rate'] ?? 0;
        $total = $data['total'];
        $currency = $data['currency'];
        $paymentTerms = $data['payment_terms'];
        $invoiceEmail = $data['invoice_submission_email'] ?? 'accountpayables@emeraldcfze.com';
        $invoiceCC = $data['invoice_submission_cc'] ?? 'douglas.anuforo@emeraldcfze.com';
        $specialTerms = $data['special_terms'];

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - ' . htmlspecialchars($poNumber) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            font-size: 11px; 
            line-height: 1.4;
            color: #000;
            padding: 20px;
        }
        .header { 
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        .company-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .company-info {
            flex: 1;
        }
        .company-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .company-details {
            font-size: 10px;
            line-height: 1.5;
        }
        .logo-container {
            width: 120px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        .logo-container img {
            max-width: 120px;
            max-height: 60px;
            object-fit: contain;
        }
        .logo-placeholder {
            width: 120px;
            height: 60px;
            border: 1px solid #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: #666;
        }
        .po-title {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin: 20px 0;
        }
        .po-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .po-detail-item {
            flex: 1;
        }
        .po-detail-label {
            font-weight: bold;
            margin-bottom: 3px;
        }
        .supplier-section, .ship-to-section {
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #ddd;
        }
        .section-title {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 8px;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 10px;
        }
        .items-table th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px 5px;
            text-align: left;
            font-weight: bold;
        }
        .items-table td {
            border: 1px solid #000;
            padding: 8px 5px;
        }
        .items-table .text-right {
            text-align: right;
        }
        .items-table .text-center {
            text-align: center;
        }
        .summary-section {
            margin-top: 20px;
            margin-left: auto;
            width: 300px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #ddd;
        }
        .summary-row.total {
            font-weight: bold;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 8px 0;
            margin-top: 5px;
        }
        .terms-section {
            margin-top: 30px;
            font-size: 10px;
        }
        .terms-title {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 12px;
        }
        .terms-list {
            margin-left: 20px;
            margin-top: 10px;
        }
        .terms-list li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-header">
            <div class="company-info">
                <div class="company-name">' . htmlspecialchars($company['name']) . '</div>
                <div class="company-details">
                    ' . nl2br(htmlspecialchars($company['address'])) . '<br>
                    Email: ' . htmlspecialchars($company['email']) . '<br>
                    Website: ' . htmlspecialchars($company['website']) . '
                </div>
            </div>
            ' . $this->getLogoHTML() . '
        </div>
    </div>

    <div class="po-title">Purchase Order</div>

    <div class="po-details">
        <div class="po-detail-item">
            <div class="po-detail-label">P.O. NO.:</div>
            <div>' . htmlspecialchars($poNumber) . '</div>
        </div>
        <div class="po-detail-item">
            <div class="po-detail-label">DATE:</div>
            <div>' . htmlspecialchars($poDate) . '</div>
        </div>
    </div>

    <div class="supplier-section">
        <div class="section-title">SUPPLIER:</div>
        <div style="font-weight: bold; margin-bottom: 5px;">' . htmlspecialchars($vendor['name']) . '</div>
        <div>' . nl2br(htmlspecialchars($vendor['address'] ?? '')) . '</div>
    </div>

    <div class="ship-to-section">
        <div class="section-title">SHIP TO:</div>
        <div style="font-weight: bold; margin-bottom: 5px;">' . htmlspecialchars($company['name']) . '</div>
        <div>' . nl2br(htmlspecialchars($shipTo)) . '</div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>DESCRIPTION</th>
                <th class="text-center">QTY</th>
                <th class="text-right">RATE</th>
                <th class="text-center">TAX</th>
                <th class="text-right">AMOUNT</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($items as $item) {
            $itemName = $item->item_name ?? $item->name ?? 'Item';
            $description = $item->description ?? $item->specifications ?? '';
            $quantity = $item->quantity ?? 1;
            $unitPrice = $item->unit_price ?? ($item->total_price ?? 0) / $quantity;
            $itemTotal = $unitPrice * $quantity;
            
            // Display tax for item (if tax_rate > 0, show percentage, otherwise "No VAT")
            $taxDisplay = $taxRate > 0 ? number_format($taxRate, 2) . '%' : 'No VAT';

            $html .= '<tr>
                <td>
                    <strong>' . htmlspecialchars($itemName) . '</strong><br>
                    <div style="font-size: 9px; color: #666;">' . nl2br(htmlspecialchars($description)) . '</div>
                </td>
                <td class="text-center">' . $quantity . '</td>
                <td class="text-right">' . number_format($unitPrice, 2) . '</td>
                <td class="text-center">' . $taxDisplay . '</td>
                <td class="text-right">' . number_format($itemTotal, 2) . '</td>
            </tr>';
        }

        $html .= '</tbody>
    </table>

    <div class="summary-section">
        <div class="summary-row">
            <span>SUBTOTAL:</span>
            <span>' . number_format($subtotal, 2) . '</span>
        </div>
        <div class="summary-row">
            <span>TAX:</span>
            <span>' . number_format($tax, 2) . '</span>
        </div>
        <div class="summary-row total">
            <span>TOTAL:</span>
            <span>' . $currency . ' ' . number_format($total, 2) . '</span>
        </div>
    </div>

    <div class="terms-section">
        <div class="terms-title">Payment Terms:</div>
        <div>' . htmlspecialchars($paymentTerms) . '</div>

        <div class="terms-title" style="margin-top: 15px;">Invoice Submission:</div>
        <div>Please upon delivery, sign off your delivery note with the warehouse, attach you invoice and a copy of your PO and submit all to this email: ' . htmlspecialchars($invoiceEmail) . ($invoiceCC ? ' cc: ' . htmlspecialchars($invoiceCC) : '') . '</div>

        <div class="terms-title" style="margin-top: 15px;">SPECIAL NOTES, TERMS OF SALE:</div>';
        
        // Use custom terms if provided, otherwise use default
        if (!empty($specialTerms)) {
            $html .= '<div>' . nl2br(htmlspecialchars($specialTerms)) . '</div>';
        } else {
            $html .= '<ol class="terms-list">
            <li>All items must be high quality, brand new and according to the specification. Anything less may trigger rejection.</li>
            <li>All packages must be clearly marked</li>
            <li>All content of the packages must be clearly marked with item number, material number, description of the item, Manufacturer\'s part number and quantity.</li>
            <li>Small items with the same part numbers must be tagged and packed together in a plastic bag or box. The tag shall also be shown on the outside of the bag or box.</li>
            <li>Items must be packed in a sturdy case to withstand handling.</li>
            <li>Items delivery must be accompanied by Airway Bill, Invoice and Delivery Note all duly signed by Emerald representative at the site.</li>
        </ol>';
        }
        
        $html .= '
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Get the HTML for the company logo in PDF
     */
    private function getLogoHTML(): string
    {
        // Try multiple possible logo locations
        $logoPaths = [
            public_path('images/logo.png'),
            public_path('images/logo.jpg'),
            public_path('images/company-logo.png'),
            public_path('images/company-logo.jpg'),
            public_path('images/emerald-logo.png'),
            public_path('images/emerald-logo.jpg'),
        ];

        foreach ($logoPaths as $logoPath) {
            if (file_exists($logoPath)) {
                // Convert to base64 data URI for PDF embedding
                $imageData = file_get_contents($logoPath);
                $imageInfo = getimagesize($logoPath);
                $mimeType = $imageInfo['mime'] ?? 'image/png';
                $base64 = base64_encode($imageData);
                $dataUri = 'data:' . $mimeType . ';base64,' . $base64;
                
                return '<div class="logo-container">
                    <img src="' . $dataUri . '" alt="Company Logo" />
                </div>';
            }
        }

        // Fallback: show placeholder
        return '<div class="logo-placeholder">LOGO</div>';
    }
}
