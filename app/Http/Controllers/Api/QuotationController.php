<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RFQ;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QuotationController extends Controller
{
    /**
     * Get all quotations
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Quotation::with(['rfq', 'vendor', 'approver']);

        // If user is a vendor, only show their quotations
        $isVendor = false;
        $vendor = null;
        if ($user && ($user->role === 'vendor' || (method_exists($user, 'hasRole') && $user->hasRole('vendor')))) {
            $isVendor = true;
            // Get vendor from user
            if ($user->vendor_id) {
                $vendor = Vendor::find($user->vendor_id);
            }
            if (!$vendor) {
                $vendor = Vendor::where('email', $user->email)->first();
            }
            if ($vendor) {
                $query->where('vendor_id', $vendor->id);
            } else {
                // Vendor user but no vendor record - return empty
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }
        }

        // Filter by vendor (for non-vendor users)
        if (!$isVendor && $request->has('vendorId')) {
            $vendor = Vendor::where('vendor_id', $request->vendorId)->first();
            if ($vendor) {
                $query->where('vendor_id', $vendor->id);
            }
        }

        // Filter by RFQ
        if ($request->has('rfqId')) {
            $rfq = RFQ::where('rfq_id', $request->rfqId)->first();
            if ($rfq) {
                $query->where('rfq_id', $rfq->id);
            }
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $quotations = $query->with('items')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $quotations->map(function($quotation) {
                // Calculate delivery_days from delivery_date if not provided
                $deliveryDays = $quotation->delivery_days;
                if (!$deliveryDays && $quotation->delivery_date) {
                    $deliveryDays = now()->diffInDays($quotation->delivery_date, false);
                    if ($deliveryDays < 0) {
                        $deliveryDays = 0;
                    }
                }
                
                // Get submitted date (prefer submitted_at, fallback to created_at)
                $submittedDate = $quotation->submitted_at ?? $quotation->created_at;
                $createdAt = $quotation->created_at;
                
            return [
                    // ID fields
                'id' => $quotation->quotation_id,
                'rfqId' => $quotation->rfq ? $quotation->rfq->rfq_id : null,
                    'rfq_id' => $quotation->rfq ? $quotation->rfq->rfq_id : null,
                'vendorId' => $quotation->vendor ? $quotation->vendor->vendor_id : null,
                    'vendor_id' => $quotation->vendor ? $quotation->vendor->vendor_id : null,
                'vendorName' => $quotation->vendor_name,
                    'vendor_name' => $quotation->vendor_name,
                    
                    // Amount fields (both formats)
                    'price' => (string) ($quotation->price ?? $quotation->total_amount),
                    'totalAmount' => (float) $quotation->total_amount,
                    'total_amount' => (float) $quotation->total_amount,
                    'currency' => $quotation->currency ?? 'NGN',
                    
                    // Delivery fields (both formats)
                    'delivery_days' => $deliveryDays,
                    'deliveryDays' => $deliveryDays,
                    'delivery_date' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,
                    'deliveryDate' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,
                    
                    // Payment terms (all variants)
                    'payment_terms' => $quotation->payment_terms ?? null,
                    'paymentTerms' => $quotation->payment_terms ?? null,
                    'payment_terms_text' => $quotation->payment_terms ?? null,
                    
                    // Validity and warranty
                    'validity_days' => $quotation->validity_days ?? null,
                    'validityDays' => $quotation->validity_days ?? null,
                    'warranty_period' => $quotation->warranty_period ?? null,
                    'warrantyPeriod' => $quotation->warranty_period ?? null,
                    
                    // Date fields (all formats)
                    'submitted_date' => $submittedDate ? $submittedDate->toIso8601String() : null,
                    'submittedDate' => $submittedDate ? $submittedDate->toIso8601String() : null,
                    'submitted_at' => $submittedDate ? $submittedDate->toIso8601String() : null,
                    'created_at' => $createdAt ? $createdAt->toIso8601String() : null,
                    'createdAt' => $createdAt ? $createdAt->toIso8601String() : null,
                    
                    // Status fields
                    'status' => $quotation->status ?? 'Pending',
                    'reviewStatus' => $quotation->review_status ?? 'pending',
                    'review_status' => $quotation->review_status ?? 'pending',
                    
                    // Notes and remarks
                    'notes' => $quotation->notes ?? null,
                    'remarks' => $quotation->approval_remarks ?? $quotation->notes ?? null,
                    'rejectionReason' => $quotation->rejection_reason ?? null,
                    'rejection_reason' => $quotation->rejection_reason ?? null,
                    'revisionNotes' => $quotation->revision_notes ?? null,
                    'revision_notes' => $quotation->revision_notes ?? null,
                    'approvalRemarks' => $quotation->approval_remarks ?? null,
                    'approval_remarks' => $quotation->approval_remarks ?? null,
                    
                    // Attachments
                    'attachments' => $quotation->attachments ?? [],
                    
                    // Items
                    'items' => $quotation->items->map(function($item) {
                        return [
                            'id' => $item->id,
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
            })
        ]);
    }

    /**
     * Submit quotation (vendor only)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rfqId' => 'required|exists:r_f_q_s,rfq_id',
            'vendorId' => 'required|exists:vendors,vendor_id',
            'vendorName' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'deliveryDate' => 'required|date',
            'notes' => 'nullable|string',
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

        $rfq = RFQ::where('rfq_id', $request->rfqId)->first();
        $vendor = Vendor::where('vendor_id', $request->vendorId)->first();

        if (!$rfq || !$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ or Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if RFQ is still open
        if ($rfq->status !== 'Open') {
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
        $calculatedTotal = $request->price;
        if ($request->has('items') && is_array($request->items) && count($request->items) > 0) {
            $itemsTotal = 0;
            foreach ($request->items as $item) {
                $unitPrice = $item['unitPrice'] ?? $item['unit_price'] ?? 0;
                $quantity = $item['quantity'] ?? 1;
                $itemTotal = $item['totalPrice'] ?? $item['total_price'] ?? ($unitPrice * $quantity);
                $itemsTotal += $itemTotal;
            }
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
                $existing->update([
                    'vendor_name' => $request->vendorName,
                    'price' => $request->price,
                    'total_amount' => $calculatedTotal,
                    'delivery_date' => $request->deliveryDate,
                    'notes' => $request->notes,
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
        $quotation = Quotation::create([
            'quotation_id' => Quotation::generateQuotationId(),
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'vendor_name' => $request->vendorName,
            'price' => $request->price,
            'total_amount' => $calculatedTotal,
            'currency' => 'NGN', // Default currency
            'delivery_date' => $request->deliveryDate,
            'validity_days' => 30, // Default validity period
            'notes' => $request->notes,
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

        // Load items for response
        $quotation->load('items');

        return response()->json([
            'id' => $quotation->quotation_id,
            'rfqId' => $rfq->rfq_id,
            'vendorId' => $vendor->vendor_id,
            'vendorName' => $quotation->vendor_name,
            'price' => (float) $quotation->price,
            'totalAmount' => (float) $quotation->total_amount,
            'deliveryDate' => $quotation->delivery_date->format('Y-m-d'),
            'notes' => $quotation->notes,
            'status' => $quotation->status,
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
        ], 201);
    }

    /**
     * Approve quotation
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();

        // Check permission (procurement or admin)
        if (!in_array($user->role, ['procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $quotation = Quotation::where('quotation_id', $id)->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
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

        $quotation->update([
            'status' => 'Approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'approval_remarks' => $request->remarks,
        ]);

        // Log activity
        try {
            Activity::create([
                'type' => 'quotation_approved',
                'title' => 'Quotation Approved',
                'description' => "Quotation {$quotation->quotation_id} was approved by {$user->name}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'quotation',
                'entity_id' => $quotation->quotation_id,
                'status' => 'approved',
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log quotation approval activity', ['error' => $e->getMessage()]);
        }

        // Close the RFQ
        $quotation->rfq->update(['status' => 'Awarded']);

        return response()->json([
            'success' => true,
            'message' => 'Quotation approved successfully',
            'quotation' => [
                'id' => $quotation->quotation_id,
                'status' => $quotation->status,
                'approvalRemarks' => $quotation->approval_remarks,
            ]
        ]);
    }

    /**
     * Reject quotation (Procurement Manager)
     * Rejected quotations are removed from active/visible quotations but remain accessible for historical tracking
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - procurement managers can reject quotations
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only procurement managers can reject quotations',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $quotation = Quotation::where('quotation_id', $id)
            ->with(['rfq.mrf', 'vendor'])
            ->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Check if quotation can be rejected (not already approved or selected)
        if ($quotation->status === 'Approved' || $quotation->review_status === 'approved') {
            return response()->json([
                'success' => false,
                'error' => 'Cannot reject an approved quotation',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10',
            'comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update quotation status
        $quotation->update([
            'status' => 'Rejected',
            'review_status' => 'rejected',
            'rejection_reason' => $request->reason,
            'revision_notes' => $request->comments ?? null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // Log activity
        try {
            Activity::create([
                'type' => 'quotation_rejected',
                'title' => 'Quotation Rejected',
                'description' => "Quotation {$quotation->quotation_id} was rejected by {$user->name}. Reason: {$request->reason}",
                'user_id' => $user->id,
                'user_name' => $user->name,
                'entity_type' => 'quotation',
                'entity_id' => $quotation->quotation_id,
                'status' => 'rejected',
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log quotation rejection activity', ['error' => $e->getMessage()]);
        }

        // Log rejection for audit purposes
        \Log::info('Quotation rejected by procurement manager', [
            'quotation_id' => $quotation->quotation_id,
            'rfq_id' => $quotation->rfq ? $quotation->rfq->rfq_id : null,
            'mrf_id' => $quotation->rfq && $quotation->rfq->mrf ? $quotation->rfq->mrf->mrf_id : null,
            'vendor_id' => $quotation->vendor ? $quotation->vendor->vendor_id : null,
            'vendor_name' => $quotation->vendor_name,
            'rejected_by' => $user->id,
            'rejected_by_name' => $user->name,
            'rejection_reason' => $request->reason,
            'rejected_at' => now()->toIso8601String(),
        ]);

        // Send notification to vendor
        try {
            if ($quotation->vendor) {
                $quotation->vendor->notify(new \App\Notifications\QuotationStatusUpdatedNotification(
                    $quotation,
                    'rejected',
                    "Your quotation has been rejected. Reason: {$request->reason}"
                ));
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to send rejection notification to vendor', [
                'quotation_id' => $quotation->quotation_id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quotation rejected successfully. It has been removed from active quotations but remains accessible for historical tracking.',
            'data' => [
                'id' => $quotation->quotation_id,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status,
                'rejectionReason' => $quotation->rejection_reason,
                'revisionNotes' => $quotation->revision_notes,
                'reviewedBy' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'reviewedAt' => $quotation->reviewed_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Request revision of quotation
     */
    public function requestRevision(Request $request, $id)
    {
        $user = $request->user();

        // Only Procurement Manager can request revision
        if (!in_array($user->role, ['procurement', 'procurement_manager', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Only Procurement Managers can request revisions.',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $quotation = Quotation::where('quotation_id', $id)->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'revisionNotes' => 'required|string',
            'deadline' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $quotation->update([
            'review_status' => 'revision_requested',
            'revision_notes' => $request->revisionNotes,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // TODO: Send notification/email to vendor with revision request

        return response()->json([
            'success' => true,
            'message' => 'Revision requested from vendor',
            'data' => [
                'id' => $quotation->quotation_id,
                'reviewStatus' => $quotation->review_status,
                'revisionNotes' => $quotation->revision_notes,
                'reviewedAt' => $quotation->reviewed_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Delete/remove quotation (vendor only - can only delete their own quotations)
     */
    public function destroy(Request $request, $id)
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
                'error' => 'Only vendors can delete quotations',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Find quotation by quotation_id
        $quotation = Quotation::where('quotation_id', $id)->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get vendor from authenticated user
        $vendor = null;
        if ($user->vendor_id && method_exists($user, 'vendor')) {
            $vendor = $user->vendor;
        }
        if (!$vendor && $user->vendor_id) {
            $vendor = Vendor::find($user->vendor_id);
        }
        if (!$vendor) {
            $vendor = Vendor::where('email', $user->email)->first();
        }

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Verify quotation belongs to this vendor
        if ($quotation->vendor_id !== $vendor->id) {
            return response()->json([
                'success' => false,
                'error' => 'You can only delete your own quotations',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Only allow deletion if quotation is still pending (not approved/rejected)
        // Vendors can't delete quotations that have been reviewed/approved by procurement
        if ($quotation->status === 'Approved') {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete an approved quotation. Please contact procurement to cancel it.',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Store quotation ID for response before deletion
        $quotationId = $quotation->quotation_id;
        $rfqId = $quotation->rfq ? $quotation->rfq->rfq_id : null;

        // Delete the quotation
        $quotation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Quotation removed successfully',
            'data' => [
                'id' => $quotationId,
                'rfqId' => $rfqId,
            ]
        ]);
    }

    /**
     * Close quotation
     * Endpoint: POST /api/quotations/{id}/close
     */
    public function close(Request $request, $id)
    {
        $user = $request->user();
        
        // Authorization check - only procurement can close
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $quotation = Quotation::where('quotation_id', $id)->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Only allow closing submitted/pending quotations
        if (!in_array($quotation->status, ['submitted', 'Submitted', 'Pending', 'pending'])) {
            return response()->json([
                'success' => false,
                'error' => 'Only submitted quotations can be closed',
                'code' => 'VALIDATION_ERROR'
            ], 400);
        }

        $quotation->status = 'closed';
        $quotation->updated_at = now();
        $quotation->save();

        // Log activity
        Activity::create([
            'type' => 'quotation_closed',
            'title' => 'Quotation Closed',
            'description' => "Quotation {$quotation->quotation_id} was closed by {$user->name}",
            'user_id' => $user->id,
            'user_name' => $user->name,
            'entity_type' => 'quotation',
            'entity_id' => $quotation->quotation_id,
            'status' => 'closed'
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $quotation->quotation_id,
                'status' => $quotation->status,
                'updated_at' => $quotation->updated_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Reopen quotation
     * Endpoint: POST /api/quotations/{id}/reopen
     */
    public function reopen(Request $request, $id)
    {
        $user = $request->user();
        
        // Authorization check - only procurement can reopen
        if (!in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $quotation = Quotation::where('quotation_id', $id)->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Only allow reopening closed quotations
        if ($quotation->status !== 'closed') {
            return response()->json([
                'success' => false,
                'error' => 'Only closed quotations can be reopened',
                'code' => 'VALIDATION_ERROR'
            ], 400);
        }

        $quotation->status = 'submitted';
        $quotation->updated_at = now();
        $quotation->save();

        // Log activity
        Activity::create([
            'type' => 'quotation_reopened',
            'title' => 'Quotation Reopened',
            'description' => "Quotation {$quotation->quotation_id} was reopened by {$user->name}",
            'user_id' => $user->id,
            'user_name' => $user->name,
            'entity_type' => 'quotation',
            'entity_id' => $quotation->quotation_id,
            'status' => 'submitted'
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $quotation->quotation_id,
                'status' => $quotation->status,
                'updated_at' => $quotation->updated_at->toIso8601String(),
            ]
        ]);
    }
}
