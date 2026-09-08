<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VendorPerformanceMetrics
{
    private const TTL = 300;

    /**
     * @return array{
     *   total_pos: int,
     *   completed_pos: int,
     *   on_time_deliveries: int,
     *   late_deliveries: int,
     *   average_delivery_days: float|null,
     *   fulfilment_rate: float
     * }
     */
    public static function forVendor(int $vendorId): array
    {
        return [
            'total_pos' => self::countVendorPOs($vendorId),
            'completed_pos' => self::countVendorCompletedPOs($vendorId),
            'on_time_deliveries' => self::countVendorOnTimeDeliveries($vendorId),
            'late_deliveries' => self::countVendorLateDeliveries($vendorId),
            'average_delivery_days' => self::averageVendorDeliveryDays($vendorId),
            'fulfilment_rate' => self::calculateVendorFulfilmentRate($vendorId),
        ];
    }

    public static function countVendorPOs(int $vendorId): int
    {
        return (int) Cache::remember("vendor_po_count_{$vendorId}", self::TTL, function () use ($vendorId) {
            return DB::table('m_r_f_s')
                ->where('selected_vendor_id', $vendorId)
                ->whereNotNull('po_generated_at')
                ->count();
        });
    }

    public static function countVendorCompletedPOs(int $vendorId): int
    {
        return (int) Cache::remember("vendor_po_completed_{$vendorId}", self::TTL, function () use ($vendorId) {
            return DB::table('m_r_f_s')
                ->where('selected_vendor_id', $vendorId)
                ->whereNotNull('po_generated_at')
                ->where(function ($q) {
                    $q->where('grn_completed', true)
                        ->orWhereNotNull('grn_completed_at');
                })
                ->count();
        });
    }

    public static function countVendorOnTimeDeliveries(int $vendorId): int
    {
        return (int) Cache::remember("vendor_po_on_time_{$vendorId}", self::TTL, function () use ($vendorId) {
            return DB::table('m_r_f_s')
                ->where('selected_vendor_id', $vendorId)
                ->whereNotNull('grn_completed_at')
                ->whereNotNull('expected_delivery_date')
                ->whereRaw('DATE(grn_completed_at) <= expected_delivery_date')
                ->count();
        });
    }

    public static function countVendorLateDeliveries(int $vendorId): int
    {
        return (int) Cache::remember("vendor_po_late_{$vendorId}", self::TTL, function () use ($vendorId) {
            return DB::table('m_r_f_s')
                ->where('selected_vendor_id', $vendorId)
                ->whereNotNull('grn_completed_at')
                ->whereNotNull('expected_delivery_date')
                ->whereRaw('DATE(grn_completed_at) > expected_delivery_date')
                ->count();
        });
    }

    public static function averageVendorDeliveryDays(int $vendorId): ?float
    {
        $avg = Cache::remember("vendor_po_avg_days_{$vendorId}", self::TTL, function () use ($vendorId) {
            $row = DB::table('m_r_f_s')
                ->where('selected_vendor_id', $vendorId)
                ->whereNotNull('po_generated_at')
                ->whereNotNull('grn_completed_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (grn_completed_at - po_generated_at)) / 86400.0) as avg_days')
                ->first();

            return $row?->avg_days;
        });

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    public static function calculateVendorFulfilmentRate(int $vendorId): float
    {
        $total = self::countVendorPOs($vendorId);
        if ($total === 0) {
            return 0.0;
        }

        $completed = self::countVendorCompletedPOs($vendorId);

        return round(($completed / $total) * 100, 1);
    }
}
