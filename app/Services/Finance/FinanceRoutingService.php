<?php

namespace App\Services\Finance;

use App\Models\MRF;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single place for cutover-based finance routing (Phase 7).
 * All routing uses mrfUsesFinanceAp() / created_at vs FINANCE_AP_CUTOVER_DATE only.
 */
class FinanceRoutingService
{
    /**
     * Workflow states where a post-cutover MRF is in the Finance AP finance pipeline.
     *
     * @return list<string>
     */
    public function financeApPipelineStates(): array
    {
        return [
            WorkflowStateService::STATE_PO_SIGNED,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
            WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING,
            WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            WorkflowStateService::STATE_FINANCIALLY_COMPLETE,
            WorkflowStateService::STATE_OPERATIONALLY_COMPLETE,
        ];
    }

    public function cutoverDate(): ?Carbon
    {
        $cutover = config('finance_ap.cutover_date');

        return $cutover ? Carbon::parse($cutover)->startOfDay() : null;
    }

    public function isRoutingConfigured(): bool
    {
        return $this->cutoverDate() !== null;
    }

    public function usesFinanceAp(MRF $mrf): bool
    {
        return mrfUsesFinanceAp($mrf);
    }

    public function financeRoute(MRF $mrf): string
    {
        return $this->usesFinanceAp($mrf) ? 'finance_ap' : 'legacy_internal';
    }

    public function scopeFinanceApCohort(Builder $query): Builder
    {
        $cutover = $this->cutoverDate();

        if (! $cutover) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('created_at', '>=', $cutover);
    }

    public function scopeLegacyInternalCohort(Builder $query): Builder
    {
        $cutover = $this->cutoverDate();

        if (! $cutover) {
            return $query;
        }

        return $query->where('created_at', '<', $cutover);
    }

    /**
     * Pre-cutover MRFs in internal chairman payment flow (status-based).
     */
    public function scopeLegacyFinanceReady(Builder $query): Builder
    {
        return $this->scopeLegacyInternalCohort($query)
            ->whereNotNull('signed_po_url')
            ->where(function (Builder $q) {
                $q->where('status', 'finance')
                    ->orWhere('current_stage', 'finance')
                    ->orWhere('status', 'chairman_payment')
                    ->orWhere('current_stage', 'chairman_payment');
            });
    }

    /**
     * Post-cutover MRFs in Finance AP workflow (workflow_state-based).
     */
    public function scopeFinanceApFinanceReady(Builder $query): Builder
    {
        return $this->scopeFinanceApCohort($query)
            ->whereNotNull('signed_po_url')
            ->whereIn('workflow_state', $this->financeApPipelineStates());
    }

    /**
     * Unified finance dashboard cohort: legacy internal + Finance AP pipelines.
     */
    public function scopeAnyFinanceReady(Builder $query): Builder
    {
        if (! $this->cutoverDate()) {
            return $this->scopeLegacyFinanceReady($query);
        }

        return $query->where(function (Builder $outer) {
            $outer->where(function (Builder $legacy) {
                $this->scopeLegacyFinanceReady($legacy);
            })->orWhere(function (Builder $fa) {
                $this->scopeFinanceApFinanceReady($fa);
            });
        });
    }

    public function isLegacyFinanceReady(MRF $mrf): bool
    {
        if ($this->usesFinanceAp($mrf) || ! $mrf->signed_po_url) {
            return false;
        }

        return in_array($mrf->status, ['finance', 'chairman_payment'], true)
            || in_array($mrf->current_stage, ['finance', 'chairman_payment'], true);
    }

    public function isFinanceApFinanceReady(MRF $mrf): bool
    {
        if (! $this->usesFinanceAp($mrf) || ! $mrf->signed_po_url) {
            return false;
        }

        return in_array(
            $mrf->workflow_state ?? WorkflowStateService::STATE_MRF_CREATED,
            $this->financeApPipelineStates(),
            true
        );
    }

    /**
     * @return array{
     *     totalFinanceMRFs: int,
     *     legacy: array{count: int, pendingInternalPayment: int, awaitingChairmanApproval: int},
     *     financeAp: array{count: int, financeHandoffPending: int, inReviewOrMilestonePayment: int, packagePushedCount: int}
     * }
     */
    public function computeDashboardStats(): array
    {
        $legacyQuery = MRF::query();
        $this->scopeLegacyFinanceReady($legacyQuery);

        $financeApQuery = MRF::query();
        $this->scopeFinanceApFinanceReady($financeApQuery);

        $unifiedQuery = MRF::query();
        $this->scopeAnyFinanceReady($unifiedQuery);

        return [
            'totalFinanceMRFs' => (clone $unifiedQuery)->count(),
            'legacy' => [
                'count' => (clone $legacyQuery)->count(),
                'pendingInternalPayment' => (clone $legacyQuery)
                    ->where('status', 'finance')
                    ->whereNull('payment_processed_at')
                    ->count(),
                'awaitingChairmanApproval' => (clone $legacyQuery)
                    ->where('status', 'chairman_payment')
                    ->where('payment_status', 'processing')
                    ->count(),
            ],
            'financeAp' => [
                'count' => (clone $financeApQuery)->count(),
                'financeHandoffPending' => (clone $financeApQuery)
                    ->where('workflow_state', WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING)
                    ->count(),
                'inReviewOrMilestonePayment' => (clone $financeApQuery)
                    ->whereIn('workflow_state', [
                        WorkflowStateService::STATE_FINANCE_IN_REVIEW,
                        WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
                    ])
                    ->count(),
                'packagePushedCount' => (clone $financeApQuery)->whereNotNull('finance_ap_case_id')->count(),
            ],
        ];
    }

    /**
     * @return array{usesFinanceAp: bool, financeRoute: string, cutoverDate: ?string}
     */
    public function routingMeta(MRF $mrf): array
    {
        return [
            'usesFinanceAp' => $this->usesFinanceAp($mrf),
            'financeRoute' => $this->financeRoute($mrf),
            'cutoverDate' => $this->cutoverDate()?->toDateString(),
        ];
    }
}
