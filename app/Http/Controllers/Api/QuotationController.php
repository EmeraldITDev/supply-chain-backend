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
                'deliveryDate' => $quotation->delivery_date->format('Y-m-d'),
                'notes' => $quotation->notes,
                'status' => $quotation->status,
                'rejectionReason' => $quotation->rejection_reason,
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

        if ($existing) {
            return response()->json([
                'success' => false,
                'error' => 'Quotation already submitted for this RFQ',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $quotation = Quotation::create([
            'quotation_id' => Quotation::generateQuotationId(),
            'rfq_id' => $rfq->id,
            'vendor_id' => $vendor->id,
            'vendor_name' => $request->vendorName,
            'price' => $request->price,
            'delivery_date' => $request->deliveryDate,
            'notes' => $request->notes,
            'status' => 'Pending',
        ]);

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

        $quotation->update([
            'status' => 'Rejected',
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Quotation rejected',
            'quotation' => [
                'id' => $quotation->quotation_id,
                'status' => $quotation->status,
                'rejectionReason' => $quotation->rejection_reason,
            ]
        ]);
    }
}
