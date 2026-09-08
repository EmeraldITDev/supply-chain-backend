<?php

namespace App\Support;

use App\Models\MRF;

/**
 * Dual-case API fields for the Executive Command Centre dashboard.
 * Additive only — does not remove or rename existing keys.
 */
class ExecutiveCommandCentreFields
{
    /**
     * @return array<string, mixed>
     */
    public static function apiFields(MRF $mrf): array
    {
        $poCreated = $mrf->po_generated_at?->toIso8601String();
        $delivered = $mrf->grn_completed_at?->toIso8601String();
        $scdAt = ($mrf->scd_approved_at ?? $mrf->director_approved_at ?? $mrf->supply_chain_approved_at ?? null);
        $scdIso = $scdAt?->toIso8601String();
        $poValue = $mrf->po_value !== null ? (float) $mrf->po_value : null;

        return array_merge(ProcurementDeliveryStatus::apiFields($mrf), [
            // PO creation timestamp (alias of po_generated_at)
            'po_created_at' => $poCreated,
            'poCreatedAt' => $poCreated,

            // Actual delivery / goods received (alias of grn_completed_at)
            'delivered_at' => $delivered,
            'deliveredAt' => $delivered,
            'goods_received_at' => $delivered,
            'goodsReceivedAt' => $delivered,

            // PO value (actual spend)
            'po_value' => $poValue,
            'poValue' => $poValue,
            'final_amount' => $poValue,
            'finalAmount' => $poValue,

            // Approval stage timestamps
            'executive_approved_at' => $mrf->executive_approved_at?->toIso8601String(),
            'executiveApprovedAt' => $mrf->executive_approved_at?->toIso8601String(),
            'scd_approved_at' => $scdIso,
            'scdApprovedAt' => $scdIso,
            'director_approved_at' => $mrf->director_approved_at?->toIso8601String(),
            'directorApprovedAt' => $mrf->director_approved_at?->toIso8601String(),
            'procurement_approved_at' => $mrf->procurement_approved_at?->toIso8601String(),
            'procurementApprovedAt' => $mrf->procurement_approved_at?->toIso8601String(),
            'procurement_review_started_at' => $mrf->procurement_review_started_at?->toIso8601String(),
            'procurementReviewStartedAt' => $mrf->procurement_review_started_at?->toIso8601String(),
            'finance_approved_at' => $mrf->finance_approved_at?->toIso8601String(),
            'financeApprovedAt' => $mrf->finance_approved_at?->toIso8601String(),
            'payment_approved_at' => $mrf->payment_approved_at?->toIso8601String(),
            'paymentApprovedAt' => $mrf->payment_approved_at?->toIso8601String(),
        ]);
    }
}
