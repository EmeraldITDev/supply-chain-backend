<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\JCCLineItem;
use Illuminate\Support\Str;

class JCCReferenceNumberService
{
    /**
     * Generate JCC reference number in format: JCC/SERVIZO/YYYYMMDD-XX
     *
     * Example: JCC/SERVIZO/202509-05
     */
    public function generateReferenceNumber(): string
    {
        $datePrefix = now()->format('Ym'); // YYYYMM

        // Find the next sequence number for today
        $sequenceNumber = JobCompletionCertificate::whereDate('created_at', today())
            ->count() + 1;

        // Format as two digits with leading zero
        $sequence = str_pad($sequenceNumber, 2, '0', STR_PAD_LEFT);

        return "JCC/SERVIZO/{$datePrefix}-{$sequence}";
    }

    /**
     * Validate reference number format
     */
    public function isValidFormat(string $referenceNumber): bool
    {
        return preg_match('/^JCC\/SERVIZO\/\d{6}-\d{2}$/', $referenceNumber) === 1;
    }
}
