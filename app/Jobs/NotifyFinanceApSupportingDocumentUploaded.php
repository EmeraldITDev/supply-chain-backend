<?php

namespace App\Jobs;

use App\Services\Finance\FinanceIntegrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyFinanceApSupportingDocumentUploaded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $mrfId,
        public readonly int $documentId,
    ) {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(FinanceIntegrationService $financeIntegration): void
    {
        $financeIntegration->sendSupportingDocumentUploadedEvent($this->mrfId, $this->documentId);
    }
}
