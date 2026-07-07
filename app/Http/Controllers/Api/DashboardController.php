<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\MRF;
use App\Models\Quotation;
use App\Models\RFQ;
use App\Models\SRF;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Services\DashboardStatsCache;
use App\Services\Finance\FinanceRoutingService;
use App\Services\WorkflowStateService;
use App\Support\ProcurementOverviewAccess;
use App\Support\UserRoleNormalizer;
use App\Support\VendorCategoryDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Get Procurement Manager Dashboard
     */
    public function procurementManagerDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission - procurement managers, executives, and logistics overview roles
        $allowedRoles = array_merge(
            ProcurementOverviewAccess::MANAGEMENT_ROLES,
            ProcurementOverviewAccess::OVERVIEW_ROLES,
            ['logistics_officer'],
        );
        
        $hasAllowedRole =
            (UserRoleNormalizer::supplyChainRole($user) !== null && in_array($user->scmRole(), $allowedRoles)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (!$hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $listLimit = $this->dashboardListLimit($request);

        // Get pending vendor registrations
        $pendingRegistrations = VendorRegistration::where('status', 'Pending')
            ->orderBy('created_at', 'desc')
            ->limit($listLimit)
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
        $pendingMRFs = MRF::query()
            ->select(array_values(array_intersect(
                ['id', 'mrf_id', 'formatted_id', 'title', 'category', 'urgency', 'requester_name', 'estimated_cost', 'created_at', 'source', 'is_po_linked', 'linked_po_id', 'po_draft_saved_at', 'unsigned_po_url'],
                MRF::resolveListApiSelect(),
            )))
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->limit($listLimit)
            ->get()
            ->map(function($mrf) {
                return array_merge($mrf->poOriginApiFields(), $mrf->poDraftApiFields(), [
                    'id' => $mrf->id,
                    'mrfId' => $mrf->mrf_id,
                    'title' => $mrf->title,
                    'category' => $mrf->category,
                    'urgency' => $mrf->urgency,
                    'requesterName' => $mrf->requester_name,
                    'estimatedCost' => $mrf->estimated_cost,
                    'createdAt' => $mrf->created_at->toIso8601String(),
                ]);
            });

        // Get pending SRFs
        $pendingSRFs = SRF::query()
            ->select(array_values(array_intersect(
                ['id', 'srf_id', 'formatted_id', 'title', 'requester_name', 'created_at'],
                SRF::resolveListApiSelect(),
            )))
            ->where('status', 'Pending')
            ->orderBy('created_at', 'desc')
            ->limit($listLimit)
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
            ->limit($listLimit)
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

        $stats = DashboardStatsCache::remember('dashboard.procurement_manager.stats', function () {
            $pendingRegistrationsCount = VendorRegistration::where('status', 'Pending')->count();
            $totalVendorsCount = Vendor::where('status', 'Active')->count();
            $avgRating = Vendor::where('status', 'Active')
                ->whereNotNull('rating')
                ->where('rating', '>', 0)
                ->avg('rating') ?? 0;

            $onTimeRow = DB::table('quotations')
                ->join('r_f_q_s as rfqs', 'quotations.rfq_id', '=', 'rfqs.id')
                ->where('quotations.status', 'Approved')
                ->whereNotNull('quotations.delivery_date')
                ->whereNotNull('rfqs.deadline')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN quotations.delivery_date <= rfqs.deadline THEN 1 ELSE 0 END) as on_time')
                ->first();

            $totalDeliveries = (int) ($onTimeRow->total ?? 0);
            $onTimeCount = (int) ($onTimeRow->on_time ?? 0);

            return [
                'pendingRegistrations' => $pendingRegistrationsCount,
                'pendingMRFs' => MRF::where('status', 'pending')->count(),
                'pendingSRFs' => SRF::where('status', 'Pending')->count(),
                'pendingQuotations' => Quotation::where('status', 'Pending')->count(),
                'totalVendors' => $totalVendorsCount,
                'pendingKYC' => $pendingRegistrationsCount,
                'awaitingReview' => $pendingRegistrationsCount,
                'avgRating' => round((float) $avgRating, 2),
                'onTimeDelivery' => $totalDeliveries > 0
                    ? round(($onTimeCount / $totalDeliveries) * 100, 1)
                    : 0,
            ];
        });

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'listLimit' => $listLimit,
            'pendingRegistrations' => $pendingRegistrations,
            'pendingMRFs' => $pendingMRFs,
            'pendingSRFs' => $pendingSRFs,
            'pendingQuotations' => $pendingQuotations,
            'readOnly' => ProcurementOverviewAccess::isProcurementOverviewOnly($user),
            'isProcurementOverviewOnly' => ProcurementOverviewAccess::isProcurementOverviewOnly($user),
            'canManageProcurement' => ! ProcurementOverviewAccess::isProcurementOverviewOnly($user),
        ]);
    }

    /**
     * Get Supply Chain Director Dashboard
     */
    public function supplyChainDirectorDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission (include `supply_chain` — same alias used on SRF/MRF approval routes)
        $allowedRoles = ['supply_chain_director', 'supply_chain', 'director', 'admin'];
        $hasAllowedRole =
            (UserRoleNormalizer::supplyChainRole($user) !== null && in_array($user->scmRole(), $allowedRoles)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (!$hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $listLimit = $this->dashboardListLimit($request);

        // SRFs waiting on Supply Chain Director (not returned here before — frontends that only read this dashboard never saw them)
        $srfsAwaitingSupplyChainDirectorApproval = SRF::query()
            ->select(array_values(array_filter([
                'id', 'srf_id', 'formatted_id', 'title', 'service_type', 'description', 'justification',
                'duration', 'department', 'requester_id', 'requester_name', 'current_stage',
                'urgency', 'estimated_cost', 'created_at', 'date',
            ], fn (string $column): bool => Schema::hasColumn('s_r_f_s', $column))))
            ->where('status', 'Pending')
            ->where('current_stage', 'supply_chain_director_review')
            ->with(['requester:id,name,email'])
            ->orderByDesc('created_at')
            ->limit($listLimit)
            ->get()
            ->map(function (SRF $srf) {
                $requesterName = $srf->requester_name
                    ?: ($srf->relationLoaded('requester') && $srf->requester ? $srf->requester->name : null);

                return [
                    'id' => $srf->id,
                    'srfId' => $srf->srf_id,
                    'formattedId' => $srf->formatted_id,
                    'title' => $srf->title,
                    'serviceType' => $srf->service_type,
                    'service_type' => $srf->service_type,
                    'description' => $srf->description,
                    'justification' => $srf->justification,
                    'duration' => $srf->duration,
                    'department' => $srf->department,
                    'requesterName' => $requesterName,
                    'requester_name' => $requesterName,
                    'requester' => [
                        'id' => (int) $srf->requester_id,
                        'name' => $requesterName,
                        'email' => $srf->requester?->email,
                    ],
                    'currentStage' => $srf->current_stage,
                    'current_stage' => $srf->current_stage,
                    'urgency' => $srf->urgency,
                    'estimatedCost' => $srf->estimated_cost !== null ? (float) $srf->estimated_cost : null,
                    'estimated_cost' => $srf->estimated_cost !== null ? (float) $srf->estimated_cost : null,
                    'createdAt' => $srf->created_at->toIso8601String(),
                    'submittedDate' => $srf->date ? $srf->date->format('Y-m-d') : null,
                ];
            });

        // Get all vendor registrations (pending and recent)
        $recentRegistrations = VendorRegistration::with(['vendor', 'approver'])
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

        $stats = DashboardStatsCache::remember('dashboard.supply_chain_director.stats', fn () => [
            'totalVendors' => Vendor::count(),
            'activeVendors' => Vendor::where('status', 'Active')->count(),
            'pendingRegistrations' => VendorRegistration::where('status', 'Pending')->count(),
            'approvedRegistrations' => VendorRegistration::where('status', 'Approved')->count(),
            'rejectedRegistrations' => VendorRegistration::where('status', 'Rejected')->count(),
            'totalMRFs' => MRF::count(),
            'pendingMRFs' => MRF::where('status', 'pending')->count(),
            'totalRFQs' => RFQ::count(),
            'activeRFQs' => RFQ::where('status', 'Active')->count(),
            'totalQuotations' => Quotation::count(),
            'pendingQuotations' => Quotation::where('status', 'Pending')->count(),
            'pendingSrfDirectorApprovals' => SRF::where('status', 'Pending')
                ->where('current_stage', 'supply_chain_director_review')
                ->count(),
        ]);

        $metrics = DashboardStatsCache::remember('dashboard.supply_chain_director.metrics', fn () => [
            'averageQuotationAmount' => Quotation::where('status', 'Approved')->avg('price') ?? 0,
            'totalApprovedQuotations' => Quotation::where('status', 'Approved')->count(),
            'totalApprovedMRFs' => MRF::where(function ($q): void {
                $q->where('executive_approved', true)
                    ->orWhereNotNull('director_approved_at')
                    ->orWhereIn('workflow_state', [
                        WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
                        WorkflowStateService::STATE_PROCUREMENT_REVIEW,
                        WorkflowStateService::STATE_PROCUREMENT_APPROVED,
                        WorkflowStateService::STATE_RFQ_ISSUED,
                        WorkflowStateService::STATE_QUOTATIONS_RECEIVED,
                        WorkflowStateService::STATE_QUOTATIONS_EVALUATED,
                        WorkflowStateService::STATE_PO_GENERATED,
                        WorkflowStateService::STATE_PO_SIGNED,
                        WorkflowStateService::STATE_CLOSED,
                    ]);
            })->count(),
        ]);

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'metrics' => $metrics,
            'recentRegistrations' => $recentRegistrations,
            'srfsAwaitingSupplyChainDirectorApproval' => $srfsAwaitingSupplyChainDirectorApproval,
        ]);
    }

    /**
     * Get Vendor Dashboard
     */
    public function vendorDashboard(Request $request)
    {
        $user = $request->user();

        // Check permission
        $isVendor =
            ($user->scmRole() === 'vendor') ||
            (method_exists($user, 'hasRole') && $user->hasRole('vendor'));

        if (!$isVendor) {
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
            ->with(['registrationDocuments'])
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
                'categoryDisplay' => VendorCategoryDisplay::format($vendor->category, $vendor->category_other),
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'address' => $vendor->address,
                'contactPerson' => $vendor->contact_person,
                'status' => $vendor->status,
            ],
            'registration' => $registration ? [
                'id' => $registration->id,
                'documents' => $registration->getDocumentsMetadataList(),
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

        $allowedRoles = ['finance', 'finance_officer', 'admin'];
        $hasAllowedRole =
            (UserRoleNormalizer::supplyChainRole($user) !== null && in_array($user->scmRole(), $allowedRoles)) ||
            (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles));

        if (! $hasAllowedRole) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $routing = app(FinanceRoutingService::class);
        $eager = $this->financeDashboardEager();
        $perPage = $this->dashboardPerPage($request, default: 50, max: 100);
        $listSelect = $this->financeDashboardSelect();
        $page = max(1, (int) $request->query('page', 1));

        $mapMrf = function (MRF $mrf) use ($routing) {
            $rfq = $mrf->rfqs->first();
            $selectedQuotation = $rfq?->selectedQuotation;
            $meta = $routing->routingMeta($mrf);

            return array_merge($meta, $mrf->poOriginApiFields(), $mrf->poDraftApiFields(), [
                'id' => $mrf->id,
                'mrfId' => $mrf->mrf_id,
                'title' => $mrf->title,
                'category' => $mrf->category,
                'contractType' => $mrf->contract_type,
                'workflowState' => $mrf->workflow_state,
                'status' => $mrf->status,
                'currentStage' => $mrf->current_stage,
                'estimatedCost' => (float) $mrf->estimated_cost,
                'currency' => $mrf->currency ?? 'NGN',
                'requester' => $mrf->requester ? [
                    'id' => $mrf->requester->id,
                    'name' => $mrf->requester->name,
                    'email' => $mrf->requester->email,
                ] : null,
                'poNumber' => $mrf->po_number,
                'unsignedPoUrl' => $mrf->freshUnsignedPoStreamUrl() ?? $mrf->unsigned_po_url,
                'signedPoUrl' => $mrf->signed_po_url,
                'signedPoShareUrl' => $mrf->signed_po_share_url,
                'poGeneratedAt' => $mrf->po_generated_at?->toIso8601String(),
                'poSignedAt' => $mrf->po_signed_at?->toIso8601String(),
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
                    'paymentTerms' => $selectedQuotation->payment_terms ?? null,
                    'deliveryDate' => $selectedQuotation->delivery_date?->format('Y-m-d'),
                    'validityDays' => $selectedQuotation->validity_days ?? null,
                    'warrantyPeriod' => $selectedQuotation->warranty_period ?? null,
                ] : null,
                'rfqId' => $rfq?->rfq_id,
                'rfqTitle' => $rfq?->getDisplayTitle(),
                'paymentStatus' => $mrf->payment_status,
                'paymentProcessedAt' => $mrf->payment_processed_at?->toIso8601String(),
                'financeApCaseId' => $mrf->finance_ap_case_id,
                'financeApStatus' => $mrf->finance_ap_status,
                'canProcessPaymentInternal' => ! $meta['usesFinanceAp'],
                'financeSyncPath' => $meta['usesFinanceAp'] ? '/api/mrfs/'.$mrf->mrf_id.'/finance-sync' : null,
                'executiveApproved' => (bool) $mrf->executive_approved,
                'executiveApprovedAt' => $mrf->executive_approved_at?->toIso8601String(),
                'createdAt' => $mrf->created_at->toIso8601String(),
            ]);
        };

        $unifiedQuery = MRF::query();
        $routing->scopeAnyFinanceReady($unifiedQuery);
        $financePaginated = (clone $unifiedQuery)
            ->select($listSelect)
            ->with($eager)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $financeMRFs = collect($financePaginated->items())->map($mapMrf)->values();

        $stats = DashboardStatsCache::remember('dashboard.finance.stats', fn () => $routing->computeDashboardStats());

        return response()->json([
            'success' => true,
            'routing' => [
                'cutoverDate' => $routing->cutoverDate()?->toDateString(),
                'routingConfigured' => $routing->isRoutingConfigured(),
                'description' => 'MRFs created on or after cutoverDate use Finance AP; earlier MRFs use internal chairman payment flow in SCM.',
            ],
            'stats' => $stats,
            'pagination' => [
                'perPage' => $perPage,
                'financeMRFs' => $this->paginationMeta($financePaginated),
            ],
            'financeMRFs' => $financeMRFs,
        ]);
    }

    /**
     * Get recent activities for the authenticated user
     * Endpoint: GET /api/dashboard/recent-activities?limit={limit}
     * 
     * Returns activities where:
     * 1. The user performed the action (user_id matches)
     * 2. The activity relates to entities the user owns/is involved in (MRFs they created, RFQs they manage, etc.)
     */
    public function getRecentActivities(Request $request)
    {
        $user = $request->user();
        $limit = max(1, min((int) $request->query('limit', 20), 100));

        $vendorId = null;
        if ($user->scmRole() === 'vendor') {
            $vendor = Vendor::where('email', $user->email)->first();
            if ($vendor) {
                $vendorId = $vendor->id;
            }
        }

        $role = $user->scmRole();

        $activities = Activity::query()
            ->where(function ($q) use ($user, $vendorId, $role) {
                $q->where('user_id', $user->id);

                $q->orWhere(function ($mrfActivity) use ($user, $role) {
                    $mrfActivity->where('entity_type', 'mrf')
                        ->whereExists(function ($sub) use ($user, $role) {
                            $sub->selectRaw('1')
                                ->from('m_r_f_s')
                                ->whereColumn('m_r_f_s.mrf_id', 'activities.entity_id')
                                ->where(function ($mrfScope) use ($user, $role) {
                                    $mrfScope->where('m_r_f_s.requester_id', $user->id);

                                    if (in_array($role, ['procurement_manager', 'procurement', 'admin'], true)) {
                                        $mrfScope->orWhere(function ($procurement) {
                                            $procurement->whereIn('m_r_f_s.status', [
                                                'procurement',
                                                'pending_po_upload',
                                                'procurement_review',
                                                'revision_required',
                                            ])->orWhere('m_r_f_s.current_stage', 'procurement');
                                        });
                                    }

                                    if (in_array($role, ['finance', 'finance_officer', 'admin'], true)) {
                                        $mrfScope->orWhere(function ($finance) {
                                            $finance->whereIn('m_r_f_s.status', ['finance', 'chairman_payment'])
                                                ->orWhere('m_r_f_s.current_stage', 'finance')
                                                ->orWhereIn('m_r_f_s.workflow_state', [
                                                    WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
                                                    WorkflowStateService::STATE_FINANCE_IN_REVIEW,
                                                    WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
                                                    WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
                                                ]);
                                        });
                                    }

                                    if (in_array($role, ['supply_chain_director', 'supply_chain', 'admin'], true)) {
                                        $mrfScope->orWhere(function ($scd) {
                                            $scd->whereIn('m_r_f_s.status', [
                                                'supply_chain',
                                                'vendor_selected',
                                                'vendor_approved',
                                                'awaiting_scd_signature',
                                            ])->orWhere('m_r_f_s.current_stage', 'supply_chain')
                                                ->orWhereIn('m_r_f_s.workflow_state', [
                                                    WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW,
                                                    WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED,
                                                ]);
                                        });
                                    }
                                });
                        });
                });

                if ($vendorId) {
                    $q->orWhere(function ($vendorQ) use ($vendorId) {
                        $vendorQ->where('entity_type', 'quotation')
                            ->whereExists(function ($sub) use ($vendorId) {
                                $sub->selectRaw('1')
                                    ->from('quotations')
                                    ->whereColumn('quotations.quotation_id', 'activities.entity_id')
                                    ->where('quotations.vendor_id', $vendorId);
                            });
                    });
                }
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => (string) $activity->id,
                    'type' => $activity->type,
                    'title' => $activity->title,
                    'description' => $activity->description,
                    'timestamp' => $activity->created_at->toIso8601String(),
                    'user' => $activity->user_name,
                    'entityId' => $activity->entity_id,
                    'entityType' => $activity->entity_type,
                    'status' => $activity->status,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $activities
        ]);
    }

    private function dashboardListLimit(Request $request, int $default = 30, int $max = 50): int
    {
        $limit = (int) $request->query('list_limit', $default);

        return max(1, min($limit, $max));
    }

    private function dashboardPerPage(Request $request, int $default = 50, int $max = 100): int
    {
        $perPage = (int) $request->query('per_page', $request->query('perPage', $default));

        return max(1, min($perPage, $max));
    }

    /**
     * Columns loaded for finance dashboard list rows.
     *
     * @return list<string>
     */
    private function financeDashboardSelect(): array
    {
        return [
            'id', 'mrf_id', 'formatted_id', 'title', 'category', 'contract_type', 'workflow_state',
            'status', 'current_stage', 'estimated_cost', 'currency', 'requester_id', 'po_number',
            'unsigned_po_url', 'signed_po_url', 'signed_po_share_url', 'po_generated_at', 'po_signed_at',
            'selected_vendor_id', 'payment_status', 'payment_processed_at', 'finance_ap_case_id',
            'finance_ap_status', 'executive_approved', 'executive_approved_at', 'created_at',
        ];
    }

    /**
     * Minimal relations for finance dashboard rows (avoids loading every quotation per RFQ).
     *
     * @return list<string|array<int|string, mixed>>
     */
    private function financeDashboardEager(): array
    {
        return [
            'requester',
            'selectedVendor',
            'executiveApprover',
            'rfqs' => fn ($query) => $query
                ->with('selectedQuotation')
                ->orderByDesc('created_at')
                ->limit(1),
        ];
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator
     * @return array<string, int>
     */
    private function paginationMeta($paginator): array
    {
        return [
            'total' => $paginator->total(),
            'perPage' => $paginator->perPage(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
