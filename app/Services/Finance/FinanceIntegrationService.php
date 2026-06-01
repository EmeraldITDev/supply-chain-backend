<?php

namespace App\Services\Finance;

use App\Models\MRF;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Finance AP outbound integration. Full push/webhook handling lands in Phase 6.
 */
class FinanceIntegrationService
{
    public function hasPackageBeenPushed(MRF $mrf): bool
    {
        if (! Schema::hasColumn($mrf->getTable(), 'finance_ap_case_id')) {
            return false;
        }

        return filled($mrf->finance_ap_case_id);
    }

    public function pushDelta(MRF $mrf, string $reason): bool
    {
        if (! $this->hasPackageBeenPushed($mrf)) {
            Log::info('Finance AP delta push skipped; package not yet pushed', [
                'mrf_id' => $mrf->mrf_id,
                'reason' => $reason,
            ]);

            return false;
        }

        Log::info('Finance AP delta push queued (stub until Phase 6 REST client)', [
            'mrf_id' => $mrf->mrf_id,
            'scm_transaction_id' => $mrf->scm_transaction_id,
            'reason' => $reason,
        ]);

        return true;
    }
}
