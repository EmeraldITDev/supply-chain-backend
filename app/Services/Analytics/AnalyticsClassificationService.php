<?php

namespace App\Services\Analytics;

use App\Models\MRF;
use App\Support\AnalyticsBaseline;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies analytics classification rules to MRF queries.
 *
 * Classification is used ONLY for performance KPI populations.
 * Operational queues (pending_for_role, SCD/exec/finance dashboards)
 * MUST NOT use these scopes — every outstanding item stays visible regardless
 * of analytics_class.
 *
 * Classification logic (applied when analytics_class column IS NULL):
 *   - created_at >= baseline → new_period
 *   - created_at < baseline  → unreconciled_legacy (until reconciled)
 *   - analytics_class = 'verified_historical' → explicitly reconciled historical
 */
class AnalyticsClassificationService
{
    /** States considered terminal (excluded from 'outstanding work' counts). */
    private const TERMINAL_STATES = [
        'closed',
        'financially_complete',
        'operationally_complete',
        'rejected',
        'cancelled',
    ];

    /**
     * Scope: MRFs that should contribute to new-period performance metrics.
     * Includes: explicitly 'new_period' OR (no class AND created_at >= baseline).
     */
    public function scopeNewPeriod(Builder $query, ?Carbon $baseline = null): Builder
    {
        $baseline ??= AnalyticsBaseline::effectiveAt();

        if ($baseline === null) {
            // Baseline not established — treat all records as new period
            // (no baseline to exclude anything against).
            return $query;
        }

        return $query->where(function (Builder $q) use ($baseline): void {
            $q->where('analytics_class', 'new_period')
                ->orWhere(function (Builder $inner) use ($baseline): void {
                    $inner->whereNull('analytics_class')
                        ->where('created_at', '>=', $baseline);
                });
        });
    }

    /**
     * Scope: MRFs classified as unreconciled legacy (excluded from new-period metrics).
     * Includes: explicitly 'unreconciled_legacy' OR (no class AND created_at < baseline).
     */
    public function scopeUnreconciledLegacy(Builder $query, ?Carbon $baseline = null): Builder
    {
        $baseline ??= AnalyticsBaseline::effectiveAt();

        if ($baseline === null) {
            // No baseline — nothing is legacy.
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $q) use ($baseline): void {
            $q->where('analytics_class', 'unreconciled_legacy')
                ->orWhere(function (Builder $inner) use ($baseline): void {
                    $inner->whereNull('analytics_class')
                        ->where('created_at', '<', $baseline);
                });
        });
    }

    /**
     * Scope: MRFs with verified historical classification.
     */
    public function scopeVerifiedHistorical(Builder $query): Builder
    {
        return $query->where('analytics_class', 'verified_historical');
    }

    /**
     * Count unreconciled legacy MRFs that are still operationally outstanding.
     * These must remain visible in queues with a "carry-over" badge.
     */
    public function countOutstandingLegacy(?Carbon $baseline = null): int
    {
        $baseline ??= AnalyticsBaseline::effectiveAt();

        if ($baseline === null) {
            return 0;
        }

        return MRF::query()
            ->where(function (Builder $q) use ($baseline): void {
                $q->where('analytics_class', 'unreconciled_legacy')
                    ->orWhere(function (Builder $inner) use ($baseline): void {
                        $inner->whereNull('analytics_class')
                            ->where('created_at', '<', $baseline);
                    });
            })
            ->whereNotIn('workflow_state', self::TERMINAL_STATES)
            ->where(function (Builder $q): void {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(status) NOT IN ('completed', 'rejected', 'cancelled')");
            })
            ->count();
    }

    /**
     * Classify a single MRF in-place.
     * Called during reconciliation workflow — originals are never overwritten;
     * the previous class is preserved in audit_logs by the controller.
     */
    public function classify(
        MRF $mrf,
        string $class,
        ?string $originalBusinessDate = null,
        ?bool $handledOffline = null,
        ?string $notes = null,
        ?int $reviewedBy = null,
    ): MRF {
        $allowedClasses = ['new_period', 'verified_historical', 'unreconciled_legacy'];
        if (! in_array($class, $allowedClasses, true)) {
            throw new \InvalidArgumentException("Invalid analytics_class: {$class}");
        }

        $attributes = [
            'analytics_class'       => $class,
            'reconciliation_status' => 'completed',
            'reconciled_at'         => now(),
            'reconciled_by'         => $reviewedBy,
        ];

        if ($originalBusinessDate !== null) {
            $attributes['original_business_date'] = $originalBusinessDate;
        }
        if ($handledOffline !== null) {
            $attributes['handled_offline'] = $handledOffline;
        }
        if ($notes !== null) {
            $attributes['reconciliation_notes'] = $notes;
        }

        $mrf->fill($attributes)->save();

        return $mrf;
    }
}
