<?php

namespace App\Services\Finance;

use App\Models\PaymentSchedule;
use App\Models\ProcurementDocument;

/**
 * Maps SCM payment terms / documents to Finance AP payment_type values.
 */
class FinanceApPaymentTypeMapper
{
    /**
     * @param  list<array<string, mixed>>  $documentManifest
     */
    public function map(?string $paymentTerms, ?PaymentSchedule $schedule, array $documentManifest): string
    {
        $terms = strtolower(trim((string) $paymentTerms));

        if (str_contains($terms, 'statutory') || str_contains($terms, 'regulatory')) {
            return 'statutory_payment';
        }

        if (str_contains($terms, 'exceptional') || str_contains($terms, 'higher auth')) {
            return 'exceptional_approval';
        }

        $types = collect($documentManifest)->pluck('type')->filter()->all();
        $hasInvoice = $this->hasAnyType($types, ['invoice', 'proforma', 'pfi', 'vendor_invoice']);
        $hasGrn = $this->hasAnyType($types, ['grn', 'waybill', 'delivery_note', 'delivery_confirmation']);
        $hasPo = $this->hasAnyType($types, ['purchase_order', 'signed_po', 'po_pdf']);

        if ((str_contains($terms, 'advance') || $this->isAdvanceSchedule($schedule)) && $hasPo) {
            if ($hasInvoice && $hasGrn) {
                return 'three_way_match_exception';
            }

            return 'advance_payment';
        }

        if ($hasPo && $hasInvoice && $hasGrn) {
            return 'three_way_match';
        }

        if ($hasPo && $hasInvoice) {
            return 'two_way_match';
        }

        if (str_contains($terms, 'advance') || str_contains($terms, 'prepay') || $this->isAdvanceSchedule($schedule)) {
            return 'advance_payment';
        }

        return 'two_way_match';
    }

    private function isAdvanceSchedule(?PaymentSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $key = strtolower((string) ($schedule->template_name ?? ''));

        return str_contains($key, 'advance');
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $needles
     */
    private function hasAnyType(array $types, array $needles): bool
    {
        foreach ($types as $type) {
            foreach ($needles as $needle) {
                if ($type === $needle) {
                    return true;
                }
            }
        }

        return false;
    }
}
