<?php

namespace App\Jobs;

use App\Models\MRF;
use App\Models\User;
use App\Models\ProcurementDocument;
use App\Services\PurchaseOrderPdfService;
use App\Services\WorkflowStateService;
use App\Services\FinanceAp\FinanceApWorkflowOrchestrator;
use App\Services\ProcurementDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPoSignatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public readonly int    $mrfId,
        public readonly int    $userId,
        public readonly array  $poData,
        public readonly string $signaturePath,
        public readonly string $signatureDisk,
        public readonly string $poNumber,
    ) {}

    public function handle(): void
    {
        $mrf  = MRF::find($this->mrfId);
        $user = User::find($this->userId);

        if (! $mrf || ! $user) {
            Log::error('ProcessPoSignatureJob: MRF or user not found', [
                'mrf_id'  => $this->mrfId,
                'user_id' => $this->userId,
            ]);
            return;
        }

        try {
            // Re-embed signature for PDF rendering
            $poData = $this->poData;
            $sigDisk = Storage::disk($this->signatureDisk);

            if ($this->signatureDisk === 's3') {
                $tmp = tempnam(sys_get_temp_dir(), 'sig_');
                file_put_contents($tmp, $sigDisk->get($this->signaturePath));
                $poData['signature_image_url'] = $tmp;
            } else {
                $poData['signature_image_url'] = $sigDisk->path($this->signaturePath);
            }

            // Generate PDF
            $pdfBinary = app(PurchaseOrderPdfService::class)
                ->renderWorkflowPdf($poData, $this->poNumber, $user);

            // Store signed PDF
            $docsDisk   = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
            $signedPath = 'purchase-orders/signed/' . date('Y/m') . '/po_signed_' . $this->poNumber . '_' . time() . '.pdf';
            Storage::disk($docsDisk)->put($signedPath, $pdfBinary);

            // Get URL
            $signedUrl = $docsDisk === 's3'
                ? Storage::disk($docsDisk)->temporaryUrl($signedPath, now()->addHours(168))
                : Storage::disk($docsDisk)->url($signedPath);

            // Apply signed state
            app(WorkflowStateService::class)->applyPoSigned($mrf, $user, [
                'signed_po_url'       => $signedUrl,
                'signed_po_share_url' => $signedUrl,
                'po_signed_at'        => now(),
            ], force: true);

            $mrf->refresh();

            // Register in document registry
            app(ProcurementDocumentService::class)->registerExistingStorageFile(
                $mrf,
                ProcurementDocument::TYPE_SIGNED_PO,
                $signedPath,
                $signedUrl,
                basename($signedPath),
                $user,
                app(ProcurementDocumentService::class)->resolveVendorId($mrf),
            );

            app(FinanceApWorkflowOrchestrator::class)->afterPoSigned($mrf, $user);

            Log::info('ProcessPoSignatureJob: completed', [
                'mrf_id'    => $mrf->mrf_id,
                'po_number' => $this->poNumber,
            ]);

            // Clean up temp file
            if ($this->signatureDisk === 's3' && isset($tmp) && file_exists($tmp)) {
                unlink($tmp);
            }

        } catch (\Throwable $e) {
            Log::error('ProcessPoSignatureJob: failed', [
                'mrf_id' => $mrf->mrf_id,
                'error'  => $e->getMessage(),
            ]);

            // Reset so SCD can retry
            $mrf->update(['status' => 'awaiting_scd_signature']);

            throw $e;
        }
    }
}
