<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
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
        $query = Quotation::with(['rfq', 'vendor', 'approver']);

        // Filter by vendor
        if ($request->has('vendorId')) {
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

        $quotations = $query->orderBy('created_at', 'desc')->get();

        return response()->json($quotations->map(function($quotation) {
            return [
                'id' => $quotation->quotation_id,
                'rfqId' => $quotation->rfq ? $quotation->rfq->rfq_id : null,
                'vendorId' => $quotation->vendor ? $quotation->vendor->vendor_id : null,
                'vendorName' => $quotation->vendor_name,
                'price' => (float) $quotation->price,
                'totalAmount' => (float) $quotation->total_amount,
                'deliveryDate' => $quotation->delivery_date ? $quotation->delivery_date->format('Y-m-d') : null,
                'notes' => $quotation->notes,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status ?? 'pending',
                'rejectionReason' => $quotation->rejection_reason,
                'revisionNotes' => $quotation->revision_notes,
                'approvalRemarks' => $quotation->approval_remarks,
            ];
        }));
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
                    'delivery_date' => $request->deliveryDate,
                    'notes' => $request->notes,
                    'status' => 'Pending',
                    'review_status' => 'pending', // Reset to pending
                    'revision_notes' => null, // Clear revision notes
                    'submitted_at' => now(),
                ]);
                $quotation = $existing;
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
            'delivery_date' => $request->deliveryDate,
            'notes' => $request->notes,
            'status' => 'Pending',
                'review_status' => 'pending',
                'submitted_at' => now(),
        ]);
        }

        return response()->json([
            'id' => $quotation->quotation_id,
            'rfqId' => $rfq->rfq_id,
            'vendorId' => $vendor->vendor_id,
            'vendorName' => $quotation->vendor_name,
            'price' => (float) $quotation->price,
            'deliveryDate' => $quotation->delivery_date->format('Y-m-d'),
            'notes' => $quotation->notes,
            'status' => $quotation->status,
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
     * Reject quotation
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();

        // Check permission
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

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
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

        $quotation->update([
            'status' => 'Rejected',
            'review_status' => 'rejected',
            'rejection_reason' => $request->reason,
            'revision_notes' => $request->comments,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // TODO: Send notification/email to vendor with rejection reason

        return response()->json([
            'success' => true,
            'message' => 'Quotation rejected',
            'data' => [
                'id' => $quotation->quotation_id,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status,
                'rejectionReason' => $quotation->rejection_reason,
                'revisionNotes' => $quotation->revision_notes,
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
}
