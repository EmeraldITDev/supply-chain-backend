<?php

namespace App\Support;

use App\Models\MRF;
use Carbon\CarbonInterface;

class ProcurementDeliveryStatus
{
    /**
     * Derive delivery status for dashboard / list / detail payloads.
     */
    public static function calculate(MRF $mrf): string
    {
        $actual = $mrf->grn_completed_at;
        $expected = $mrf->expected_delivery_date;

        if ($actual) {
            if ($expected) {
                $actualDate = $actual instanceof CarbonInterface
                    ? $actual->toDateString()
                    : (string) $actual;
                $expectedDate = $expected instanceof CarbonInterface
                    ? $expected->toDateString()
                    : (string) $expected;

                return $actualDate <= $expectedDate ? 'on_time' : 'late';
            }

            return 'delivered';
        }

        if ($expected) {
            $expectedDate = $expected instanceof CarbonInterface
                ? $expected->toDateString()
                : (string) $expected;
            if (now()->toDateString() > $expectedDate) {
                return 'overdue';
            }
        }

        if ($mrf->po_generated_at) {
            return 'in_transit';
        }

        return 'pending';
    }

    /**
     * Dual-case delivery intelligence fields for API responses.
     *
     * @return array<string, mixed>
     */
    public static function apiFields(MRF $mrf): array
    {
        $expected = $mrf->expected_delivery_date
            ? ($mrf->expected_delivery_date instanceof CarbonInterface
                ? $mrf->expected_delivery_date->format('Y-m-d')
                : (string) $mrf->expected_delivery_date)
            : null;

        $actual = $mrf->grn_completed_at?->toIso8601String();
        $status = self::calculate($mrf);

        return [
            'expected_delivery_date' => $expected,
            'expectedDeliveryDate' => $expected,
            'actual_delivery_date' => $actual,
            'actualDeliveryDate' => $actual,
            'delivery_status' => $status,
            'deliveryStatus' => $status,
            'rfq_issued_at' => $mrf->rfq_issued_at?->toIso8601String(),
            'rfqIssuedAt' => $mrf->rfq_issued_at?->toIso8601String(),
            'quotation_received_at' => $mrf->quotation_received_at?->toIso8601String(),
            'quotationReceivedAt' => $mrf->quotation_received_at?->toIso8601String(),
        ];
    }
}
