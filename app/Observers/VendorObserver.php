<?php

namespace App\Observers;

use App\Models\Vendor;
use App\Services\Finance\FinanceApVendorSyncService;

class VendorObserver
{
    /**
     * Relevant vendor columns; avoid syncing on unrelated touches (e.g. rating only).
     *
     * @var list<string>
     */
    private const SYNC_FIELDS = [
        'vendor_id', 'name', 'status', 'category', 'category_other',
        'email', 'phone', 'alternate_phone', 'address', 'city', 'state',
        'postal_code', 'country_code', 'tax_id', 'website',
        'contact_person', 'contact_person_title', 'contact_person_email', 'contact_person_phone',
        'profile_completed', 'onboarding_source',
    ];

    public function created(Vendor $vendor): void
    {
        $this->schedulePush($vendor);
    }

    public function updated(Vendor $vendor): void
    {
        if (! $vendor->wasChanged(self::SYNC_FIELDS)) {
            return;
        }

        $this->schedulePush($vendor);
    }

    private function schedulePush(Vendor $vendor): void
    {
        $vendorId = $vendor->id;

        dispatch(function () use ($vendorId) {
            $fresh = Vendor::query()->find($vendorId);
            if ($fresh) {
                app(FinanceApVendorSyncService::class)->pushVendor($fresh);
            }
        })->afterCommit();
    }
}
