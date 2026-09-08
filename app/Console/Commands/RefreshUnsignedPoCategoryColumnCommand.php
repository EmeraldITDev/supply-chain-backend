<?php

namespace App\Console\Commands;

use App\Models\MRF;
use App\Services\RefreshUnsignedPurchaseOrderPdfService;
use App\Services\WorkflowStateService;
use Illuminate\Console\Command;

class RefreshUnsignedPoCategoryColumnCommand extends Command
{
    protected $signature = 'po:refresh-unsigned-category
                            {--dry-run : List matching POs without regenerating PDFs}';

    protected $description = 'Regenerate unsigned PO PDFs awaiting SCD signature so the first column uses PO type (e.g. LOGISTICS) and header "PO category"';

    public function handle(RefreshUnsignedPurchaseOrderPdfService $refresher): int
    {
        $query = MRF::query()
            ->whereNotNull('unsigned_po_url')
            ->where('unsigned_po_url', '!=', '')
            ->where(function ($q) {
                $q->whereNull('signed_po_url')->orWhere('signed_po_url', '=', '');
            })
            ->where(function ($q) {
                $q->whereRaw('LOWER(status) = ?', ['awaiting_scd_signature'])
                    ->orWhere('workflow_state', WorkflowStateService::STATE_PO_GENERATED);
            });

        $mrfs = $query->orderBy('id')->get();
        $this->info('Found '.$mrfs->count().' unsigned PO(s) awaiting SCD signature.');

        if ($this->option('dry-run')) {
            foreach ($mrfs as $mrf) {
                $this->line(sprintf(
                    '- %s | po=%s | po_type=%s | category=%s',
                    $mrf->mrf_id,
                    $mrf->po_number,
                    $mrf->po_type ?: 'goods',
                    $mrf->category
                ));
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        foreach ($mrfs as $mrf) {
            $result = $refresher->refresh($mrf);
            if ($result['success'] ?? false) {
                $ok++;
                $this->info("Refreshed {$mrf->mrf_id} ({$mrf->po_number})");
            } else {
                $failed++;
                $this->error("Failed {$mrf->mrf_id}: ".($result['error'] ?? 'unknown'));
            }
        }

        $this->info("Done. refreshed={$ok} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
