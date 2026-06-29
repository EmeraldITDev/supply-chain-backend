<?php

namespace App\Services\Finance;

use App\Models\MRF;
use App\Models\Vendor;
use App\Services\PriceComparisonPoLineService;
use App\Support\VendorCategoryDisplay;

/**
 * Resolves the supplier for an MRF finance package and builds the vendor
 * snapshot Finance AP ingests (Pattern A — SCM vendor master, read-only in FA).
 */
class FinanceApVendorSnapshotBuilder
{
    public function __construct(
        private PriceComparisonPoLineService $priceComparisonPoLineService,
    ) {
    }

    public function resolveForMrf(MRF $mrf): ?Vendor
    {
        $mrf->loadMissing(['selectedVendor', 'priceComparisons.vendor']);

        $rows = $this->priceComparisonPoLineService->selectedSupplierRows($mrf);
        $vendor = $this->priceComparisonPoLineService->resolveVendorFromRows($rows);

        if ($vendor) {
            return $vendor;
        }

        return $mrf->selectedVendor;
    }

    /**
     * Canonical vendor payload for Finance AP package ingest.
     * Includes camelCase keys (package convention) and snake_case aliases.
     *
     * @return array<string, mixed>
     */
    public function toArray(Vendor $vendor): array
    {
        $categoryOther = $vendor->category_other;
        $addressLine = trim(implode(', ', array_filter([
            $vendor->address,
            $vendor->city,
            $vendor->state,
        ])));

        $snapshot = [
            'source' => 'scm',
            'scmVendorId' => $vendor->id,
            'vendorCode' => $vendor->vendor_id,
            'name' => $vendor->name,
            'status' => $vendor->status,
            'category' => $vendor->category,
            'categoryDisplay' => VendorCategoryDisplay::format($vendor->category, $categoryOther),
            'categoryOther' => $categoryOther,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'alternatePhone' => $vendor->alternate_phone,
            'taxId' => $vendor->tax_id,
            'website' => $vendor->website,
            'address' => $addressLine !== '' ? $addressLine : $vendor->address,
            'addressLine1' => $vendor->address,
            'city' => $vendor->city,
            'state' => $vendor->state,
            'postalCode' => $vendor->postal_code,
            'countryCode' => $vendor->country_code,
            'contactPerson' => $vendor->contact_person,
            'contactPersonTitle' => $vendor->contact_person_title,
            'contactPersonEmail' => $vendor->contact_person_email,
            'contactPersonPhone' => $vendor->contact_person_phone,
            'bankName' => $vendor->bank_name,
            'bank_name' => $vendor->bank_name,
            'accountName' => $vendor->account_name,
            'account_name' => $vendor->account_name,
            'accountNumber' => $vendor->account_number,
            'account_number' => $vendor->account_number,
            'profileCompleted' => (bool) ($vendor->profile_completed ?? true),
            'onboardingSource' => $vendor->onboarding_source,
            'snapshotAt' => now()->toIso8601String(),
        ];

        return $this->withSnakeCaseAliases($snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function withSnakeCaseAliases(array $snapshot): array
    {
        $aliases = [
            'scm_vendor_id' => 'scmVendorId',
            'vendor_code' => 'vendorCode',
            'category_display' => 'categoryDisplay',
            'category_other' => 'categoryOther',
            'alternate_phone' => 'alternatePhone',
            'tax_id' => 'taxId',
            'address_line1' => 'addressLine1',
            'postal_code' => 'postalCode',
            'country_code' => 'countryCode',
            'contact_person' => 'contactPerson',
            'contact_person_title' => 'contactPersonTitle',
            'contact_person_email' => 'contactPersonEmail',
            'contact_person_phone' => 'contactPersonPhone',
            'bank_name' => 'bankName',
            'account_name' => 'accountName',
            'account_number' => 'accountNumber',
            'profile_completed' => 'profileCompleted',
            'onboarding_source' => 'onboardingSource',
            'snapshot_at' => 'snapshotAt',
        ];

        foreach ($aliases as $snake => $camel) {
            if (array_key_exists($camel, $snapshot)) {
                $snapshot[$snake] = $snapshot[$camel];
            }
        }

        return $snapshot;
    }
}
