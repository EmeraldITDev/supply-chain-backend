<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\RFQ;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WorkflowStateService;
use App\Support\PurchaseOrderCurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuild unsigned Emerald PO PDFs for MRFs awaiting SCD signature
 * (so layout/label fixes apply before the director signs).
 */
class RefreshUnsignedPurchaseOrderPdfService
{
    public function __construct(
        private PurchaseOrderPdfService $pdfService,
        private PriceComparisonPoLineService $poLineService,
        private ProcurementDocumentService $documentService,
        private PaymentScheduleService $paymentScheduleService,
    ) {
    }

    /**
     * @return array{success: bool, mrf_id?: string, po_number?: string, error?: string}
     */
    public function refresh(MRF $mrf, ?User $actor = null): array
    {
        if (! $this->isAwaitingScdSignature($mrf)) {
            return [
                'success' => false,
                'mrf_id' => $mrf->mrf_id,
                'error' => 'MRF is not awaiting SCD signature with an unsigned PO.',
            ];
        }

        $poNumber = trim((string) ($mrf->po_number ?: $mrf->linked_po_id ?: ''));
        if ($poNumber === '') {
            return [
                'success' => false,
                'mrf_id' => $mrf->mrf_id,
                'error' => 'PO number missing.',
            ];
        }

        try {
            $payload = $this->buildPayload($mrf);
            $actor = $actor ?? $this->resolveActor($mrf);
            $pdfBinary = $this->pdfService->renderWorkflowPdf($payload, $poNumber, $actor);

            $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 's3'));
            $poFileName = 'po_'.$poNumber.'_emerald_v2_'.time().'.pdf';
            $poPath = 'purchase-orders/'.date('Y/m').'/'.$poFileName;

            if ($disk !== 's3') {
                $directory = dirname($poPath);
                if (! empty($directory) && ! Storage::disk($disk)->exists($directory)) {
                    Storage::disk($disk)->makeDirectory($directory, 0755, true);
                }
            }

            Storage::disk($disk)->put($poPath, $pdfBinary);
            $poUrl = $this->fileUrl($poPath, $disk);

            $mrf->update([
                'unsigned_po_url' => $poUrl,
                'unsigned_po_share_url' => $poUrl,
            ]);

            try {
                $this->documentService->registerExistingStorageFile(
                    $mrf,
                    ProcurementDocument::TYPE_PO_PDF,
                    $poPath,
                    $poUrl,
                    $poFileName,
                    $actor,
                    $this->documentService->resolveVendorId($mrf),
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to register refreshed unsigned PO PDF', [
                    'mrf_id' => $mrf->mrf_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'mrf_id' => $mrf->mrf_id,
                'po_number' => $poNumber,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to refresh unsigned PO PDF', [
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'mrf_id' => $mrf->mrf_id,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAwaitingScdSignature(MRF $mrf): bool
    {
        $hasUnsigned = filled($mrf->unsigned_po_url);
        $notSigned = blank($mrf->signed_po_url);
        $awaiting = strtolower((string) $mrf->status) === 'awaiting_scd_signature'
            || ($mrf->workflow_state ?? null) === WorkflowStateService::STATE_PO_GENERATED;

        return $hasUnsigned && $notSigned && $awaiting;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(MRF $mrf): array
    {
        $mrf->loadMissing(['items', 'priceComparisons.vendor', 'selectedVendor', 'paymentSchedule.milestones']);

        $vendor = $mrf->selectedVendor;
        $rfq = RFQ::where('mrf_id', $mrf->id)->with(['items', 'quotations' => fn ($q) => $q->orderByDesc('id')])->first();

        $items = collect();
        $quotationBlock = [
            'id' => 'REFRESH-QUOTE',
            'total_amount' => (float) ($mrf->po_value ?? $mrf->estimated_cost ?? 0),
            'currency' => PurchaseOrderCurrency::normalize($mrf->currency),
            'payment_terms' => $mrf->po_payment_terms,
        ];

        if ($rfq) {
            $selected = $rfq->quotations->firstWhere('status', 'Approved')
                ?? $rfq->quotations->firstWhere('is_selected', true)
                ?? $rfq->quotations->first();
            if ($selected) {
                if (! $vendor && $selected->vendor_id) {
                    $vendor = Vendor::query()->find($selected->vendor_id);
                }
                $selected->loadMissing('items');
                foreach ($selected->items as $qi) {
                    $items->push([
                        'name' => $qi->item_name ?? 'Item',
                        'item_name' => $qi->item_name ?? 'Item',
                        'description' => $qi->description ?? '',
                        'quantity' => $qi->quantity ?? 1,
                        'unit' => $qi->unit ?? 'unit',
                        'unit_price' => (float) ($qi->unit_price ?? 0),
                        'total_price' => (float) ($qi->total_price ?? 0),
                        'specifications' => $qi->specifications ?? '',
                    ]);
                }
                $quotationBlock = [
                    'id' => $selected->quotation_id ?? 'QUOTE',
                    'total_amount' => (float) ($selected->total_amount ?? $selected->price ?? 0),
                    'currency' => PurchaseOrderCurrency::normalize($selected->currency ?? $mrf->currency),
                    'payment_terms' => $selected->payment_terms ?? $mrf->po_payment_terms,
                ];
            }
        }

        if ($items->isEmpty()) {
            $selectedRows = $this->poLineService->selectedSupplierRows($mrf);
            if ($selectedRows->isNotEmpty()) {
                $comparisonVendor = $this->poLineService->resolveVendorFromRows($selectedRows);
                if ($comparisonVendor) {
                    $vendor = $comparisonVendor;
                }
                $items = $this->poLineService->rowsToPoLineObjects($selectedRows)->map(fn ($o) => [
                    'name' => $o->item_name ?? 'Item',
                    'item_name' => $o->item_name ?? 'Item',
                    'description' => $o->description ?? '',
                    'quantity' => $o->quantity ?? 1,
                    'unit' => $o->unit ?? 'unit',
                    'unit_price' => (float) ($o->unit_price ?? 0),
                    'total_price' => (float) ($o->total_price ?? 0),
                    'specifications' => $o->specifications ?? '',
                ]);
            }
        }

        if ($items->isEmpty()) {
            foreach ($mrf->items as $it) {
                $items->push([
                    'name' => $it->item_name ?? 'Item',
                    'item_name' => $it->item_name ?? 'Item',
                    'description' => $it->description ?? '',
                    'quantity' => max(1, (int) ($it->quantity ?? 1)),
                    'unit' => $it->unit ?? 'unit',
                    'unit_price' => (float) ($it->unit_price ?? 0),
                    'total_price' => (float) ($it->total_price ?? 0),
                    'specifications' => $it->specifications ?? '',
                ]);
            }
        }

        if ($items->isEmpty()) {
            $qty = max(1, (int) ($mrf->quantity ?? 1));
            $est = (float) ($mrf->po_value ?? $mrf->estimated_cost ?? 0);
            $items->push([
                'name' => $mrf->title ?: 'Goods / services',
                'item_name' => $mrf->title ?: 'Goods / services',
                'description' => (string) ($mrf->description ?? ''),
                'quantity' => $qty,
                'unit' => 'unit',
                'unit_price' => $qty > 0 ? $est / $qty : $est,
                'total_price' => $est,
                'specifications' => '',
            ]);
        }

        $currency = PurchaseOrderCurrency::normalize($mrf->currency);
        $subtotal = (float) $items->sum(fn ($row) => (float) ($row['total_price'] ?? 0));
        $taxRate = (float) ($mrf->tax_rate ?? 0);
        $taxAmount = (float) ($mrf->tax_amount ?? 0);
        if ($taxAmount <= 0 && $taxRate > 0) {
            $taxAmount = ($subtotal * $taxRate) / 100;
        }
        $poTotal = $subtotal + $taxAmount;

        $schedule = $mrf->paymentSchedule;
        $milestones = $schedule
            ? $this->paymentScheduleService->milestonesForPoPdf($schedule, $poTotal, $currency)
            : [];

        $vendorBlock = $vendor ? [
            'name' => (string) $vendor->name,
            'contact_person' => (string) ($vendor->contact_person ?? ''),
            'email' => (string) ($vendor->email ?? ''),
            'phone' => (string) ($vendor->phone ?? ''),
            'address' => (string) ($vendor->address ?? ''),
            'tax_id' => (string) ($vendor->tax_id ?? ''),
            'vendor_id' => (string) ($vendor->vendor_id ?? ''),
            'id' => $vendor->id,
        ] : [
            'name' => 'Supplier',
            'contact_person' => '',
            'email' => '',
            'phone' => '',
            'address' => '',
            'tax_id' => '',
        ];

        return [
            'mrf' => [
                'id' => $mrf->mrf_id,
                'formatted_id' => $mrf->formatted_id,
                'title' => $mrf->title,
                'description' => $mrf->description,
                'justification' => $mrf->justification,
                'requester_name' => $mrf->requester_name,
                'department' => $mrf->department,
                'category' => $mrf->category,
                'estimated_cost' => $mrf->estimated_cost,
                'currency' => $currency,
                'contract_type' => $mrf->contract_type,
                'po_type' => $mrf->po_type ?: 'goods',
                'po_terms_mode' => $mrf->po_terms_mode,
                'custom_terms' => $mrf->custom_terms,
                'po_payment_terms' => $mrf->po_payment_terms,
                'po_special_terms' => $mrf->po_special_terms,
                'ship_to_address' => $mrf->ship_to_address,
                'invoice_submission_email' => $mrf->invoice_submission_email,
                'invoice_submission_cc' => $mrf->invoice_submission_cc,
                'tax_rate' => $taxRate,
            ],
            'rfq' => [
                'id' => $rfq?->rfq_id ?? ($mrf->mrf_id.'-RFQ'),
                'title' => (string) ($rfq?->title ?? $mrf->title ?? 'Requisition'),
            ],
            'quotation' => $quotationBlock,
            'vendor' => $vendorBlock,
            'company' => [
                'name' => env('COMPANY_NAME', config('app.name', 'Emerald Industrial Co. FZE')),
                'address' => env('COMPANY_ADDRESS', ''),
                'phone' => env('COMPANY_PHONE', ''),
                'email' => env('COMPANY_EMAIL', config('mail.from.address', '')),
                'tax_id' => env('COMPANY_TAX_ID', ''),
                'website' => env('COMPANY_WEBSITE', 'https://emeraldcfze.com/'),
            ],
            'items' => $items->values()->all(),
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'ship_to' => (string) ($mrf->ship_to_address ?? env('COMPANY_ADDRESS', '')),
            'invoice_submission_email' => (string) ($mrf->invoice_submission_email ?? ''),
            'invoice_submission_cc' => (string) ($mrf->invoice_submission_cc ?? ''),
            'payment_milestones' => $milestones,
            'special_terms' => (string) ($mrf->po_special_terms ?? ''),
        ];
    }

    private function resolveActor(MRF $mrf): User
    {
        if ($mrf->procurement_manager_id) {
            $user = User::query()->find($mrf->procurement_manager_id);
            if ($user) {
                return $user;
            }
        }

        return User::query()->find($mrf->requester_id)
            ?? User::query()->where('role', 'admin')->first()
            ?? new User(['name' => 'System', 'id' => 0]);
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
}
