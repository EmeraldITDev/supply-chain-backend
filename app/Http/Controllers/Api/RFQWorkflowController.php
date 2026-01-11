<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RFQ;
use App\Models\RFQItem;
use App\Models\Quotation;
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
     */
    public function getVendorRFQs(Request $request)
    {
        $user = $request->user();
        
        // Get vendor from authenticated user
        $vendor = Vendor::where('user_id', $user->id)->first();
        
        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get RFQs assigned to this vendor with engagement tracking
        $rfqs = RFQ::whereHas('vendors', function ($query) use ($vendor) {
            $query->where('vendor_id', $vendor->id);
        })
        ->with(['items', 'mrf'])
        ->get()
        ->map(function ($rfq) use ($vendor) {
            // Get pivot data for this vendor
            $pivot = $rfq->vendors()->where('vendor_id', $vendor->id)->first()->pivot ?? null;
            
            // Check if vendor has submitted quotation
            $hasSubmitted = Quotation::where('rfq_id', $rfq->id)
                ->where('vendor_id', $vendor->id)
                ->exists();

            return [
                'id' => $rfq->rfq_id,
                'mrf_id' => $rfq->mrf_id ? $rfq->mrf->mrf_id : null,
                'title' => $rfq->mrf_title ?? $rfq->description,
                'description' => $rfq->description,
                'deadline' => $rfq->deadline->format('Y-m-d'),
                'status' => $rfq->status,
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
                'sent_at' => $pivot?->sent_at?->toIso8601String(),
                'viewed_at' => $pivot?->viewed_at?->toIso8601String(),
                'responded' => $pivot?->responded ?? false,
                'responded_at' => $pivot?->responded_at?->toIso8601String(),
                'has_submitted_quote' => $hasSubmitted,
                'created_at' => $rfq->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $rfqs,
        ]);
    }

    /**
     * Mark RFQ as viewed by vendor
     */
    public function markAsViewed(Request $request, $id)
    {
        $user = $request->user();
        $vendor = Vendor::where('user_id', $user->id)->first();
        
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

        $rfq = RFQ::where('rfq_id', $id)->with(['items'])->first();

        if (!$rfq) {
            return response()->json([
                'success' => false,
                'error' => 'RFQ not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get all quotations with items and vendor details
        $quotations = Quotation::where('rfq_id', $rfq->id)
            ->with(['vendor', 'items.rfqItem'])
            ->get()
            ->map(function ($quotation) {
                return [
                    'quotation' => [
                        'id' => $quotation->quotation_id,
                        'quote_number' => $quotation->quote_number,
                        'total_amount' => (float) $quotation->total_amount,
                        'currency' => $quotation->currency,
                        'delivery_days' => $quotation->delivery_days,
                        'delivery_date' => $quotation->delivery_date?->format('Y-m-d'),
                        'payment_terms' => $quotation->payment_terms,
                        'validity_days' => $quotation->validity_days,
                        'warranty_period' => $quotation->warranty_period,
                        'notes' => $quotation->notes,
                        'status' => $quotation->status,
                        'attachments' => $quotation->attachments,
                        'submitted_at' => $quotation->submitted_at?->toIso8601String(),
                    ],
                    'vendor' => [
                        'id' => $quotation->vendor->vendor_id,
                        'name' => $quotation->vendor->name,
                        'email' => $quotation->vendor->email,
                        'phone' => $quotation->vendor->phone,
                        'rating' => (float) $quotation->vendor->rating,
                    ],
                    'items' => $quotation->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'rfq_item_id' => $item->rfq_item_id,
                            'item_name' => $item->item_name,
                            'description' => $item->description,
                            'quantity' => $item->quantity,
                            'unit' => $item->unit,
                            'unit_price' => (float) $item->unit_price,
                            'total_price' => (float) $item->total_price,
                            'specifications' => $item->specifications,
                        ];
                    }),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'rfq' => [
                    'id' => $rfq->rfq_id,
                    'description' => $rfq->description,
                    'deadline' => $rfq->deadline->format('Y-m-d'),
                    'status' => $rfq->status,
                    'items' => $rfq->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'item_name' => $item->item_name,
                            'quantity' => $item->quantity,
                            'unit' => $item->unit,
                        ];
                    }),
                ],
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

            return response()->json([
                'success' => true,
                'message' => 'Vendor selected successfully',
                'data' => [
                    'rfq_id' => $rfq->rfq_id,
                    'status' => $rfq->status,
                    'selected_vendor' => [
                        'id' => $selectedQuotation->vendor->vendor_id,
                        'name' => $selectedQuotation->vendor->name,
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
