<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Vendor;
use Illuminate\Support\Str;

class JCCReferenceNumberService
{
    /**
     * @deprecated Use generateReferenceNumberForVendor()
     */
    public function generateReferenceNumber(): string
    {
        return $this->generateReferenceNumberForVendor(null);
    }

    /**
     * Format: JCC/{VendorShortCode}/{YYYYMM}-{seq} — sequence scoped per vendor per calendar month.
     */
    public function generateReferenceNumberForVendor(?Vendor $vendor): string
    {
        $code = $this->vendorShortCode($vendor);
        $ym = now()->format('Ym');

        $query = JobCompletionCertificate::query()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);

        if ($vendor) {
            $query->where('vendor_id', $vendor->id);
        } else {
            $query->whereNull('vendor_id');
        }

        $next = $query->count() + 1;
        $seq = str_pad((string) min($next, 99), 2, '0', STR_PAD_LEFT);

        return "JCC/{$code}/{$ym}-{$seq}";
    }

    private function vendorShortCode(?Vendor $vendor): string
    {
        if (!$vendor) {
            return 'GEN';
        }

        if (!empty($vendor->vendor_id)) {
            $slug = preg_replace('/[^A-Za-z0-9]/', '', $vendor->vendor_id);

            return Str::upper(Str::limit($slug ?: 'VEND', 12, ''));
        }

        $fromName = Str::upper(Str::slug(Str::limit($vendor->name, 12, ''), ''));

        return $fromName !== '' ? $fromName : 'VEND';
    }

    /**
     * Validate reference number format (flexible vendor code segment).
     */
    public function isValidFormat(string $referenceNumber): bool
    {
        return preg_match('/^JCC\/[A-Z0-9]+\/\d{6}-\d{2}$/', $referenceNumber) === 1;
    }
}
