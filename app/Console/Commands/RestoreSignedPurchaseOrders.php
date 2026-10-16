<?php

namespace App\Console\Commands;

use App\Services\SignedPurchaseOrderRepairService;
use Illuminate\Console\Command;

class RestoreSignedPurchaseOrders extends Command
{
    protected $signature = 'scm:restore-signed-purchase-orders';

    protected $description = 'Put already-signed purchase orders back to signed when they are stuck as SCD signature pending';

    public function handle(SignedPurchaseOrderRepairService $repair): int
    {
        $restored = $repair->repair();
        $this->info("Restored {$restored} signed purchase order(s).");

        return self::SUCCESS;
    }
}
