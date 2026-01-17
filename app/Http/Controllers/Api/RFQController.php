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
                'title' => $rfq->title,
                'category' => $rfq->category,
                'description' => $rfq->description,
                'quantity' => $rfq->quantity,
                'estimatedCost' => (float) $rfq->estimated_cost,
                'paymentTerms' => $rfq->payment_terms,
                'notes' => $rfq->notes,
                'supportingDocuments' => $rfq->supporting_documents ?? [],
                'deadline' => $rfq->deadline->format('Y-m-d'),
                'status' => $rfq->status,
                'workflowState' => $rfq->workflow_state,
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
            'mrfId' => 'required|string|exists:m_r_f_s,mrf_id',
            'title' => 'required|string',
            'category' => 'nullable|string|max:255',
            'description' => 'required|string',
            'quantity' => 'required|string',
            'estimatedCost' => 'required|string|numeric',
            'deadline' => 'required|date',
            'vendorIds' => 'required|array|min:1',
            'vendorIds.*' => 'required|string|exists:vendors,vendor_id',
            'paymentTerms' => 'nullable|string',
            'notes' => 'nullable|string',
            'supportingDocuments' => 'nullable|array',
            'supportingDocuments.*' => 'nullable|string|url', // URLs to supporting documents
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
        $mrfCategory = null;
        if ($request->mrfId) {
            $mrf = MRF::where('mrf_id', $request->mrfId)->first();
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

        $rfq = RFQ::create([
            'rfq_id' => RFQ::generateRFQId(),
            'mrf_id' => $mrf ? $mrf->id : null,
            'mrf_title' => $mrfTitle,
            'title' => $request->title,
            'category' => $category,
            'description' => $request->description,
            'quantity' => $request->quantity,
            'estimated_cost' => $request->estimatedCost,
            'deadline' => $request->deadline,
            'payment_terms' => $request->paymentTerms,
            'notes' => $request->notes,
            'supporting_documents' => !empty($supportingDocuments) ? $supportingDocuments : null,
            'status' => 'Open',
            'workflow_state' => 'open',
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
            'title' => $rfq->title,
            'category' => $rfq->category,
            'description' => $rfq->description,
            'quantity' => $rfq->quantity,
            'estimatedCost' => (float) $rfq->estimated_cost,
            'paymentTerms' => $rfq->payment_terms,
            'notes' => $rfq->notes,
            'supportingDocuments' => $rfq->supporting_documents ?? [],
            'deadline' => $rfq->deadline->format('Y-m-d'),
            'status' => $rfq->status,
            'workflowState' => $rfq->workflow_state,
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
