<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\Vendor;
use App\Models\VendorRating;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorFulfilmentService
{
    /**
     * Increment total_orders when a PO is first generated for a vendor.
     */
    public function recordPoGenerated(MRF $mrf): void
    {
        $vendor = $this->resolveVendor($mrf);
        if (! $vendor) {
            return;
        }

        try {
            $vendor->increment('total_orders');
            $this->forgetPerformanceCache((int) $vendor->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to increment vendor total_orders', [
                'vendor_id' => $vendor->id,
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * On GRN completion / PO close: update completed_orders, on_time_deliveries, rating.
     */
    public function recordCycleCompleted(MRF $mrf, bool $fromGrn = true): void
    {
        unset($fromGrn); // Reserved for callers distinguishing GRN vs close paths.
        $vendor = $this->resolveVendor($mrf);
        if (! $vendor) {
            return;
        }

        try {
            DB::transaction(function () use ($vendor, $mrf, $fromGrn) {
                $locked = Vendor::query()->whereKey($vendor->id)->lockForUpdate()->first();
                if (! $locked) {
                    return;
                }

                $completed = (int) ($locked->completed_orders ?? 0) + 1;
                $onTime = (int) ($locked->on_time_deliveries ?? 0);

                if ($mrf->grn_completed_at && $mrf->expected_delivery_date) {
                    $actualDate = $mrf->grn_completed_at->toDateString();
                    $expectedDate = $mrf->expected_delivery_date->toDateString();
                    if ($actualDate <= $expectedDate) {
                        $onTime++;
                    }
                }

                $update = [
                    'completed_orders' => $completed,
                    'on_time_deliveries' => $onTime,
                ];

                // Refresh running average rating from fulfilment when no manual ratings exist.
                $manualCount = VendorRating::where('vendor_id', $locked->id)->count();
                if ($manualCount === 0 && $completed > 0) {
                    $update['rating'] = round(($onTime / $completed) * 5, 2);
                } elseif ($manualCount > 0) {
                    $avg = VendorRating::where('vendor_id', $locked->id)->avg('rating');
                    $update['rating'] = $avg ? round((float) $avg, 2) : (float) ($locked->rating ?? 0);
                }

                $locked->update($update);
            });

            $this->forgetPerformanceCache((int) $vendor->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update vendor fulfilment after cycle', [
                'vendor_id' => $vendor->id,
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveVendor(MRF $mrf): ?Vendor
    {
        if (! $mrf->selected_vendor_id) {
            return null;
        }

        return Vendor::query()->find($mrf->selected_vendor_id);
    }

    private function forgetPerformanceCache(int $vendorId): void
    {
        foreach ([
            "vendor_po_count_{$vendorId}",
            "vendor_po_completed_{$vendorId}",
            "vendor_po_on_time_{$vendorId}",
            "vendor_po_late_{$vendorId}",
            "vendor_po_avg_days_{$vendorId}",
        ] as $key) {
            Cache::forget($key);
        }
    }
}
