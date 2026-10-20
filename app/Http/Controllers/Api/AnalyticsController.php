<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Services\Analytics\AnalyticsClassificationService;
use App\Services\Analytics\ScmKpiService;
use App\Services\ScmAuditService;
use App\Support\AnalyticsBaseline;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics API — baseline configuration, unified KPIs, reconciliation lists.
 *
 * Routes:
 *   GET  /api/analytics/baseline         — baseline config (all authenticated)
 *   GET  /api/analytics/kpis             — period KPIs (management roles)
 *   GET  /api/analytics/legacy           — unreconciled legacy list (admin/scd)
 *   PUT  /api/analytics/legacy/{mrf_id}  — classify a single MRF (admin/scd)
 */
class AnalyticsController extends Controller
{
    private const MANAGEMENT_ROLES = [
        'admin',
        'supply_chain_director',
        'executive',
        'chairman',
        'procurement_manager',
    ];

    private const RECONCILIATION_ROLES = [
        'admin',
        'supply_chain_director',
    ];

    public function __construct(
        private readonly ScmKpiService $kpi,
        private readonly AnalyticsClassificationService $classification,
        private readonly ScmAuditService $audit,
    ) {
    }

    /**
     * GET /api/analytics/baseline
     * Returns baseline config — useful for all authenticated views.
     */
    public function baseline(Request $request): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'baseline' => AnalyticsBaseline::config(),
        ]);
    }

    /**
     * GET /api/analytics/kpis?from=&to=
     * Unified KPI payload for all command-centre dashboards.
     */
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->hasRole($user, self::MANAGEMENT_ROLES)) {
            return $this->forbidden();
        }

        $from = $request->get('from')
            ? Carbon::parse($request->get('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        $to = $request->get('to')
            ? Carbon::parse($request->get('to'))->endOfDay()
            : now()->endOfDay();

        $payload = $this->kpi->buildKpiPayload($from, $to);

        return response()->json([
            'success' => true,
            'data'    => $payload,
        ]);
    }

    /**
     * GET /api/analytics/legacy?page=1&per_page=50
     * Returns unreconciled legacy MRFs for the reconciliation UI.
     */
    public function legacyList(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->hasRole($user, self::RECONCILIATION_ROLES)) {
            return $this->forbidden();
        }

        $perPage = min(100, max(10, (int) $request->get('per_page', 50)));

        $query = MRF::query()
            ->select([
                'id',
                'mrf_id',
                'formatted_id',
                'title',
                'workflow_state',
                'analytics_class',
                'reconciliation_status',
                'created_at',
                'updated_at',
                'original_business_date',
                'handled_offline',
                'reconciled_at',
                'reconciliation_notes',
            ]);

        $this->classification->scopeUnreconciledLegacy($query);

        $paginator = $query->orderBy('created_at')->paginate($perPage);

        return response()->json([
            'success'    => true,
            'data'       => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * PUT /api/analytics/legacy/{mrf_id}
     * Classify a single MRF (reconciliation workflow).
     * Preserves all original values; every change is audit-logged.
     */
    public function classifyMrf(Request $request, string $mrfId): JsonResponse
    {
        $user = $request->user();

        if (! $this->hasRole($user, self::RECONCILIATION_ROLES)) {
            return $this->forbidden();
        }

        $mrf = MRF::where('mrf_id', $mrfId)->orWhere('id', $mrfId)->firstOrFail();

        $validated = $request->validate([
            'analytics_class'       => 'required|in:new_period,verified_historical,unreconciled_legacy',
            'original_business_date' => 'nullable|date',
            'handled_offline'       => 'nullable|boolean',
            'reconciliation_notes'  => 'nullable|string|max:2000',
        ]);

        $before = [
            'analytics_class'        => $mrf->analytics_class,
            'reconciliation_status'  => $mrf->reconciliation_status,
            'original_business_date' => $mrf->original_business_date,
            'handled_offline'        => $mrf->handled_offline,
            'reconciliation_notes'   => $mrf->reconciliation_notes,
        ];

        $this->classification->classify(
            mrf: $mrf,
            class: $validated['analytics_class'],
            originalBusinessDate: $validated['original_business_date'] ?? null,
            handledOffline: $validated['handled_offline'] ?? null,
            notes: $validated['reconciliation_notes'] ?? null,
            reviewedBy: $user->id,
        );

        $after = [
            'analytics_class'        => $mrf->analytics_class,
            'reconciliation_status'  => $mrf->reconciliation_status,
            'original_business_date' => $mrf->original_business_date,
            'handled_offline'        => $mrf->handled_offline,
            'reconciliation_notes'   => $mrf->reconciliation_notes,
        ];

        $this->audit->recordChange(
            action: 'mrf_analytics_classified',
            model: $mrf,
            actor: $user,
            before: $before,
            after: $after,
            description: "MRF {$mrf->mrf_id} classified as {$validated['analytics_class']} by {$user->name}",
            request: $request,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'mrf_id'         => $mrf->mrf_id,
                'analytics_class' => $mrf->analytics_class,
                'reconciled_at'  => $mrf->reconciled_at,
            ],
        ]);
    }

    private function hasRole(?object $user, array $roles): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'scmRole') && in_array($user->scmRole(), $roles, true)) {
            return true;
        }

        if (property_exists($user, 'supply_chain_role') && in_array($user->supply_chain_role, $roles, true)) {
            return true;
        }

        return false;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => 'Insufficient permissions',
            'code'    => 'FORBIDDEN',
        ], 403);
    }
}
