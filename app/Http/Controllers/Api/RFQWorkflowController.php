<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\RFQ;
use App\Models\RFQItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Vendor;
use App\Services\NotificationService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class RFQWorkflowController extends Controller
{
    protected NotificationService $notificationService;
    protected EmailService $emailService;

    public function __construct(NotificationService $notificationService, EmailService $emailService)
    {
        $this->notificationService = $notificationService;
        $this->emailService = $emailService;
    }

    /**
     * Get RFQs for vendor portal (vendor-specific view)
     * Returns only RFQs where the logged-in vendor is associated via the RFQ-vendor relationship
     */
    public function getVendorRFQs(Request $request)
    {
        $user = $request->user();
        
        // Verify user is a vendor - check both direct role field and Spatie roles
        $isVendor = false;
        if ($user->role === 'vendor') {
            $isVendor = true;
        } elseif (method_exists($user, 'hasRole') && $user->hasRole('vendor')) {
            $isVendor = true;
        }

        if (!$isVendor) {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }
        
        // Get vendor from authenticated user - try multiple methods
        $vendor = null;
        
        // Method 1: Try vendor relationship
        if ($user->vendor_id && method_exists($user, 'vendor')) {
            $vendor = $user->vendor;
        }
        
        // Method 2: Find vendor by vendor_id if relationship didn't work
        if (!$vendor && $user->vendor_id) {
            $vendor = Vendor::find($user->vendor_id);
        }
        
        // Method 3: Try finding vendor by email as last resort
        if (!$vendor) {
            $vendor = Vendor::where('email', $user->email)->first();
        }
        
        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found. Please ensure your account is linked to a vendor.',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get RFQs assigned to this vendor via the many-to-many relationship
        // Using whereHas ensures we only get RFQs where this vendor is in the rfq_vendors pivot table
        $rfqs = RFQ::whereHas('vendors', function ($query) use ($vendor) {
            $query->where('vendors.id', $vendor->id);
        })
        ->with([
            'items',
            'mrf',
            'vendors' => function ($query) use ($vendor) {
                // Only load the current vendor's pivot data for efficiency
                $query->where('vendors.id', $vendor->id);
            }
        ])
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($rfq) use ($vendor) {
            // Get pivot data for this vendor (should be loaded from the relationship)
            $vendorPivot = $rfq->vendors->firstWhere('id', $vendor->id);
            $pivot = $vendorPivot ? $vendorPivot->pivot : null;
            
            // Check if vendor has submitted quotation
            $hasSubmitted = Quotation::where('rfq_id', $rfq->id)
                ->where('vendor_id', $vendor->id)
                ->exists();

            // Get estimated cost with fallback to MRF's estimated_cost
            // Cast to float to ensure proper numeric handling
            $estimatedCost = $rfq->estimated_cost ? (float) $rfq->estimated_cost : null;

            // If RFQ's estimated_cost is null, 0, or not set, use MRF's estimated_cost
            if (!$estimatedCost || $estimatedCost == 0) {
                $estimatedCost = $rfq->mrf && $rfq->mrf->estimated_cost
                    ? (float) $rfq->mrf->estimated_cost
                    : 0;
            }

            return [
                'id' => $rfq->rfq_id,
                'mrf_id' => $rfq->mrf_id ? ($rfq->mrf ? $rfq->mrf->mrf_id : null) : null,
                'title' => $rfq->title ?? $rfq->mrf_title ?? $rfq->description,
                'category' => $rfq->category ?? ($rfq->mrf ? $rfq->mrf->category : null),
                'description' => $rfq->description,
                'quantity' => $rfq->quantity,
                'estimatedCost' => (float) $estimatedCost,
                'budget' => (float) $estimatedCost, // Alias for estimatedCost for frontend compatibility
                'paymentTerms' => $rfq->payment_terms,
                'notes' => $rfq->notes,
                'supportingDocuments' => $rfq->supporting_documents ?? [],
                'deadline' => $rfq->deadline ? $rfq->deadline->format('Y-m-d') : null,
                'status' => $rfq->status,
                'workflowState' => $rfq->workflow_state,
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
                'sent_at' => $pivot?->sent_at ? $pivot->sent_at->toIso8601String() : null,
                'viewed_at' => $pivot?->viewed_at ? $pivot->viewed_at->toIso8601String() : null,
                'responded' => $pivot?->responded ?? false,
                'responded_at' => $pivot?->responded_at ? $pivot->responded_at->toIso8601String() : null,
                'has_submitted_quote' => $hasSubmitted,
                'created_at' => $rfq->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $rfqs,
            'count' => $rfqs->count(),
        ]);
    }

    /**
     * Mark RFQ as viewed by vendor
     */
    public function markAsViewed(Request $request, $id)
    {
        $user = $request->user();

        // Verify user is a vendor
        if ($user->role !== 'vendor') {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get vendor from authenticated user
        $vendor = $user->vendor ?? Vendor::find($user->vendor_id);
        
        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $rfq = RFQ::where('rfq_id', $id)->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Update viewed_at in pivot table
        $rfq->vendors()->updateExistingPivot($vendor->id, [
            'viewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'RFQ marked as viewed',
        ]);
    }

    /**
     * Get all quotations for an RFQ (comparison view for procurement)
     */
    public function getQuotationsForRFQ(Request $request, $id)
    {
        $user = $request->user();

        // Check role (procurement only)
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can view quotation comparisons',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $rfq = RFQ::where('rfq_id', $id)->with(['items', 'mrf.executiveApprover', 'mrf.chairmanApprover'])->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get all quotations with items and vendor details
        // Exclude rejected quotations from active view (they remain accessible for historical tracking via RFQ Management)
        // Rejected quotations should not be selectable or forwarded for approval
        $quotations = Quotation::where('rfq_id', $rfq->id)
            ->where(function($query) {
                $query->where('status', '!=', 'Rejected')
                    ->where(function($q) {
                        $q->where('review_status', '!=', 'rejected')
                            ->orWhereNull('review_status');
                    })
                    ->orWhereNull('status');
            })
            ->with(['vendor', 'items.rfqItem'])
            ->get()
            ->map(function ($quotation) {
                // Handle missing vendor gracefully
                $vendor = $quotation->vendor;
                
                // Calculate delivery_days from delivery_date if not provided
                // Calculate delivery_days from delivery_date if not provided
                $deliveryDays = $quotation->delivery_days;

                if ($deliveryDays === null && $quotation->delivery_date) {
                    $deliveryDays = now()->startOfDay()->diffInDays(
                        \Carbon\Carbon::parse($quotation->delivery_date)->startOfDay(),
                        false
                    );

                    if ($deliveryDays < 0) {
                        $deliveryDays = 0; // If delivery date is in the past, set to 0
                    }
                }

                $deliveryDays = (int) $deliveryDays;
                
                // Get submitted date (prefer submitted_at, fallback to created_at)
                $submittedDate = $quotation->submitted_at ?? $quotation->created_at;
                $createdAt = $quotation->created_at;
                
                return [
                    'quotation' => [
                        // ID fields
                        'id' => $quotation->quotation_id,
                        'rfq_id' => $quotation->rfq_id,
                        'vendor_id' => $quotation->vendor_id,
                        'quoteNumber' => $quotation->quote_number,
                        
                        // Amount fields (both formats)
                        'total_amount' => (float) $quotation->total_amount,
                        'totalAmount' => (float) $quotation->total_amount,
                        'price' => (string) ($quotation->price ?? $quotation->total_amount),
                        'currency' => $quotation->currency ?? 'NGN',
                        
                        // Delivery fields (both formats)
                        'delivery_days' => $deliveryDays,
                        'deliveryDays' => $deliveryDays,
                        'delivery_date' => $quotation->delivery_date?->format('Y-m-d'),
                        'deliveryDate' => $quotation->delivery_date?->format('Y-m-d'),
                        
                        // Payment terms (both formats)
                        'payment_terms' => $quotation->payment_terms ?? null,
                        'paymentTerms' => $quotation->payment_terms ?? null,
                        'payment_terms_text' => $quotation->payment_terms ?? null,
                        
                        // Validity and warranty
                        'validity_days' => $quotation->validity_days ?? null,
                        'validityDays' => $quotation->validity_days ?? null,
                        'warranty_period' => $quotation->warranty_period ?? null,
                        'warrantyPeriod' => $quotation->warranty_period ?? null,
                        
                        // Status fields
                        'status' => $quotation->status ?? 'submitted',
                        'reviewStatus' => $quotation->review_status ?? 'pending',
                        
                        // Date fields (all formats)
                        'submitted_date' => $submittedDate?->toIso8601String(),
                        'submittedDate' => $submittedDate?->toIso8601String(),
                        'submitted_at' => $submittedDate?->toIso8601String(),
                        'created_at' => $createdAt?->toIso8601String(),
                        'createdAt' => $createdAt?->toIso8601String(),
                        
                        // Notes and remarks
                        'notes' => $quotation->notes ?? null,
                        'remarks' => $quotation->approval_remarks ?? $quotation->notes ?? null,
                        
                        // Attachments
                        'attachments' => $quotation->attachments ?? [],
                    ],
                    'vendor' => $vendor ? [
                        'id' => $vendor->vendor_id,
                        'name' => $vendor->name,
                        'company_name' => $vendor->name,
                        'email' => $vendor->email,
                        'phone' => $vendor->phone,
                        'rating' => (float) ($vendor->rating ?? 0),
                        'total_orders' => (int) ($vendor->total_orders ?? 0),
                        'orders' => (int) ($vendor->total_orders ?? 0),
                    ] : [
                        'id' => null,
                        'name' => $quotation->vendor_name ?? 'Unknown Vendor',
                        'company_name' => $quotation->vendor_name ?? 'Unknown Vendor',
                        'email' => null,
                        'phone' => null,
                        'rating' => 0,
                        'total_orders' => 0,
                        'orders' => 0,
                    ],
                    'items' => $quotation->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'rfq_item_id' => $item->rfq_item_id,
                            'item_name' => $item->item_name,
                            'name' => $item->item_name,
                            'description' => $item->description ?? '',
                            'quantity' => $item->quantity,
                            'unit' => $item->unit ?? 'unit',
                            'unit_price' => (float) $item->unit_price,
                            'unitPrice' => (float) $item->unit_price,
                            'total_price' => (float) $item->total_price,
                            'totalPrice' => (float) $item->total_price,
                            'specifications' => $item->specifications ?? '',
                        ];
                    }),
                ];
            });

        // Get MRF with all relationships
        $mrf = $rfq->mrf;

        return response()->json([
            'success' => true,
            'data' => [
                'rfq' => [
                    'id' => $rfq->rfq_id,
                    'title' => $rfq->getDisplayTitle(),
                    'description' => $rfq->description,
                    'category' => $rfq->category,
                    'deadline' => $rfq->deadline->format('Y-m-d'),
                    'status' => $rfq->status,
                    'workflowState' => $rfq->workflow_state,
                    'estimatedCost' => (float) $rfq->estimated_cost,
                    'paymentTerms' => $rfq->payment_terms,
                    'supportingDocuments' => $rfq->supporting_documents ?? [],
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
                ],
                'mrf' => $mrf ? [
                    'id' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'contractType' => $mrf->contract_type,
                    'description' => $mrf->description,
                    'estimatedCost' => (float) $mrf->estimated_cost,
                    'executiveApproved' => (bool) $mrf->executive_approved,
                    'executiveApprovedAt' => $mrf->executive_approved_at ? $mrf->executive_approved_at->toIso8601String() : null,
                    'executiveApprovedBy' => $mrf->executiveApprover ? [
                        'id' => $mrf->executiveApprover->id,
                        'name' => $mrf->executiveApprover->name,
                        'email' => $mrf->executiveApprover->email,
                    ] : null,
                    'chairmanApproved' => (bool) $mrf->chairman_approved,
                    'chairmanApprovedAt' => $mrf->chairman_approved_at ? $mrf->chairman_approved_at->toIso8601String() : null,
                    'workflowState' => $mrf->workflow_state,
                    'status' => $mrf->status,
                ] : null,
                'quotations' => $quotations,
                'statistics' => [
                    'total_quotations' => $quotations->count(),
                    'lowest_bid' => $quotations->min('quotation.total_amount'),
                    'highest_bid' => $quotations->max('quotation.total_amount'),
                    'average_bid' => $quotations->avg('quotation.total_amount'),
                ],
            ],
        ]);
    }

    /**
     * Select winning vendor/quotation (award RFQ)
     */
    public function selectVendor(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can select vendors',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $rfq = RFQ::where('rfq_id', $id)->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'quotation_id' => 'required|exists:quotations,quotation_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Get the selected quotation
        $selectedQuotation = Quotation::where('quotation_id', $request->quotation_id)
            ->where('rfq_id', $rfq->id)
            ->first();

        if (!$selectedQuotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found for this RFQ',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Use transaction to ensure data consistency
        DB::beginTransaction();
        try {
            // Update RFQ
            $rfq->update([
                'status' => 'Awarded',
                'workflow_state' => 'supply_chain_review', // Move to Supply Chain Director for approval
                'selected_vendor_id' => $selectedQuotation->vendor_id,
                'selected_quotation_id' => $selectedQuotation->id,
            ]);

            // Update selected quotation
            $selectedQuotation->update([
                'status' => 'Approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            // Update other quotations
            Quotation::where('rfq_id', $rfq->id)
                ->where('id', '!=', $selectedQuotation->id)
                ->update([
                    'status' => 'Rejected',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'rejection_reason' => 'Another vendor was selected',
                ]);

            DB::commit();

            // Send notifications
            $this->notificationService->notifyQuotationAwarded($selectedQuotation);
            
            // Notify rejected vendors
            $rejectedQuotations = Quotation::where('rfq_id', $rfq->id)
                ->where('id', '!=', $selectedQuotation->id)
                ->with('vendor')
                ->get();
                
            foreach ($rejectedQuotations as $rejectedQuotation) {
                $this->notificationService->notifyQuotationRejected($rejectedQuotation);
            }

            // Get vendor info safely
            $selectedVendor = $selectedQuotation->vendor;

            return response()->json([
                'success' => true,
                'message' => 'Vendor selected successfully',
                'data' => [
                    'rfq_id' => $rfq->rfq_id,
                    'status' => $rfq->status,
                    'selected_vendor' => $selectedVendor ? [
                        'id' => $selectedVendor->vendor_id,
                        'name' => $selectedVendor->name,
                    ] : [
                        'id' => null,
                        'name' => $selectedQuotation->vendor_name ?? 'Unknown Vendor',
                    ],
                    'selected_quotation' => [
                        'id' => $selectedQuotation->quotation_id,
                        'total_amount' => (float) $selectedQuotation->total_amount,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to select vendor',
                'message' => $e->getMessage(),
                'code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Submit quotation for a specific RFQ (vendor endpoint)
     * Route: POST /api/rfqs/{rfq-id}/submit-quotation
     */
    public function submitQuotation(Request $request, $id)
    {
        $user = $request->user();

        // Verify user is a vendor - check both direct role field and Spatie roles
        $isVendor = false;
        if ($user->role === 'vendor') {
            $isVendor = true;
        } elseif (method_exists($user, 'hasRole') && $user->hasRole('vendor')) {
            $isVendor = true;
        }

        if (!$isVendor) {
            $response = [
                'success' => false,
                'error' => 'Only vendors can submit quotations',
                'code' => 'FORBIDDEN',
            ];
            
            // Include debug info only in debug mode
            if (config('app.debug')) {
                $response['debug'] = [
                    'user_role' => $user->role,
                    'has_vendor_role' => method_exists($user, 'hasRole') ? $user->hasRole('vendor') : 'method_not_available',
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ];
            }
            
            return response()->json($response, 403);
        }

        // Get vendor from authenticated user
        $vendor = $user->vendor ?? Vendor::find($user->vendor_id);
        if (!$vendor) {
            // Try finding vendor by email as fallback
            $vendor = Vendor::where('email', $user->email)->first();
        }

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found. Please ensure your account is linked to a vendor.',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Find RFQ by RFQ ID (the $id parameter from URL)
        $rfq = RFQ::where('rfq_id', $id)->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'vendorName' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'totalAmount' => 'nullable|numeric|min:0',
            'deliveryDate' => 'required|date',
            'deliveryDays' => 'nullable|integer|min:0',
            'paymentTerms' => 'nullable|string',
            'validityDays' => 'nullable|integer|min:0',
            'warrantyPeriod' => 'nullable|string',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'currency' => 'nullable|string|max:10',
            'items' => 'nullable|array',
            'items.*.rfqItemId' => 'nullable|exists:rfq_items,id',
            'items.*.itemName' => 'nullable|string|max:255',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.description' => 'nullable|string',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.unitPrice' => 'nullable|numeric|min:0',
            'items.*.totalPrice' => 'nullable|numeric|min:0',
            'items.*.specifications' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Check if RFQ is still open
        if ($rfq->status !== 'Open' && $rfq->workflow_state !== 'open') {
            return response()->json([
                'success' => false,
                'error' => 'RFQ is not open for quotations',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Check if vendor is associated with this RFQ
        if (!$rfq->vendors->contains($vendor->id)) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor is not associated with this RFQ',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Calculate total amount from items if provided
        $calculatedTotal = $request->totalAmount ?? $request->price;
        if ($request->has('items') && is_array($request->items) && count($request->items) > 0) {
            $itemsTotal = 0;
            foreach ($request->items as $item) {
                $unitPrice = $item['unitPrice'] ?? $item['unit_price'] ?? 0;
                $quantity = $item['quantity'] ?? 1;
                $itemTotal = $item['totalPrice'] ?? $item['total_price'] ?? ($unitPrice * $quantity);
                $itemsTotal += $itemTotal;
            }
            // Use calculated total from items if it's greater than 0, otherwise use provided totalAmount/price
            if ($itemsTotal > 0) {
                $calculatedTotal = $itemsTotal;
            }
        }

        // Check if quotation already exists
        $existing = Quotation::where('rfq_id', $rfq->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        // If existing and revision was requested, allow resubmission
        if ($existing) {
            if ($existing->review_status === 'revision_requested') {
                // Update existing quotation (resubmission)
                // Provide default value for validity_days if not provided (default is 30 days)
                $validityDays = $request->validityDays ?? $existing->validity_days ?? 30;
                
                $existing->update([
                    'vendor_name' => $request->vendorName ?? $vendor->name,
                    'price' => $request->price,
                    'total_amount' => $calculatedTotal,
                    'currency' => $request->currency ?? 'NGN',
                    'delivery_date' => $request->deliveryDate,
                    'delivery_days' => $request->deliveryDays,
                    'payment_terms' => $request->paymentTerms,
                    'validity_days' => $validityDays,
                    'warranty_period' => $request->warrantyPeriod,
                    'notes' => $request->notes,
                    'attachments' => $request->attachments,
                    'status' => 'Pending',
                    'review_status' => 'pending', // Reset to pending
                    'revision_notes' => null, // Clear revision notes
                    'submitted_at' => now(),
                ]);
                $quotation = $existing;
                
                // Delete existing quotation items before creating new ones
                QuotationItem::where('quotation_id', $quotation->id)->delete();
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Quotation already submitted for this RFQ',
                    'code' => 'VALIDATION_ERROR'
                ], 422);
            }
        } else {
            // Create new quotation
            // Provide default value for validity_days if not provided (default is 30 days)
            $validityDays = $request->validityDays ?? 30;
            
            $quotation = Quotation::create([
                'quotation_id' => Quotation::generateQuotationId(),
                'rfq_id' => $rfq->id,
                'vendor_id' => $vendor->id,
                'vendor_name' => $request->vendorName ?? $vendor->name,
                'price' => $request->price,
                'total_amount' => $calculatedTotal,
                'currency' => $request->currency ?? 'NGN',
                'delivery_date' => $request->deliveryDate,
                'delivery_days' => $request->deliveryDays,
                'payment_terms' => $request->paymentTerms,
                'validity_days' => $validityDays,
                'warranty_period' => $request->warrantyPeriod,
                'notes' => $request->notes,
                'attachments' => $request->attachments,
                'status' => 'Pending',
                'review_status' => 'pending',
                'submitted_at' => now(),
            ]);
        }

        // Handle quotation items if provided
        if ($request->has('items') && is_array($request->items) && count($request->items) > 0) {
            foreach ($request->items as $itemData) {
                $itemName = $itemData['itemName'] ?? $itemData['name'] ?? 'Item';
                $description = $itemData['description'] ?? '';
                $quantity = $itemData['quantity'] ?? 1;
                $unit = $itemData['unit'] ?? 'unit';
                $unitPrice = $itemData['unitPrice'] ?? $itemData['unit_price'] ?? 0;
                $totalPrice = $itemData['totalPrice'] ?? $itemData['total_price'] ?? ($unitPrice * $quantity);
                $rfqItemId = $itemData['rfqItemId'] ?? $itemData['rfq_item_id'] ?? null;
                $specifications = $itemData['specifications'] ?? '';

                QuotationItem::create([
                    'quotation_id' => $quotation->id,
                    'rfq_item_id' => $rfqItemId,
                    'item_name' => $itemName,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'specifications' => $specifications,
                ]);
            }
            
            // Recalculate total from items and update quotation
            $itemsTotal = QuotationItem::where('quotation_id', $quotation->id)->sum('total_price');
            if ($itemsTotal > 0) {
                $quotation->update([
                    'total_amount' => $itemsTotal,
                    'price' => $itemsTotal, // Also update price field for backward compatibility
                ]);
            }
        }

        // Log activity
        try {
            Activity::create([
                'type' => 'quotation_submitted',
                'title' => 'Quotation Submitted',
                'description' => "Vendor {$quotation->vendor_name} submitted quotation {$quotation->quotation_id} for RFQ {$rfq->rfq_id}",
                'user_id' => $user->id,
                'user_name' => $quotation->vendor_name,
                'entity_type' => 'quotation',
                'entity_id' => $quotation->quotation_id,
                'status' => 'submitted',
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log quotation submission activity', ['error' => $e->getMessage()]);
        }

        // Notify procurement managers
        $this->notificationService->notifyQuotationSubmitted($quotation, $quotation->vendor_name);

        // Load items for response
        $quotation->load('items');
        
        return response()->json([
            'success' => true,
            'message' => 'Quotation submitted successfully',
            'data' => [
                'id' => $quotation->quotation_id,
                'rfqId' => $rfq->rfq_id,
                'vendorId' => $vendor->vendor_id,
                'vendorName' => $quotation->vendor_name,
                'price' => (float) $quotation->price,
                'totalAmount' => (float) $quotation->total_amount,
                'deliveryDate' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status,
                'items' => $quotation->items->map(function($item) {
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
            ]
        ], 201);
    }

    /**
     * Close RFQ without selecting a vendor
     */
    public function closeRFQ(Request $request, $id)
    {
        $user = $request->user();

        // Check role
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can close RFQs',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $rfq = RFQ::where('rfq_id', $id)->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update RFQ
        $rfq->update([
            'status' => 'Closed',
        ]);

        // Update all quotations
        Quotation::where('rfq_id', $rfq->id)
            ->update([
                'status' => 'Rejected',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'rejection_reason' => $request->reason ?? 'RFQ closed without selection',
            ]);

        // Notify all participating vendors
        $quotations = Quotation::where('rfq_id', $rfq->id)->with('vendor')->get();
        foreach ($quotations as $quotation) {
            $this->notificationService->notifyRFQClosed($rfq, $quotation->vendor);
        }

        return response()->json([
            'success' => true,
            'message' => 'RFQ closed successfully',
            'data' => [
                'rfq_id' => $rfq->rfq_id,
                'status' => $rfq->status,
            ],
        ]);
    }
}
