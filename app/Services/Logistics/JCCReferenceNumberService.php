<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\Trip;
use App\Models\Vendor;
use Illuminate\Support\Str;

class JCCReferenceNumberService
{
    /**
     * @deprecated Use generateReferenceNumberForTrip()
     */
    public function generateReferenceNumber(): string
    {
        return $this->generateReferenceNumberForTrip(null);
    }

    /**
     * Format: JCC-{TRIP}-{YYYY}-{seq} — sequence scoped per trip per calendar year.
     */
    public function generateReferenceNumberForTrip(?Trip $trip): string
    {
        $tripRef = $this->tripReference($trip);
        $year = now()->format('Y');

        $query = JobCompletionCertificate::query()
            ->whereYear('created_at', $year);

        if ($trip) {
            $query->where('trip_id', $trip->id);
        }

        $next = $query->count() + 1;

        return sprintf('JCC-%s-%s-%03d', $tripRef, $year, $next);
    }

    /**
     * @deprecated Use generateReferenceNumberForTrip()
     *
     * Format: JCC/{VendorShortCode}/{YYYYMM}-{seq} — sequence scoped per vendor per calendar month.
     */
    public function generateReferenceNumberForVendor(?Vendor $vendor): string
    {
        return $this->generateReferenceNumberForTrip(null);
    }

    private function tripReference(?Trip $trip): string
    {
        if (! $trip) {
            return 'TRIP';
        }

        $code = preg_replace('/[^A-Za-z0-9-]/', '-', (string) ($trip->trip_code ?: 'TRIP-'.$trip->id));
        $code = trim((string) preg_replace('/-+/', '-', $code), '-');

        return Str::upper($code !== '' ? $code : 'TRIP-'.$trip->id);
    }

    /**
     * Validate reference number format.
     */
    public function isValidFormat(string $referenceNumber): bool
    {
        return preg_match('/^JCC-[A-Z0-9-]+-\d{4}-\d{3}$/', $referenceNumber) === 1
            || preg_match('/^JCC\/[A-Z0-9]+\/\d{6}-\d{2}$/', $referenceNumber) === 1;
    }
}
