<?php

namespace App\Jobs;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use App\Services\PurchaseOrderPdfService;
use App\Services\WorkflowStateService;
use App\Support\PurchaseOrderCurrency;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPurchaseOrderGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $mrfPrimaryKey,
        public int $userId,
        public array $context,
    ) {
    }

    public function handle(
        PurchaseOrderPdfService $pdfService,
        PaymentScheduleService $paymentScheduleService,
    ): void {
        $jobStarted = microtime(true);
        $mrf = MRF::query()->find($this->mrfPrimaryKey);
        $user = User::query()->find($this->userId);

        if (! $mrf || ! $user) {
            Log::warning('PO generation job skipped — MRF or user missing', [
                'mrf_id' => $this->mrfPrimaryKey,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $poNumber = (string) ($this->context['po_number'] ?? $mrf->po_number);
        $poData = $this->context['po_data'] ?? null;

        if (! is_array($poData)) {
            $this->markFailed($mrf, 'PO generation job missing po_data payload.');
            Log::error('PO generation job missing po_data payload', ['mrf_id' => $mrf->mrf_id]);

            return;
        }

        Log::info('PO generation job: PDF start', [
            'mrf_id' => $mrf->mrf_id,
            'po_number' => $poNumber,
        ]);

        try {
            $pdfStarted = microtime(true);
            $pdfBinary = $pdfService->renderWorkflowPdf($poData, $poNumber, $user);
            $pdfMs = (int) round((microtime(true) - $pdfStarted) * 1000);
        } catch (\Throwable $e) {
            $this->markFailed($mrf, 'PDF generation failed: '.$e->getMessage());
            Log::error('PO PDF generation failed in queue', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('PO generation job: PDF done', [
            'mrf_id' => $mrf->mrf_id,
            'pdf_ms' => $pdfMs,
            'bytes' => strlen($pdfBinary),
        ]);

        try {
            $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
            // `_emerald_v2_` marks PDFs generated with the canonical Emerald layout.
            $poFileName = 'po_'.$poNumber.'_emerald_v2_'.time().'.pdf';
            $poPath = 'purchase-orders/'.date('Y/m').'/'.$poFileName;

            if ($disk !== 's3') {
                $directory = dirname($poPath);
                if (! empty($directory) && ! Storage::disk($disk)->exists($directory)) {
                    Storage::disk($disk)->makeDirectory($directory, 0755, true);
                }
            }

            $storeStarted = microtime(true);
            Storage::disk($disk)->put($poPath, $pdfBinary);
            $poUrl = $this->fileUrl($poPath, $disk);
            $storeMs = (int) round((microtime(true) - $storeStarted) * 1000);
        } catch (\Throwable $e) {
            $this->markFailed($mrf, 'Failed to store PO PDF: '.$e->getMessage());
            Log::error('PO PDF storage failed in queue', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $taxRate = (float) ($this->context['tax_rate'] ?? 0);
        $taxAmount = (float) ($this->context['tax_amount'] ?? 0);
        $subtotal = (float) ($this->context['subtotal'] ?? 0);
        $fastTrack = (bool) ($this->context['fast_track'] ?? false);
        $isRegeneration = (bool) ($this->context['is_regeneration'] ?? false);
        $hasRfq = (bool) ($this->context['has_rfq'] ?? true);

        $dbStarted = microtime(true);
        $mrf->update([
            'po_number' => $poNumber,
            'unsigned_po_url' => $poUrl,
            'unsigned_po_share_url' => $poUrl,
            'po_generated_at' => now(),
            'po_draft_saved_at' => null,
            'workflow_state' => WorkflowStateService::STATE_PO_GENERATED,
            'status' => 'awaiting_scd_signature',
            'current_stage' => 'supply_chain',
            'rejection_reason' => null,
            'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
            'po_generation_error' => null,
            'po_generation_failed_at' => null,
        ]);
        $dbMs = (int) round((microtime(true) - $dbStarted) * 1000);

        $poTotal = $subtotal + $taxAmount;
        if ($poTotal <= 0) {
            $poTotal = (float) ($mrf->estimated_cost ?? 0);
        }
        $paymentScheduleService->lockOnPoGeneration($mrf, $poTotal);

        $this->registerPoPdf($mrf, $user, $poPath, $poUrl, $poFileName);

        ProcessPurchaseOrderNotificationsJob::dispatch(
            $mrf->id,
            $user->id,
            $poNumber,
            $fastTrack,
            $isRegeneration,
            $hasRfq,
        )
            ->onConnection('database')
            ->onQueue(config('queue.connections.database.queue', 'default'));

        Log::info('PO generation job completed', [
            'mrf_id' => $mrf->mrf_id,
            'po_number' => $poNumber,
            'pdf_ms' => $pdfMs,
            'store_ms' => $storeMs,
            'db_ms' => $dbMs,
            'elapsed_ms' => (int) round((microtime(true) - $jobStarted) * 1000),
        ]);
    }

    public function failed(?\Throwable $e): void
    {
        $mrf = MRF::query()->find($this->mrfPrimaryKey);
        if ($mrf) {
            $this->markFailed($mrf, $e?->getMessage() ?? 'PO generation job failed after retries.');
        }
    }

    private function markFailed(MRF $mrf, string $message): void
    {
        $cols = array_flip(\App\Support\TableColumnCache::columnsFor('m_r_f_s'));
        $update = [
            'po_generation_error' => mb_substr($message, 0, 2000),
            'po_generation_failed_at' => now(),
        ];
        if ($cols !== []) {
            $update = array_intersect_key($update, $cols);
        }
        if ($update !== []) {
            $mrf->update($update);
        }
    }

    private function fileUrl(string $filePath, string $disk): string
    {
        if ($disk === 's3') {
            try {
                return Storage::disk($disk)->temporaryUrl($filePath, now()->addHours(168));
            } catch (\Throwable) {
                return Storage::disk($disk)->url($filePath);
            }
        }

        return Storage::disk($disk)->url($filePath);
    }

    private function registerPoPdf(MRF $mrf, User $user, string $path, string $url, string $fileName): void
    {
        try {
            $documentService = app(ProcurementDocumentService::class);
            $documentService->registerExistingStorageFile(
                $mrf,
                ProcurementDocument::TYPE_PO_PDF,
                $path,
                $url,
                $fileName,
                $user,
                $documentService->resolveVendorId($mrf),
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to register PO PDF in procurement document registry', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
