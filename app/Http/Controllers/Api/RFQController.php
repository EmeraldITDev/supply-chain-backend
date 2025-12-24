<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RFQ;
use App\Models\MRF;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RFQController extends Controller
{
    /**
     * Get all RFQs
     */
    public function index(Request $request)
    {
        $query = RFQ::with(['mrf', 'creator', 'vendors']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $rfqs = $query->orderBy('created_at', 'desc')->get();

        return response()->json($rfqs->map(function($rfq) {
            return [
                'id' => $rfq->rfq_id,
                'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
                'mrfTitle' => $rfq->mrf_title ?? ($rfq->mrf ? $rfq->mrf->title : null),
                'description' => $rfq->description,
                'quantity' => $rfq->quantity,
                'estimatedCost' => (float) $rfq->estimated_cost,
                'deadline' => $rfq->deadline->format('Y-m-d'),
                'status' => $rfq->status,
                'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
                'createdAt' => $rfq->created_at->toIso8601String(),
            ];
        }));
    }

    /**
     * Create new RFQ
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mrfId' => 'nullable|exists:m_r_f_s,mrf_id',
            'description' => 'required|string',
            'quantity' => 'required|string',
            'estimatedCost' => 'required|numeric|min:0',
            'deadline' => 'required|date',
            'vendorIds' => 'required|array|min:1',
            'vendorIds.*' => 'exists:vendors,vendor_id',
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

        // Get MRF if provided
        $mrf = null;
        $mrfTitle = null;
        if ($request->mrfId) {
            $mrf = MRF::where('mrf_id', $request->mrfId)->first();
            $mrfTitle = $mrf ? $mrf->title : null;
        }

        $rfq = RFQ::create([
            'rfq_id' => RFQ::generateRFQId(),
            'mrf_id' => $mrf ? $mrf->id : null,
            'mrf_title' => $mrfTitle,
            'description' => $request->description,
            'quantity' => $request->quantity,
            'estimated_cost' => $request->estimatedCost,
            'deadline' => $request->deadline,
            'status' => 'Open',
            'created_by' => $user->id,
        ]);

        // Attach vendors
        $vendorIds = Vendor::whereIn('vendor_id', $request->vendorIds)->pluck('id');
        $rfq->vendors()->attach($vendorIds);

        $rfq->load('vendors');

        return response()->json([
            'id' => $rfq->rfq_id,
            'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
            'mrfTitle' => $rfq->mrf_title,
            'description' => $rfq->description,
            'quantity' => $rfq->quantity,
            'estimatedCost' => (float) $rfq->estimated_cost,
            'deadline' => $rfq->deadline->format('Y-m-d'),
            'status' => $rfq->status,
            'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
            'createdAt' => $rfq->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Update RFQ
     */
    public function update(Request $request, $id)
    {
        $rfq = RFQ::where('rfq_id', $id)->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'sometimes|required|string',
            'quantity' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|required|numeric|min:0',
            'deadline' => 'sometimes|required|date',
            'status' => 'sometimes|in:Open,Closed,Awarded,Cancelled',
            'vendorIds' => 'sometimes|array|min:1',
            'vendorIds.*' => 'exists:vendors,vendor_id',
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
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('estimatedCost')) $updateData['estimated_cost'] = $request->estimatedCost;
        if ($request->has('deadline')) $updateData['deadline'] = $request->deadline;
        if ($request->has('status')) $updateData['status'] = $request->status;

        $rfq->update($updateData);

        // Update vendors if provided
        if ($request->has('vendorIds')) {
            $vendorIds = Vendor::whereIn('vendor_id', $request->vendorIds)->pluck('id');
            $rfq->vendors()->sync($vendorIds);
        }

        $rfq->load(['mrf', 'vendors']);

        return response()->json([
            'id' => $rfq->rfq_id,
            'mrfId' => $rfq->mrf_id ? (string) $rfq->mrf->mrf_id : null,
            'mrfTitle' => $rfq->mrf_title,
            'description' => $rfq->description,
            'quantity' => $rfq->quantity,
            'estimatedCost' => (float) $rfq->estimated_cost,
            'deadline' => $rfq->deadline->format('Y-m-d'),
            'status' => $rfq->status,
            'vendorIds' => $rfq->vendors->pluck('vendor_id')->toArray(),
            'createdAt' => $rfq->created_at->toIso8601String(),
        ]);
    }
}
