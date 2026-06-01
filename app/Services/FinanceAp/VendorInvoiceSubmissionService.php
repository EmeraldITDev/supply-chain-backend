<?php

namespace App\Services\FinanceAp;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\Quotation;
use App\Models\RFQ;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\FinanceIntegrationService;
use App\Services\ProcurementDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class VendorInvoiceSubmissionService
{
    public function __construct(
        private VendorInvoiceGateService $gateService,
        private ProcurementDocumentService $documentService,
        private FinanceIntegrationService $financeIntegrationService,
    ) {
    }

    /**
     * @return array{
     *     canSubmit: bool,
     *     reason: string,
     *     gateType: ?string,
     *     submitted: bool,
     *     document: ?array<string, mixed>
     * }
     */
    public function statusForVendor(MRF $mrf, Vendor $vendor): array
    {
        $this->assertVendorAccess($mrf, $vendor);

        $gate = $this->gateService->status($mrf);
        $document = ProcurementDocument::query()
            ->where('mrf_id', $mrf->id)
            ->where('type', ProcurementDocument::TYPE_VENDOR_INVOICE)
            ->where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->first();

        return [
            'canSubmit' => $gate['canSubmit'] && $document === null,
            'reason' => $document !== null
                ? 'Your invoice has already been submitted for this MRF.'
                : $gate['reason'],
            'gateType' => $gate['gateType'],
            'submitted' => $document !== null,
            'document' => $document ? $this->documentService->transform($document) : null,
        ];
    }

    public function submit(MRF $mrf, Vendor $vendor, User $user, UploadedFile $file): ProcurementDocument
    {
        $this->assertVendorAccess($mrf, $vendor);

        if ($this->documentService->hasActiveDocument($mrf, ProcurementDocument::TYPE_VENDOR_INVOICE, $vendor->id)) {
            throw new \RuntimeException('An active vendor invoice already exists for this MRF.');
        }

        if (! $this->gateService->canSubmitInvoice($mrf)) {
            $gate = $this->gateService->status($mrf);
            throw new \RuntimeException($gate['reason'] ?: 'Vendor invoice submission is not open for this MRF.');
        }

        $document = $this->documentService->storeUpload(
            $mrf,
            $file,
            ProcurementDocument::TYPE_VENDOR_INVOICE,
            $user,
            $vendor->id,
            'procurement-documents/' . date('Y/m') . '/' . $mrf->mrf_id . '/vendor-invoices',
        );

        $this->linkInvoiceReferences($mrf, $document);

        if ($this->financeIntegrationService->hasPackageBeenPushed($mrf)) {
            $this->financeIntegrationService->pushDelta($mrf, 'vendor_invoice_submitted');
        }

        Log::info('Vendor invoice submitted via portal', [
            'mrf_id' => $mrf->mrf_id,
            'vendor_id' => $vendor->id,
            'document_id' => $document->id,
        ]);

        return $document->fresh(['uploader', 'vendor']);
    }

    public function assertVendorAccess(MRF $mrf, Vendor $vendor): void
    {
        if (! $mrf->selected_vendor_id) {
            throw new \RuntimeException('No vendor has been selected for this MRF.');
        }

        if ((int) $mrf->selected_vendor_id !== (int) $vendor->id) {
            throw new \RuntimeException('You are not the selected vendor for this MRF.');
        }
    }

    private function linkInvoiceReferences(MRF $mrf, ProcurementDocument $document): void
    {
        $mrf->update([
            'invoice_url' => $document->file_url,
            'invoice_share_url' => $document->file_url,
        ]);

        $rfq = RFQ::query()->where('mrf_id', $mrf->id)->first();
        if (! $rfq) {
            return;
        }

        $quotation = null;
        if ($rfq->selected_quotation_id) {
            $quotation = Quotation::query()->find($rfq->selected_quotation_id);
        }

        if (! $quotation && $mrf->selected_vendor_id) {
            $quotation = Quotation::query()
                ->where('rfq_id', $rfq->id)
                ->where('vendor_id', $mrf->selected_vendor_id)
                ->orderByDesc('created_at')
                ->first();
        }

        if ($quotation) {
            $attachments = is_array($quotation->attachments) ? $quotation->attachments : [];
            if (! in_array($document->file_url, $attachments, true)) {
                $attachments[] = $document->file_url;
                $quotation->update(['attachments' => $attachments]);
            }
        }
    }
}
