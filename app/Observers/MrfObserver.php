<?php

namespace App\Observers;

use App\Models\MRF;
use App\Models\Vendor;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MrfObserver
{
    /**
     * Handle the MRF "saving" event.
     * Validate that when a po_number is being set, a selected_vendor_id
     * is present and the vendor slug matches the token embedded in po_number.
     * Throwing here will prevent the save and (if within a DB transaction)
     * will cause a rollback.
     */
    public function saving(MRF $mrf): void
    {
        // Only care when po_number is being set/changed
        if (! $mrf->isDirty('po_number')) {
            return;
        }

        $po = (string) $mrf->po_number;
        if ($po === '') {
            return;
        }

        $vendorId = $mrf->selected_vendor_id ?? null;
        if (! $vendorId) {
            Log::error('MRF saving: PO number being set without selected_vendor_id', [
                'mrf_id' => $mrf->mrf_id ?? $mrf->id ?? null,
                'po_number' => $po,
            ]);

            throw new \RuntimeException('Cannot save PO number: selected_vendor_id is missing');
        }

        $vendor = Vendor::query()->find($vendorId);
        if (! $vendor) {
            Log::error('MRF saving: selected_vendor_id refers to missing vendor', [
                'mrf_id' => $mrf->mrf_id ?? $mrf->id ?? null,
                'vendor_id' => $vendorId,
                'po_number' => $po,
            ]);

            throw new \RuntimeException('Cannot save PO number: selected vendor not found');
        }

        $slug = Str::slug($vendor->name ?? '', '');
        if ($slug !== '' && ! Str::contains(strtoupper($po), strtoupper($slug))) {
            Log::info('MRF saving: PO number vendor slug mismatch. Wiping old PO number to allow auto-regeneration.', [
                'mrf_id' => $mrf->mrf_id ?? $mrf->id ?? null,
                'po_number' => $po,
                'vendor_name' => $vendor->name ?? null,
                'vendor_id' => $vendorId,
            ]);

            // SELF-HEALING: Instead of crashing, wipe the invalid PO number
            $mrf->po_number = null;
            return;
        }
    }
}
