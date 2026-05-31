<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RFQ;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class GrnPdfService
{
    /**
     * @return array{success: bool, error?: string, line_items?: list<array<string, mixed>>}
     */
    public function resolveLineItems(MRF $mrf): array
    {
        $mrf->loadMissing('items');

        if ($mrf->items->isNotEmpty()) {
            return [
                'success' => true,
                'line_items' => $this->mapMrfItems($mrf->items),
            ];
        }

        $rfq = RFQ::query()->where('mrf_id', $mrf->id)->first();
        if (! $rfq) {
            return [
                'success' => false,
                'error' => 'No line items found on this MRF. Add MRF line items before generating a GRN.',
            ];
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

        if ($quotation) {
            $quotationItems = QuotationItem::query()
                ->where('quotation_id', $quotation->id)
                ->get();

            if ($quotationItems->isNotEmpty()) {
                return [
                    'success' => true,
                    'line_items' => $quotationItems->map(fn ($item) => [
                        'name' => (string) ($item->item_name ?? 'Item'),
                        'description' => trim((string) ($item->description ?? $item->specifications ?? '')),
                        'quantity' => $this->fmtQty((float) ($item->quantity ?? 1)),
                        'unit' => (string) ($item->unit ?? 'unit'),
                    ])->values()->all(),
                ];
            }
        }

        $rfq->loadMissing('items');
        if ($rfq->items->isNotEmpty()) {
            return [
                'success' => true,
                'line_items' => $rfq->items->map(fn ($item) => [
                    'name' => (string) ($item->item_name ?? 'Item'),
                    'description' => trim((string) ($item->description ?? $item->specifications ?? '')),
                    'quantity' => $this->fmtQty((float) ($item->quantity ?? 1)),
                    'unit' => (string) ($item->unit ?? 'unit'),
                ])->values()->all(),
            ];
        }

        return [
            'success' => false,
            'error' => 'No line items found on this MRF. Add MRF line items before generating a GRN.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function renderPdf(MRF $mrf, array $options = []): string
    {
        $mrf->loadMissing(['items', 'selectedVendor']);

        $resolved = $this->resolveLineItems($mrf);
        if (! $resolved['success']) {
            throw new \RuntimeException($resolved['error'] ?? 'Unable to resolve GRN line items.');
        }

        $html = $this->html($mrf, $resolved['line_items'], $options);

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
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $options
     */
    public function html(MRF $mrf, array $lineItems, array $options = []): string
    {
        $date = isset($options['received_at'])
            ? Carbon::parse($options['received_at'])
            : now()->setTimezone('Africa/Lagos');

        $grnNumber = (string) ($options['grn_number'] ?? $this->defaultGrnNumber($mrf));

        return View::make('pdf.grn', [
            'grn_number' => $grnNumber,
            'grn_date' => $date->format('d/m/Y'),
            'po_number' => (string) ($mrf->po_number ?? ''),
            'mrf_reference' => (string) ($mrf->formatted_id ?? $mrf->mrf_id ?? ''),
            'supplier_name' => (string) ($options['supplier_name'] ?? $mrf->selectedVendor?->name ?? 'Supplier'),
            'supplier_address' => (string) ($options['supplier_address'] ?? $mrf->selectedVendor?->address ?? ''),
            'received_at' => (string) ($options['received_at_label'] ?? env('COMPANY_ADDRESS', 'Emerald Industrial Co. FZE')),
            'department' => (string) ($mrf->department ?? ''),
            'line_items' => $lineItems,
            'remarks' => trim((string) ($options['remarks'] ?? '')),
        ])->render();
    }

    public function defaultGrnNumber(MRF $mrf): string
    {
        $base = $mrf->po_number ?: ($mrf->formatted_id ?: $mrf->mrf_id);

        return 'GRN-' . Str::upper(Str::slug((string) $base, '-')) . '-' . now()->format('Ymd');
    }

    /**
     * @param  Collection<int, \App\Models\MRFItem>  $items
     * @return list<array<string, mixed>>
     */
    private function mapMrfItems(Collection $items): array
    {
        return $items->map(fn ($item) => [
            'name' => (string) ($item->item_name ?? 'Item'),
            'description' => trim((string) ($item->description ?? $item->specifications ?? '')),
            'quantity' => $this->fmtQty((float) ($item->quantity ?? 1)),
            'unit' => (string) ($item->unit ?? 'unit'),
        ])->values()->all();
    }

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }
}
