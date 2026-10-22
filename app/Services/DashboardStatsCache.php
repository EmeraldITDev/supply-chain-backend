<?php

namespace App\Services;

use App\Models\MRF;
use App\Support\FastCache;
use Illuminate\Support\Facades\DB;

class DashboardStatsCache
{
    private const TTL_SECONDS = 300;

    private const KEYS = [
        'dashboard.procurement_manager.stats',
        'dashboard.queue.pending_vendor_registrations',
        'dashboard.queue.pending_mrf_first_approval',
        'dashboard.queue.pending_srf_scd_approval',
        'dashboard.queue.pending_trip_scd_approval',
        'dashboard.queue.pending_trip_po_signature',
        'dashboard.procurement_manager.lists',
        'dashboard.supply_chain_director.stats',
        'dashboard.supply_chain_director.metrics',
        'dashboard.supply_chain_director.queues.counts',
        'dashboard.supply_chain_director.recent_registrations',
        'dashboard.executive.queues.counts',
        'dashboard.kpis',
        'dashboard.finance.stats',
        'dashboard.po.summary_counts',
    ];

    /** @var array<string, mixed> */
    private static array $requestMemo = [];

    /**
     * Cached PO lifecycle summary counts for dashboards (TTL 5 minutes).
     *
     * Uses a single aggregate query (PostgreSQL FILTER) instead of 6 COUNT clones.
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
        $memoKey = 'po_summary_counts';
        if (isset(self::$requestMemo[$memoKey]) && is_array(self::$requestMemo[$memoKey])) {
            return self::$requestMemo[$memoKey];
        }

        $counts = self::remember('dashboard.po.summary_counts', function (): array {
            $rejectedStates = MRF::poRejectedWorkflowStates();
            $completedStates = MRF::poCompletedWorkflowStates();

            $rejectedPlaceholders = implode(',', array_fill(0, count($rejectedStates), '?'));
            $completedPlaceholders = implode(',', array_fill(0, count($completedStates), '?'));

            $sql = "
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE
                        WHEN po_draft_saved_at IS NOT NULL
                          AND (unsigned_po_url IS NULL OR unsigned_po_url = '')
                        THEN 1 ELSE 0 END) AS draft,
                    SUM(CASE
                        WHEN unsigned_po_url IS NOT NULL
                          AND unsigned_po_url != ''
                          AND (signed_po_url IS NULL OR signed_po_url = '')
                        THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE
                        WHEN signed_po_url IS NOT NULL AND signed_po_url != ''
                        THEN 1 ELSE 0 END) AS signed,
                    SUM(CASE
                        WHEN status = 'rejected'
                           OR rejected_at IS NOT NULL
                           OR workflow_state IN ({$rejectedPlaceholders})
                        THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE
                        WHEN grn_completed = true
                           OR status = 'completed'
                           OR workflow_state IN ({$completedPlaceholders})
                        THEN 1 ELSE 0 END) AS completed
                FROM m_r_f_s
                WHERE (
                    (po_number IS NOT NULL AND po_number != '')
                    OR (
                        po_draft_saved_at IS NOT NULL
                        AND (unsigned_po_url IS NULL OR unsigned_po_url = '')
                    )
                )
            ";

            $bindings = array_merge($rejectedStates, $completedStates);
            $row = DB::selectOne($sql, $bindings);

            return [
                'total' => (int) ($row->total ?? 0),
                'draft' => (int) ($row->draft ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'signed' => (int) ($row->signed ?? 0),
                'rejected' => (int) ($row->rejected ?? 0),
                'completed' => (int) ($row->completed ?? 0),
            ];
        });

        self::$requestMemo[$memoKey] = $counts;

        return $counts;
    }

    public static function remember(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        if (array_key_exists($key, self::$requestMemo)) {
            return self::$requestMemo[$key];
        }

        $value = FastCache::store()->remember($key, now()->addSeconds($ttlSeconds ?? self::TTL_SECONDS), $callback);
        self::$requestMemo[$key] = $value;

        return $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$requestMemo[$key]);
        FastCache::store()->forget($key);
    }

    public static function forgetAll(): void
    {
        self::$requestMemo = [];
        foreach (self::KEYS as $key) {
            FastCache::store()->forget($key);
        }
    }
}
