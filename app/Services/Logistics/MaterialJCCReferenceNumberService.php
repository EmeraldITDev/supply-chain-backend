<?php

namespace App\Services\Logistics;

use App\Models\Logistics\MaterialJCC;

class MaterialJCCReferenceNumberService
{
    /**
     * Generate reference number for Material JCC.
     * Format: JCC/MAT/[YYYYMM]-[paddedSequence]
     * Example: JCC/MAT/202509-01
     * Sequence scoped per month.
     */
    public function generateReferenceNumber(): string
    {
        $prefix = 'JCC/MAT';
        $ym = now()->format('Ym');

        // Get the count of JCCs created this month
        $count = MaterialJCC::query()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $seq = str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT);

        return "{$prefix}/{$ym}-{$seq}";
    }

    /**
     * Validate reference number format.
     */
    public function isValidFormat(string $referenceNumber): bool
    {
        return preg_match('/^JCC\/MAT\/\d{6}-\d{2}$/', $referenceNumber) === 1;
    }
}
