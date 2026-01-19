<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\Quotation;
use App\Models\RFQ;
use App\Models\SRF;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get Procurement Manager Dashboard
     */
    public function procurementManagerDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission - allow procurement manager and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get pending vendor registrations
        $pendingRegistrations = VendorRegistration::where('status', 'Pending')
            ->with(['documents'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($reg) {
                return [
                    'id' => $reg->id,
                    'companyName' => $reg->company_name,
                    'category' => $reg->category,
                    'email' => $reg->email,
                    'contactPerson' => $reg->contact_person,
                    'createdAt' => $reg->created_at->toIso8601String(),
                ];
            });

        // Get pending MRFs
        $pendingMRFs = MRF::where('status', 'Pending')
            ->with(['requester'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($mrf) {
                return [
                    'id' => $mrf->id,
                    'mrfId' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'urgency' => $mrf->urgency,
                    'requesterName' => $mrf->requester_name,
                    'estimatedCost' => $mrf->estimated_cost,
                    'createdAt' => $mrf->created_at->toIso8601String(),
                ];
            });

        // Get pending SRFs
        $pendingSRFs = SRF::where('status', 'Pending')
            ->with(['requester'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($srf) {
                return [
                    'id' => $srf->id,
                    'srfId' => $srf->srf_id,
                    'title' => $srf->title,
                    'category' => $srf->category,
                    'requesterName' => $srf->requester_name,
                    'createdAt' => $srf->created_at->toIso8601String(),
                ];
            });

        // Get pending quotations
        $pendingQuotations = Quotation::where('status', 'Pending')
            ->with(['rfq', 'vendor'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($quote) {
                return [
                    'id' => $quote->id,
                    'quotationId' => $quote->quotation_id,
                    'rfqId' => $quote->rfq ? $quote->rfq->rfq_id : null,
                    'vendorName' => $quote->vendor ? $quote->vendor->name : null,
                    'amount' => $quote->price,
                    'createdAt' => $quote->created_at->toIso8601String(),
                ];
            });

        // Statistics
        $pendingRegistrationsCount = VendorRegistration::where('status', 'Pending')->count();
        $activeVendors = Vendor::where('status', 'Active')->get();
        
        // Calculate average rating from active vendors (only those with ratings)
        $vendorsWithRatings = $activeVendors->filter(function($vendor) {
            return $vendor->rating !== null && $vendor->rating > 0;
        });
        $avgRating = $vendorsWithRatings->count() > 0 
            ? $vendorsWithRatings->avg('rating') 
            : 0;
        
        // Calculate on-time delivery percentage
        // Compare delivery_date with RFQ deadline for approved quotations
        $approvedQuotations = Quotation::where('status', 'Approved')
            ->with('rfq')
            ->get();
        
        $onTimeCount = 0;
        $totalDeliveries = $approvedQuotations->count();
        
        foreach ($approvedQuotations as $quote) {
            if ($quote->rfq && $quote->delivery_date && $quote->rfq->deadline) {
                // If delivery_date is on or before deadline, it's on-time
                if ($quote->delivery_date <= $quote->rfq->deadline) {
                    $onTimeCount++;
                }
            }
        }
        
        $onTimeDeliveryPercentage = $totalDeliveries > 0 
            ? round(($onTimeCount / $totalDeliveries) * 100, 1) 
            : 0;

        $stats = [
            'pendingRegistrations' => $pendingRegistrationsCount,
            'pendingMRFs' => MRF::where('status', 'Pending')->count(),
            'pendingSRFs' => SRF::where('status', 'Pending')->count(),
            'pendingQuotations' => Quotation::where('status', 'Pending')->count(),
            'totalVendors' => $activeVendors->count(),
            'pendingKYC' => $pendingRegistrationsCount, // Same as pending registrations
            'awaitingReview' => $pendingRegistrationsCount, // Same as pending registrations
            'avgRating' => round((float) $avgRating, 2),
            'onTimeDelivery' => $onTimeDeliveryPercentage,
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'pendingRegistrations' => $pendingRegistrations,
            'pendingMRFs' => $pendingMRFs,
            'pendingSRFs' => $pendingSRFs,
            'pendingQuotations' => $pendingQuotations,
        ]);
    }

    /**
     * Get Supply Chain Director Dashboard
     */
    public function supplyChainDirectorDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission
        if (!in_array($user->role, ['supply_chain_director', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get all vendor registrations (pending and recent)
        $recentRegistrations = VendorRegistration::with(['vendor', 'approver', 'documents'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function($reg) {
                return [
                    'id' => $reg->id,
                    'companyName' => $reg->company_name,
                    'category' => $reg->category,
                    'status' => $reg->status,
                    'approvedBy' => $reg->approver ? $reg->approver->name : null,
                    'approvedAt' => $reg->approved_at ? $reg->approved_at->toIso8601String() : null,
                    'createdAt' => $reg->created_at->toIso8601String(),
                ];
            });

        // Get high-level statistics
        $stats = [
            'totalVendors' => Vendor::count(),
            'activeVendors' => Vendor::where('status', 'Active')->count(),
            'pendingRegistrations' => VendorRegistration::where('status', 'Pending')->count(),
            'approvedRegistrations' => VendorRegistration::where('status', 'Approved')->count(),
            'rejectedRegistrations' => VendorRegistration::where('status', 'Rejected')->count(),
            'totalMRFs' => MRF::count(),
            'pendingMRFs' => MRF::where('status', 'Pending')->count(),
            'totalRFQs' => RFQ::count(),
            'activeRFQs' => RFQ::where('status', 'Active')->count(),
            'totalQuotations' => Quotation::count(),
            'pendingQuotations' => Quotation::where('status', 'Pending')->count(),
        ];

        // Get procurement metrics
        $metrics = [
            'averageQuotationAmount' => Quotation::where('status', 'Approved')->avg('price') ?? 0,
            'totalApprovedQuotations' => Quotation::where('status', 'Approved')->count(),
            'totalApprovedMRFs' => MRF::where('status', 'Approved')->count(),
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'metrics' => $metrics,
            'recentRegistrations' => $recentRegistrations,
        ]);
    }

    /**
     * Get Vendor Dashboard
     */
    public function vendorDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission
        if ($user->role !== 'vendor') {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get vendor information - try multiple methods
        $vendor = null;
        
        // Method 1: Try vendor relationship
        if ($user->vendor_id && method_exists($user, 'vendor')) {
            $vendor = $user->vendor;
        }
        
        // Method 2: Find vendor by vendor_id if relationship didn't work
        if (!$vendor && $user->vendor_id) {
            $vendor = \App\Models\Vendor::find($user->vendor_id);
        }
        
        // Method 3: Try finding vendor by email as last resort
        if (!$vendor) {
            $vendor = \App\Models\Vendor::where('email', $user->email)->first();
        }
        
        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor information not found. Please ensure your account is linked to a vendor profile.',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get vendor's registration information
        $registration = VendorRegistration::where('vendor_id', $vendor->id)
            ->with(['documents'])
            ->first();

        // Get RFQs assigned to this vendor
        $assignedRFQs = RFQ::whereHas('vendors', function($query) use ($vendor) {
                $query->where('vendors.id', $vendor->id);
            })
            ->with(['mrf'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($rfq) {
                return [
                    'id' => $rfq->id,
                    'rfqId' => $rfq->rfq_id,
                    'mrfTitle' => $rfq->mrf_title,
                    'description' => $rfq->description,
                    'quantity' => $rfq->quantity,
                    'deadline' => $rfq->deadline ? $rfq->deadline->toDateString() : null,
                    'status' => $rfq->status,
                    'createdAt' => $rfq->created_at->toIso8601String(),
                ];
            });

        // Get vendor's quotations
        $quotations = Quotation::where('vendor_id', $vendor->id)
            ->with(['rfq'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($quote) {
                return [
                    'id' => $quote->id,
                    'quotationId' => $quote->quotation_id,
                    'rfqId' => $quote->rfq ? $quote->rfq->rfq_id : null,
                    'amount' => $quote->price,
                    'status' => $quote->status,
                    'submittedAt' => $quote->created_at->toIso8601String(),
                ];
            });

        // Vendor statistics
        $stats = [
            'assignedRFQs' => $assignedRFQs->count(),
            'pendingQuotations' => Quotation::where('vendor_id', $vendor->id)
                ->where('status', 'Pending')
                ->count(),
            'approvedQuotations' => Quotation::where('vendor_id', $vendor->id)
                ->where('status', 'Approved')
                ->count(),
            'rejectedQuotations' => Quotation::where('vendor_id', $vendor->id)
                ->where('status', 'Rejected')
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'vendor' => [
                'id' => $vendor->vendor_id,
                'companyName' => $vendor->name,
                'category' => $vendor->category,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'address' => $vendor->address,
                'contactPerson' => $vendor->contact_person,
                'status' => $vendor->status,
            ],
            'registration' => $registration ? [
                'id' => $registration->id,
                'documents' => $registration->documents,
            ] : null,
            'stats' => $stats,
            'assignedRFQs' => $assignedRFQs,
            'quotations' => $quotations,
        ]);
    }

    /**
     * Get Finance Dashboard
     * Shows only MRFs that have legitimately reached the finance stage
     */
    public function financeDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission - only finance team can access
        if (!in_array($user->role, ['finance', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get MRFs that have reached finance stage (have signed PO)
        // Only show real, live data - no dummy/placeholder data
        $financeMRFs = MRF::where(function($query) {
                $query->where('status', 'finance')
                    ->orWhere('current_stage', 'finance');
            })
            ->whereNotNull('signed_po_url') // Must have signed PO - legitimate finance stage
            ->with([
                'requester',
                'selectedVendor',
                'rfqs.selectedQuotation',
                'rfqs.quotations.vendor',
                'executiveApprover',
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($mrf) {
                $rfq = $mrf->rfqs->first();
                $selectedQuotation = $rfq ? $rfq->selectedQuotation : null;
                
                return [
                    'id' => $mrf->id,
                    'mrfId' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'contractType' => $mrf->contract_type,
                    'estimatedCost' => (float) $mrf->estimated_cost,
                    'currency' => $mrf->currency ?? 'NGN',
                    'requester' => $mrf->requester ? [
                        'id' => $mrf->requester->id,
                        'name' => $mrf->requester->name,
                        'email' => $mrf->requester->email,
                    ] : null,
                    'poNumber' => $mrf->po_number,
                    'unsignedPoUrl' => $mrf->unsigned_po_url,
                    'signedPoUrl' => $mrf->signed_po_url,
                    'signedPoShareUrl' => $mrf->signed_po_share_url,
                    'poGeneratedAt' => $mrf->po_generated_at ? $mrf->po_generated_at->toIso8601String() : null,
                    'poSignedAt' => $mrf->po_signed_at ? $mrf->po_signed_at->toIso8601String() : null,
                    'selectedVendor' => $mrf->selectedVendor ? [
                        'id' => $mrf->selectedVendor->vendor_id,
                        'name' => $mrf->selectedVendor->name,
                        'email' => $mrf->selectedVendor->email,
                        'phone' => $mrf->selectedVendor->phone,
                        'address' => $mrf->selectedVendor->address,
                    ] : null,
                    'selectedQuotation' => $selectedQuotation ? [
                        'id' => $selectedQuotation->quotation_id,
                        'totalAmount' => (float) $selectedQuotation->total_amount,
                        'currency' => $selectedQuotation->currency ?? 'NGN',
                        'paymentTerms' => $selectedQuotation->payment_terms,
                        'deliveryDate' => $selectedQuotation->delivery_date ? $selectedQuotation->delivery_date->format('Y-m-d') : null,
                        'validityDays' => $selectedQuotation->validity_days,
                        'warrantyPeriod' => $selectedQuotation->warranty_period,
                    ] : null,
                    'rfqId' => $rfq ? $rfq->rfq_id : null,
                    'rfqTitle' => $rfq ? $rfq->getDisplayTitle() : null,
                    'paymentStatus' => $mrf->payment_status,
                    'paymentProcessedAt' => $mrf->payment_processed_at ? $mrf->payment_processed_at->toIso8601String() : null,
                    'executiveApproved' => (bool) $mrf->executive_approved,
                    'executiveApprovedAt' => $mrf->executive_approved_at ? $mrf->executive_approved_at->toIso8601String() : null,
                    'createdAt' => $mrf->created_at->toIso8601String(),
                ];
            });

        // Get pending payments (MRFs in finance stage awaiting processing)
        $pendingPayments = MRF::where('status', 'finance')
            ->where('current_stage', 'finance')
            ->whereNotNull('signed_po_url')
            ->whereNull('payment_processed_at')
            ->count();

        // Get processed payments (awaiting chairman approval)
        $processedPayments = MRF::where('status', 'chairman_payment')
            ->where('payment_status', 'processing')
            ->count();

        // Get approved payments
        $approvedPayments = MRF::where('payment_status', 'approved')
            ->count();

        // Calculate financial metrics
        $totalPendingAmount = MRF::where('status', 'finance')
            ->whereNotNull('signed_po_url')
            ->whereNull('payment_processed_at')
            ->whereHas('selectedQuotation')
            ->get()
            ->sum(function($mrf) {
                return (float) ($mrf->selectedQuotation->total_amount ?? $mrf->estimated_cost ?? 0);
            });

        $totalProcessedAmount = MRF::where('status', 'chairman_payment')
            ->where('payment_status', 'processing')
            ->whereHas('selectedQuotation')
            ->get()
            ->sum(function($mrf) {
                return (float) ($mrf->selectedQuotation->total_amount ?? $mrf->estimated_cost ?? 0);
            });

        $totalApprovedAmount = MRF::where('payment_status', 'approved')
            ->whereHas('selectedQuotation')
            ->get()
            ->sum(function($mrf) {
                return (float) ($mrf->selectedQuotation->total_amount ?? $mrf->estimated_cost ?? 0);
            });

        // Statistics
        $stats = [
            'totalFinanceMRFs' => $financeMRFs->count(),
            'pendingPayments' => $pendingPayments,
            'processedPayments' => $processedPayments,
            'approvedPayments' => $approvedPayments,
            'totalPendingAmount' => (float) $totalPendingAmount,
            'totalProcessedAmount' => (float) $totalProcessedAmount,
            'totalApprovedAmount' => (float) $totalApprovedAmount,
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'financeMRFs' => $financeMRFs,
        ]);
    }
}
