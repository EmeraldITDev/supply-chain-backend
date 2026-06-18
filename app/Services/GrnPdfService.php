<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RFQ;
use App\Models\SRF;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentDisplayPayload;
use App\Support\SignatureUrls;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;

class GrnPdfService
{
    public function __construct(
        private PurchaseOrderPdfService $purchaseOrderPdfService,
        private PriceComparisonPoLineService $priceComparisonPoLineService,
    ) {
    }

    /**
     * @return array{success: bool, error?: string, line_items?: list<array<string, mixed>>}
     */
    public function resolveLineItems(MRF $mrf, ?array $overrides = null): array
    {
        try {
            $lineItems = $this->buildLineItems($mrf, $overrides);

            return [
                'success' => true,
                'line_items' => $lineItems,
            ];
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function renderPdf(MRF $mrf, User $actingUser, array $options = []): string
    {
        $html = $this->html($mrf, $actingUser, $options);

        $dompdfOptions = new Options();
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('isRemoteEnabled', true);
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');
        $dompdfOptions->set('chroot', public_path());

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function html(MRF $mrf, User $actingUser, array $options = []): string
    {
        $viewData = $this->buildViewData($mrf, $actingUser, $options);

        return View::make('pdf.grn', $viewData)->render();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildViewData(MRF $mrf, User $actingUser, array $options = []): array
    {
        $mrf->loadMissing(['items', 'selectedVendor', 'requester', 'procurementManager']);

        $overrides = is_array($options['line_items'] ?? null) ? $options['line_items'] : null;
        $lineItems = $this->buildLineItems($mrf, $overrides);

        $receiptDate = isset($options['date_of_receipt']) || isset($options['received_at'])
            ? Carbon::parse($options['date_of_receipt'] ?? $options['received_at'])
            : now()->setTimezone('Africa/Lagos');

        $deliveryDate = ! empty($options['delivery_date'])
            ? Carbon::parse($options['delivery_date'])->format('d-m-y')
            : $receiptDate->format('d-m-y');

        $vendor = $this->resolveVendor($mrf);
        $signatories = $this->resolveSignatories($mrf, $actingUser, $vendor);

        $companyName = env('COMPANY_NAME', 'Emerald Industrial Co. CFZE');

        return [
            'logo_html' => $this->purchaseOrderPdfService->logoHtml(),
            'company_name' => $companyName,
            'company_address' => env('COMPANY_ADDRESS', ''),
            'grn_number' => (string) ($options['grn_number'] ?? $options['grnNumber'] ?? $this->defaultGrnNumber($mrf)),
            'date_of_receipt' => $receiptDate->format('d/m/Y'),
            'delivery_note_number' => $this->fieldValue($options, ['delivery_note_number', 'deliveryNoteNumber'], 'N/A'),
            'delivery_date' => $deliveryDate,
            'carrier_name' => $this->fieldValue($options, ['carrier_name', 'carrierName', 'carrier_driver_name', 'carrierDriverName'], 'N/A'),
            'driver_number' => $this->fieldValue($options, ['driver_number', 'driverNumber', 'carrier_number', 'carrierNumber'], 'N/A'),
            'vehicle_plate_number' => $this->fieldValue($options, ['vehicle_plate_number', 'vehiclePlateNumber'], 'N/A'),
            'supplier_name' => (string) ($options['supplier_name'] ?? $vendor?->vendor_name ?? $vendor?->name ?? 'Supplier'),
            'supplier_address' => (string) ($options['supplier_address'] ?? $vendor?->address ?? ''),
            'line_items' => $lineItems,
            'comments' => trim((string) ($options['comments'] ?? $options['remarks'] ?? '')),
            'signatories' => $signatories,
        ];
    }

    public function defaultGrnNumber(MRF $mrf): string
    {
        $year = now()->format('Y');
        $mrfRef = (string) ($mrf->mrf_id ?? $mrf->formatted_id ?? ('MRF-' . $mrf->id));

        $sequence = 1;
        if ($mrf->id) {
            $sequence = ProcurementDocument::query()
                ->where('mrf_id', $mrf->id)
                ->where('type', ProcurementDocument::TYPE_GRN)
                ->whereYear('uploaded_at', $year)
                ->count() + 1;
        }

        return sprintf('GRN-%s-%s-%03d', $mrfRef, $year, $sequence);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPrefillPayload(MRF $mrf, ?User $actingUser = null): array
    {
        $mrf->loadMissing(['items', 'selectedVendor', 'requester', 'procurementManager']);
        $vendor = $this->resolveVendor($mrf);
        $resolved = $this->resolveLineItems($mrf);
        $lineItems = [];

        if ($resolved['success']) {
            foreach ($resolved['line_items'] as $index => $row) {
                $qtyOrdered = (float) str_replace(',', '', (string) ($row['quantity_ordered'] ?? '0'));
                $unitPriceRaw = (float) str_replace(',', '', (string) ($row['unit_price'] ?? '0'));
                $amount = round($qtyOrdered * $unitPriceRaw, 2);

                $lineItems[] = DocumentDisplayPayload::withCamelCaseAliases([
                    'index' => $index,
                    'description' => DocumentDisplayPayload::nullIfEmpty($row['description'] ?? $row['name'] ?? null),
                    'unit' => DocumentDisplayPayload::nullIfEmpty($row['uom'] ?? null),
                    'quantity_ordered' => $qtyOrdered,
                    'quantity_received_default' => $qtyOrdered,
                    'unit_price' => $unitPriceRaw > 0 ? $unitPriceRaw : null,
                    'amount' => $amount > 0 ? $amount : null,
                    'remarks' => null,
                ]);
            }
        }

        $payload = [
            'grn_number' => $this->defaultGrnNumber($mrf),
            'mrf_ref' => DocumentDisplayPayload::nullIfEmpty($mrf->mrf_id ?? $mrf->formatted_id),
            'category' => DocumentDisplayPayload::nullIfEmpty($mrf->category),
            'vendor' => [
                'name' => DocumentDisplayPayload::nullIfEmpty($vendor?->vendor_name ?? $vendor?->name),
                'address' => DocumentDisplayPayload::nullIfEmpty($vendor?->address),
                'contact' => DocumentDisplayPayload::nullIfEmpty($vendor?->contact_person),
                'phone' => DocumentDisplayPayload::nullIfEmpty($vendor?->phone),
                'email' => DocumentDisplayPayload::nullIfEmpty($vendor?->email),
            ],
            'supplier' => [
                'name' => DocumentDisplayPayload::nullIfEmpty($vendor?->vendor_name ?? $vendor?->name),
                'address' => DocumentDisplayPayload::nullIfEmpty($vendor?->address),
            ],
            'po' => [
                'po_number' => DocumentDisplayPayload::nullIfEmpty($mrf->po_number),
                'date' => $mrf->po_generated_at?->format('Y-m-d') ?? ($mrf->date ? Carbon::parse($mrf->date)->format('Y-m-d') : null),
                'currency' => 'NGN',
            ],
            'delivery_location' => DocumentDisplayPayload::nullIfEmpty($mrf->ship_to_address),
            'project' => DocumentDisplayPayload::nullIfEmpty($mrf->title),
            'department' => DocumentDisplayPayload::nullIfEmpty($mrf->department),
            'line_items' => $lineItems,
            'authorised_signatories' => $this->authorisedSignatoryBlocks($mrf, $actingUser, $vendor),
        ];

        return DocumentDisplayPayload::withCamelCaseAliases(
            DocumentDisplayPayload::nullifyEmptyStrings($payload) ?? []
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildPersistedMetadata(MRF $mrf, User $actingUser, array $options = []): array
    {
        $resolved = $this->resolveLineItems($mrf, is_array($options['line_items'] ?? null) ? $options['line_items'] : null);
        $lineItemsReceived = [];

        if ($resolved['success']) {
            foreach ($resolved['line_items'] as $index => $row) {
                $lineItemsReceived[] = [
                    'index' => $index,
                    'description' => $row['description'] ?? $row['name'] ?? null,
                    'unit' => $row['uom'] ?? null,
                    'quantity_ordered' => (float) str_replace(',', '', (string) ($row['quantity_ordered'] ?? '0')),
                    'quantity_received' => (float) str_replace(',', '', (string) ($row['quantity_received'] ?? '0')),
                    'unit_price' => (float) str_replace(',', '', (string) ($row['unit_price'] ?? '0')),
                    'amount' => (float) str_replace(',', '', (string) ($row['total'] ?? '0')),
                    'remarks' => null,
                ];
            }
        }

        $receivedAt = $options['date_of_receipt'] ?? $options['received_at'] ?? now()->toDateString();
        $vendor = $this->resolveVendor($mrf);

        return [
            'grn_number' => (string) ($options['grn_number'] ?? $options['grnNumber'] ?? $this->defaultGrnNumber($mrf)),
            'received_at' => Carbon::parse($receivedAt)->toIso8601String(),
            'received_by' => [
                'id' => $actingUser->id,
                'name' => $actingUser->name,
            ],
            'delivery_note_number' => DocumentDisplayPayload::nullIfEmpty($options['delivery_note_number'] ?? $options['deliveryNoteNumber'] ?? null),
            'delivery_date' => DocumentDisplayPayload::nullIfEmpty($options['delivery_date'] ?? $options['deliveryDate'] ?? null),
            'carrier_name' => DocumentDisplayPayload::nullIfEmpty($options['carrier_name'] ?? $options['carrierName'] ?? null),
            'driver_number' => DocumentDisplayPayload::nullIfEmpty($options['driver_number'] ?? $options['driverNumber'] ?? null),
            'vehicle_plate_number' => DocumentDisplayPayload::nullIfEmpty($options['vehicle_plate_number'] ?? $options['vehiclePlateNumber'] ?? null),
            'comments' => DocumentDisplayPayload::nullIfEmpty($options['comments'] ?? $options['remarks'] ?? null),
            'line_items_received' => $lineItemsReceived,
            'authorised_signatories' => $this->authorisedSignatoryBlocks($mrf, $actingUser, $vendor, signWarehouse: true),
            'vendor' => [
                'name' => DocumentDisplayPayload::nullIfEmpty($vendor?->name),
                'address' => DocumentDisplayPayload::nullIfEmpty($vendor?->address),
                'contact' => DocumentDisplayPayload::nullIfEmpty($vendor?->contact_person),
                'phone' => DocumentDisplayPayload::nullIfEmpty($vendor?->phone),
            ],
            'po' => [
                'po_number' => DocumentDisplayPayload::nullIfEmpty($mrf->po_number),
                'date' => $mrf->po_generated_at?->format('Y-m-d'),
                'currency' => 'NGN',
            ],
            'delivery_location' => DocumentDisplayPayload::nullIfEmpty($mrf->ship_to_address),
            'project' => DocumentDisplayPayload::nullIfEmpty($mrf->title),
            'department' => DocumentDisplayPayload::nullIfEmpty($mrf->department),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function authorisedSignatoryBlocks(
        MRF $mrf,
        ?User $actingUser,
        ?Vendor $vendor,
        bool $signWarehouse = false,
    ): array {
        $procurementManager = $mrf->procurementManager
            ?? User::query()->whereIn('supply_chain_role', ['procurement_manager', 'procurement'])->orderBy('id')->first();
        $financeUser = User::query()->whereIn('supply_chain_role', ['finance', 'finance_officer'])->orderBy('id')->first();
        $logisticsUser = User::query()->whereIn('supply_chain_role', ['logistics', 'warehouse'])->orderBy('id')->first();

        $blocks = [
            'warehouse' => $this->signatorySlot($signWarehouse ? ($actingUser ?? $logisticsUser) : null, 'Warehouse Officer'),
            'logistics' => $this->signatorySlot($logisticsUser, 'Logistics Officer'),
            'procurement' => $this->signatorySlot($procurementManager, 'Procurement Manager'),
            'finance' => $this->signatorySlot($financeUser, 'Finance Officer'),
        ];

        if ($vendor && $signWarehouse) {
            $blocks['vendor_delivered'] = DocumentDisplayPayload::withCamelCaseAliases([
                'name' => DocumentDisplayPayload::nullIfEmpty($vendor->contact_person ?: $vendor->name),
                'title' => 'Vendor',
                'signature_url' => null,
                'signed_at' => null,
            ]);
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function signatorySlot(?User $user, string $defaultTitle): array
    {
        if (! $user) {
            return DocumentDisplayPayload::withCamelCaseAliases([
                'name' => null,
                'title' => $defaultTitle,
                'signature_url' => null,
                'signed_at' => null,
            ]);
        }

        return DocumentDisplayPayload::withCamelCaseAliases([
            'name' => DocumentDisplayPayload::nullIfEmpty($user->name),
            'title' => DocumentDisplayPayload::nullIfEmpty($user->department ?? $defaultTitle),
            'signature_url' => SignatureUrls::forUser($user),
            'signed_at' => null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $overrides
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(MRF $mrf, ?array $overrides = null): array
    {
        $sourceLines = $this->resolveSourceLineObjects($mrf);
        $rows = [];

        foreach ($sourceLines as $index => $source) {
            $qtyOrdered = (float) ($source->quantity ?? 1);
            $unitPrice = (float) ($source->unit_price ?? 0);
            $uom = (string) ($source->unit ?? 'unit');
            $name = (string) ($source->item_name ?? 'Item');
            $description = trim((string) ($source->description ?? $source->specifications ?? ''));

            $rows[] = $this->composeLineRow(
                $index + 1,
                $name,
                $description !== '' ? $description : $name,
                $uom,
                $qtyOrdered,
                $unitPrice,
                $overrides,
                $index,
            );
        }

        return $rows;
    }

    /**
     * Resolve PO/MRF line sources in the same order as unsigned PO PDF generation.
     *
     * @return list<object{item_name: string, description: string, quantity: float, unit: string, unit_price: float, total_price: float, specifications: string}>
     */
    private function resolveSourceLineObjects(MRF $mrf): array
    {
        $mrf->loadMissing(['items', 'priceComparisons.vendor']);

        $selectedRows = $this->priceComparisonPoLineService->selectedSupplierRows($mrf);
        if ($selectedRows->isNotEmpty()) {
            return $this->priceComparisonPoLineService->rowsToPoLineObjects($selectedRows)->all();
        }

        $comparisonRows = $mrf->relationLoaded('priceComparisons')
            ? $mrf->priceComparisons->sortBy('id')->values()
            : $mrf->priceComparisons()->orderBy('id')->get();

        if ($comparisonRows->isNotEmpty()) {
            $vendor = $mrf->selected_vendor_id
                ? Vendor::query()->find($mrf->selected_vendor_id)
                : $this->priceComparisonPoLineService->resolveVendorFromRows($comparisonRows);

            if ($vendor) {
                $forVendor = $comparisonRows->where('vendor_id', $vendor->id)->values();
                if ($forVendor->isNotEmpty()) {
                    return $this->priceComparisonPoLineService->rowsToPoLineObjects($forVendor)->all();
                }
            }

            return $this->priceComparisonPoLineService->rowsToPoLineObjects($comparisonRows)->all();
        }

        if ($mrf->items->isNotEmpty()) {
            return $mrf->items->values()->map(function ($item) {
                $qty = max(1.0, (float) ($item->quantity ?? 1));

                return (object) [
                    'item_name' => (string) ($item->item_name ?? 'Item'),
                    'description' => (string) ($item->description ?? ''),
                    'quantity' => $qty,
                    'unit' => (string) ($item->unit ?? 'unit'),
                    'unit_price' => (float) ($item->unit_price ?? 0),
                    'total_price' => (float) ($item->total_price ?? (($item->unit_price ?? 0) * $qty)),
                    'specifications' => (string) ($item->specifications ?? ''),
                ];
            })->all();
        }

        $quotationItems = $this->resolveQuotationItems($mrf);
        if ($quotationItems->isNotEmpty()) {
            return $quotationItems->values()->map(function (QuotationItem $quoteItem) {
                return (object) [
                    'item_name' => (string) ($quoteItem->item_name ?? 'Item'),
                    'description' => trim((string) ($quoteItem->description ?? $quoteItem->specifications ?? '')),
                    'quantity' => (float) ($quoteItem->quantity ?? 1),
                    'unit' => (string) ($quoteItem->unit ?? 'unit'),
                    'unit_price' => (float) ($quoteItem->unit_price ?? 0),
                    'total_price' => (float) ($quoteItem->total_price ?? 0),
                    'specifications' => (string) ($quoteItem->specifications ?? ''),
                ];
            })->all();
        }

        $srfLines = $this->resolveLinkedSrfLineObjects($mrf);
        if ($srfLines !== []) {
            return $srfLines;
        }

        $rfq = RFQ::query()->where('mrf_id', $mrf->id)->with('items')->first();
        if ($rfq && $rfq->items->isNotEmpty()) {
            return $rfq->items->values()->map(function ($item) {
                return (object) [
                    'item_name' => (string) ($item->item_name ?? 'Item'),
                    'description' => trim((string) ($item->description ?? $item->specifications ?? '')),
                    'quantity' => (float) ($item->quantity ?? 1),
                    'unit' => (string) ($item->unit ?? 'unit'),
                    'unit_price' => 0.0,
                    'total_price' => 0.0,
                    'specifications' => (string) ($item->specifications ?? ''),
                ];
            })->all();
        }

        $qty = max(1.0, (float) ($mrf->quantity ?? 1));
        $estimated = (float) ($mrf->estimated_cost ?? 0);
        $unitPrice = $qty > 0 ? $estimated / $qty : $estimated;

        if ($estimated <= 0 && trim((string) ($mrf->title ?? '')) === '') {
            throw new \RuntimeException('No line items found on this MRF. Add MRF line items or price comparisons before generating a GRN.');
        }

        return [
            (object) [
                'item_name' => (string) ($mrf->title ?: 'Goods / services'),
                'description' => (string) ($mrf->description ?? ''),
                'quantity' => $qty,
                'unit' => 'unit',
                'unit_price' => $unitPrice,
                'total_price' => $estimated,
                'specifications' => '',
            ],
        ];
    }

    /**
     * @return list<object{item_name: string, description: string, quantity: float, unit: string, unit_price: float, total_price: float, specifications: string}>
     */
    private function resolveLinkedSrfLineObjects(MRF $mrf): array
    {
        $haystack = implode(' ', array_filter([
            (string) $mrf->title,
            (string) $mrf->description,
            (string) $mrf->justification,
        ]));

        if (! preg_match('/SRF-\d{4}-\d+/i', $haystack, $matches)) {
            return [];
        }

        $srf = SRF::query()
            ->where('srf_id', strtoupper((string) $matches[0]))
            ->with('items')
            ->first();

        if (! $srf || $srf->items->isEmpty()) {
            return [];
        }

        return $srf->items->values()->map(function ($item) {
            $qty = max(1.0, (float) ($item->quantity ?? 1));
            $unitPrice = (float) ($item->unit_price ?? $item->budget_amount ?? 0);

            return (object) [
                'item_name' => (string) ($item->item_name ?? $item->description ?? 'Item'),
                'description' => trim((string) ($item->description ?? $item->specifications ?? '')),
                'quantity' => $qty,
                'unit' => (string) ($item->unit ?? 'unit'),
                'unit_price' => $unitPrice,
                'total_price' => (float) ($item->total_price ?? ($unitPrice * $qty)),
                'specifications' => (string) ($item->specifications ?? ''),
            ];
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>|null  $overrides
     * @return array<string, mixed>
     */
    private function composeLineRow(
        int $itemNumber,
        string $name,
        string $description,
        string $uom,
        float $qtyOrdered,
        float $unitPrice,
        ?array $overrides,
        int $index,
    ): array {
        $override = $this->findLineOverride($overrides, $index, $itemNumber);
        $qtyReceived = isset($override['quantity_received']) || isset($override['quantityReceived'])
            ? (float) ($override['quantity_received'] ?? $override['quantityReceived'])
            : $qtyOrdered;

        if (isset($override['unit_price']) || isset($override['unitPrice'])) {
            $unitPrice = (float) ($override['unit_price'] ?? $override['unitPrice']);
        }

        $lineTotal = round($qtyReceived * $unitPrice, 2);

        return [
            'item' => $itemNumber,
            'name' => $name,
            'description' => $description !== '' ? $description : $name,
            'uom' => $uom,
            'quantity_ordered' => $this->fmtQty($qtyOrdered),
            'quantity_received' => $this->fmtQty($qtyReceived),
            'unit_price' => $this->fmtMoney($unitPrice),
            'total' => $this->fmtMoney($lineTotal),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $overrides
     * @return array<string, mixed>|null
     */
    private function findLineOverride(?array $overrides, int $index, int $itemNumber): ?array
    {
        if ($overrides === null) {
            return null;
        }

        foreach ($overrides as $override) {
            if (! is_array($override)) {
                continue;
            }

            $lineIndex = $override['index'] ?? $override['line_index'] ?? null;
            $lineItem = $override['item'] ?? $override['item_number'] ?? $override['itemNumber'] ?? null;

            if ($lineIndex !== null && (int) $lineIndex === $index) {
                return $override;
            }

            if ($lineItem !== null && (int) $lineItem === $itemNumber) {
                return $override;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, QuotationItem>
     */
    private function resolveQuotationItems(MRF $mrf): Collection
    {
        $rfq = RFQ::query()->where('mrf_id', $mrf->id)->first();
        if (! $rfq) {
            return collect();
        }

        $quotation = null;
        if ($rfq->selected_quotation_id) {
            $quotation = Quotation::query()->find($rfq->selected_quotation_id);
        }

        if (! $quotation) {
            $quotation = Quotation::query()
                ->where('rfq_id', $rfq->id)
                ->where('status', 'Approved')
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $quotation) {
            return collect();
        }

        return QuotationItem::query()
            ->where('quotation_id', $quotation->id)
            ->orderBy('id')
            ->get();
    }

    private function resolveVendor(MRF $mrf): ?Vendor
    {
        $mrf->loadMissing(['selectedVendor', 'priceComparisons.vendor']);

        if ($mrf->selectedVendor) {
            return $mrf->selectedVendor;
        }

        $selectedRows = $this->priceComparisonPoLineService->selectedSupplierRows($mrf);
        if ($selectedRows->isNotEmpty()) {
            $vendor = $this->priceComparisonPoLineService->resolveVendorFromRows($selectedRows);
            if ($vendor) {
                return $vendor;
            }
        }

        $comparisonRows = $mrf->relationLoaded('priceComparisons')
            ? $mrf->priceComparisons->sortBy('id')->values()
            : $mrf->priceComparisons()->with('vendor')->orderBy('id')->get();

        if ($comparisonRows->isNotEmpty()) {
            $vendor = $this->priceComparisonPoLineService->resolveVendorFromRows($comparisonRows);
            if ($vendor) {
                return $vendor;
            }
        }

        $rfq = RFQ::query()->where('mrf_id', $mrf->id)->first();
        if (! $rfq) {
            return null;
        }

        $quotation = null;
        if ($rfq->selected_quotation_id) {
            $quotation = Quotation::query()->with('vendor')->find($rfq->selected_quotation_id);
        }
        if (! $quotation) {
            $quotation = Quotation::query()
                ->where('rfq_id', $rfq->id)
                ->where('status', 'Approved')
                ->with('vendor')
                ->orderByDesc('created_at')
                ->first();
        }

        return $quotation?->vendor;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function resolveSignatories(MRF $mrf, User $actingUser, ?Vendor $vendor): array
    {
        $receivedBy = $this->resolveReceivedByUser($mrf, $actingUser);
        $procurementManager = $mrf->procurementManager
            ?? User::query()
                ->whereIn('supply_chain_role', ['procurement_manager', 'procurement'])
                ->when($mrf->procurement_manager_id, fn ($q) => $q->orWhere('id', $mrf->procurement_manager_id))
                ->orderByRaw("CASE WHEN supply_chain_role = 'procurement_manager' THEN 0 ELSE 1 END")
                ->first();

        return [
            'vendor_delivered' => [
                'name' => (string) ($vendor?->contact_person ?: $vendor?->name ?: ''),
                'position' => 'Vendor',
                'sign_date' => '',
                'phone' => (string) ($vendor?->phone ?? ''),
                'email' => (string) ($vendor?->email ?? ''),
            ],
            'emerald_received' => [
                'name' => (string) ($receivedBy?->name ?? $mrf->requester_name ?? ''),
                'position' => $this->formatUserPosition($receivedBy),
                'sign_date' => '',
                'phone' => (string) ($receivedBy?->phone ?? ''),
                'email' => (string) ($receivedBy?->email ?? ''),
            ],
            'vendor_witnessed' => [
                'name' => '',
                'position' => '',
                'sign_date' => '',
                'phone' => '',
                'email' => '',
            ],
            'emerald_supervised' => [
                'name' => (string) ($procurementManager?->name ?? 'Procurement Manager'),
                'position' => 'Site Manager',
                'sign_date' => '',
                'phone' => (string) ($procurementManager?->phone ?? ''),
                'email' => '',
            ],
        ];
    }

    private function resolveReceivedByUser(MRF $mrf, User $actingUser): ?User
    {
        if (\App\Support\ProcurementOverviewAccess::canManageDeliveryDocuments($actingUser)) {
            return $actingUser;
        }

        return $mrf->requester ?? $actingUser;
    }

    private function formatUserPosition(?User $user): string
    {
        if (! $user) {
            return '';
        }

        if (! empty($user->department)) {
            return (string) $user->department;
        }

        return ucwords(str_replace('_', ' ', (string) ($user->scmRole() ?? 'Staff')));
    }

    /**
     * @param  list<string>  $keys
     */
    private function fieldValue(array $options, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $options[$key] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $default;
    }

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    private function fmtMoney(float $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }
}
