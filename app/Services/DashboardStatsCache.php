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
    ];

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
