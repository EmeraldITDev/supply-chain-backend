<?php

namespace App\Support;

use App\Models\MRF;
use App\Models\Vendor;
use App\Support\TableColumnCache;
use Illuminate\Support\Facades\Schema;

/**
 * Field-coverage signals for dashboard gap-detection cards.
 * true = at least one live row has a non-null value (backend is capturing the field).
 */
class DashboardDataCapture
{
    /**
     * @return array<string, bool|int>
     */
    public static function snapshot(): array
    {
        $hasRfqCol = TableColumnCache::hasColumn('m_r_f_s', 'rfq_issued_at');
        $hasQuoteCol = TableColumnCache::hasColumn('m_r_f_s', 'quotation_received_at');
        $hasExpected = TableColumnCache::hasColumn('m_r_f_s', 'expected_delivery_date');
        $hasPoValue = TableColumnCache::hasColumn('m_r_f_s', 'po_value');
        $hasCompletedOrders = Schema::hasColumn('vendors', 'completed_orders');
        $hasOnTime = Schema::hasColumn('vendors', 'on_time_deliveries');

        $rfqCount = $hasRfqCol ? (int) MRF::whereNotNull('rfq_issued_at')->count() : 0;
        $quoteCount = $hasQuoteCol ? (int) MRF::whereNotNull('quotation_received_at')->count() : 0;
        $deliveredCount = (int) MRF::whereNotNull('grn_completed_at')->count();
        $expectedCount = $hasExpected ? (int) MRF::whereNotNull('expected_delivery_date')->count() : 0;
        $poCreatedCount = (int) MRF::whereNotNull('po_generated_at')->count();
        $poValueCount = $hasPoValue ? (int) MRF::whereNotNull('po_value')->count() : 0;
        $vendorHistoryCount = $hasCompletedOrders
            ? (int) Vendor::where('completed_orders', '>', 0)->count()
            : 0;
        $onTimeVendorCount = $hasOnTime
            ? (int) Vendor::where('on_time_deliveries', '>', 0)->count()
            : 0;

        return [
            // Capability / population flags used by gap cards
            'expected_delivery_date' => $expectedCount > 0,
            'po_created_at' => $poCreatedCount > 0,
            'po_generated_at' => $poCreatedCount > 0,
            'delivered_at' => $deliveredCount > 0,
            'goods_received_at' => $deliveredCount > 0,
            'actual_delivery_date' => $deliveredCount > 0,
            'grn_completed_at' => $deliveredCount > 0,
            'rfq_issued_at' => $rfqCount > 0,
            'quotation_received_at' => $quoteCount > 0,
            'quotes_received_at' => $quoteCount > 0,
            'po_value' => $poValueCount > 0,
            'vendor_fulfilment' => $vendorHistoryCount > 0 || $onTimeVendorCount > 0,
            'vendor_completed_orders' => $vendorHistoryCount > 0,
            'vendor_on_time_deliveries' => $onTimeVendorCount > 0,

            // Counts for debugging / richer UI
            'counts' => [
                'expected_delivery_date' => $expectedCount,
                'po_created_at' => $poCreatedCount,
                'delivered_at' => $deliveredCount,
                'rfq_issued_at' => $rfqCount,
                'quotation_received_at' => $quoteCount,
                'po_value' => $poValueCount,
                'vendor_completed_orders' => $vendorHistoryCount,
                'vendor_on_time_deliveries' => $onTimeVendorCount,
            ],
        ];
    }
}
