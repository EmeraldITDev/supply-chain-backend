<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DashboardStatsCache
{
    private const TTL_SECONDS = 300;

    private const KEYS = [
        'dashboard.procurement_manager.stats',
        'dashboard.supply_chain_director.stats',
        'dashboard.supply_chain_director.metrics',
        'dashboard.supply_chain_director.queues.counts',
        'dashboard.supply_chain_director.recent_registrations',
        'dashboard.executive.queues.counts',
        'dashboard.kpis',
        'dashboard.finance.stats',
        'dashboard.po.summary_counts',
    ];

    /**
     * Cached PO lifecycle summary counts for dashboards (TTL 5 minutes).
     *
     * @return array{
     *   total: int,
     *   draft: int,
     *   pending: int,
     *   signed: int,
     *   rejected: int,
     *   completed: int
     * }
     */
    public static function poSummaryCounts(): array
    {
        return self::remember('dashboard.po.summary_counts', function (): array {
            $base = \App\Models\MRF::query()->forPoList();

            return [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->withPoLifecycleStatus('draft')->count(),
                'pending' => (clone $base)->withPoLifecycleStatus('pending')->count(),
                'signed' => (clone $base)->withPoLifecycleStatus('signed')->count(),
                'rejected' => (clone $base)->withPoLifecycleStatus('rejected')->count(),
                'completed' => (clone $base)->withPoLifecycleStatus('completed')->count(),
            ];
        });
    }

    public static function remember(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        return Cache::remember($key, now()->addSeconds($ttlSeconds ?? self::TTL_SECONDS), $callback);
    }

    public static function forgetAll(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget($key);
        }
    }
}
