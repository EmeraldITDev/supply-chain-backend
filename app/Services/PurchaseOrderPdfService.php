<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\View;

class PurchaseOrderPdfService
{
    private const EMERALD_GREEN = '#0d5c3f';

    /**
     * Embed company logo as HTML for Dompdf (data URI).
     */
    public function logoHtml(): string
    {
        $logoPaths = [
            public_path('images/logo.png'),
            public_path('images/logo.jpg'),
            public_path('images/company-logo.png'),
            public_path('images/company-logo.jpg'),
            public_path('images/emerald-logo.png'),
            public_path('images/emerald-logo.jpg'),
        ];

        foreach ($logoPaths as $logoPath) {
            if (file_exists($logoPath)) {
                $imageData = file_get_contents($logoPath);
                $imageInfo = getimagesize($logoPath);
                $mimeType = $imageInfo['mime'] ?? 'image/png';
                $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

                return '<div class="logo-wrap"><img src="' . $dataUri . '" alt="Company Logo" class="logo-img" /></div>';
            }
        }

        return '<div class="logo-placeholder">LOGO</div>';
    }

    /**
     * PO PDF from MRFController dataset (Eloquent item models).
     *
     * @param  array<string, mixed>  $data
     */
    public function htmlFromMrf(array $data): string
    {
        $requester = $data['requester'] ?? null;
        $company = $data['company'];
        $vendor = $data['vendor'];
        $items = $data['items'];
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $subtotal = (float) $data['subtotal'];
        $tax = (float) $data['tax'];
        $total = (float) $data['total'];
        $currency = $data['currency'] ?? 'NGN';

        $lineItems = [];
        $row = 1;
        foreach ($items as $item) {
            $itemName = $item->item_name ?? $item->name ?? 'Item';
            $description = $item->description ?? $item->specifications ?? '';
            $quantity = (float) ($item->quantity ?? 1);
            $unit = $item->unit ?? '—';
            $unitPrice = (float) ($item->unit_price ?? (($item->total_price ?? 0) / max($quantity, 1)));
            $lineTotal = $unitPrice * $quantity;

            $lineItems[] = [
                'index' => $row++,
                'title' => $itemName,
                'description' => $description,
                'uom' => $unit,
                'qty' => $this->fmtQty($quantity),
                'unit_price' => $this->fmtMoney($unitPrice),
                'total' => $this->fmtMoney($lineTotal),
            ];
        }

        $commentsParts = [];
        if (!empty($data['payment_terms'])) {
            $commentsParts[] = 'Payment terms: ' . $data['payment_terms'];
        }
        if (!empty($data['invoice_submission_email'])) {
            $cc = !empty($data['invoice_submission_cc']) ? ' cc: ' . $data['invoice_submission_cc'] : '';
            $commentsParts[] = 'Invoice submission: ' . $data['invoice_submission_email'] . $cc;
        }
        if (!empty($data['special_terms'])) {
            $commentsParts[] = $data['special_terms'];
        } else {
            $commentsParts[] = "Standard terms:\n"
                . "- All items must be high quality, brand new and according to the specification. Anything less may trigger rejection.\n"
                . "- All packages must be clearly marked.\n"
                . "- Package contents must be marked with item number, material number, description, manufacturer's part number and quantity.\n"
                . "- Small items with the same part numbers must be tagged and packed together; tags visible on the outside of the bag or box.\n"
                . "- Items must be packed in a sturdy case to withstand handling.\n"
                . "- Delivery must be accompanied by Airway Bill, Invoice and Delivery Note, duly signed by an Emerald representative at site.";
        }
        $comments = implode("\n\n", array_filter($commentsParts));

        $poDateRaw = (string) $data['po_date'];
        try {
            $poDate = preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $poDateRaw)
                ? Carbon::createFromFormat('d/m/Y', $poDateRaw)
                : Carbon::parse($poDateRaw);
        } catch (\Throwable) {
            $poDate = Carbon::now();
        }

        return View::make('pdf.purchase-order', [
            'emerald_green' => self::EMERALD_GREEN,
            'logo_html' => $this->logoHtml(),
            'po_number' => $data['po_number'],
            'po_date_formatted' => $poDate->format('F d, Y'),
            'document_title' => 'PURCHASE ORDER',
            'table_section_title' => 'PURCHASE ORDER LINES',
            'order_info_title' => 'Order Information',
            'supplier_info_title' => 'Supplier Information',
            'order_rows' => array_values(array_filter([
                ['label' => 'PO Number:', 'value' => $data['po_number']],
                ['label' => 'Date:', 'value' => $poDate->format('F d, Y')],
                ['label' => 'Bill / Ship To:', 'value' => $data['ship_to']],
                ['label' => 'Buyer:', 'value' => $company['name']],
                ['label' => 'Buyer Address:', 'value' => $company['address']],
                ['label' => 'Website:', 'value' => (string) ($company['website'] ?? '')],
                ['label' => 'Email:', 'value' => (string) ($company['email'] ?? '')],
                ['label' => 'Phone:', 'value' => (string) ($company['phone'] ?? '')],
                ['label' => 'Tax ID:', 'value' => (string) ($company['tax_id'] ?? '')],
            ], fn ($row) => ($row['value'] ?? '') !== '' || in_array($row['label'], [
                'PO Number:', 'Date:', 'Bill / Ship To:', 'Buyer:', 'Buyer Address:',
            ], true))),
            'supplier_rows' => $this->supplierInfoRows($vendor),
            'line_items' => $lineItems,
            'subtotal' => $this->fmtMoney($subtotal),
            'tax' => $this->fmtMoney($tax),
            'tax_rate' => $taxRate,
            'total' => $this->fmtMoney($total),
            'currency' => $currency,
            'show_tax_breakdown' => $tax > 0 || $taxRate > 0,
            'comments' => $comments,
            'signature_blocks' => $this->signatureBlocksForMrf($requester, $vendor),
        ])->render();
    }

    /**
     * PO PDF from MRFWorkflowController dataset (arrays).
     *
     * @param  array<string, mixed>  $data
     */
    public function htmlFromWorkflow(array $data, string $poNumber, object $user): string
    {
        $mrf = $data['mrf'];
        $quotation = $data['quotation'];
        $vendor = $data['vendor'];
        $company = $data['company'];
        $items = $data['items'];

        $date = now()->setTimezone('Africa/Lagos');

        $lineItems = [];
        $row = 1;
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineTotal = (float) ($item['total_price'] ?? ($unitPrice * $qty));
            $subtotal += $lineTotal;

            $name = $item['item_name'] ?? $item['name'] ?? 'Item';
            $desc = $item['description'] ?? $item['specifications'] ?? '';

            $lineItems[] = [
                'index' => $row++,
                'title' => $name,
                'description' => $desc,
                'uom' => $item['unit'] ?? '—',
                'qty' => $this->fmtQty($qty),
                'unit_price' => $this->fmtMoney($unitPrice),
                'total' => $this->fmtMoney($lineTotal),
            ];
        }

        $tax = 0.0;
        $total = $subtotal + $tax;
        $currency = $quotation['currency'] ?? 'NGN';

        $shipTo = env('COMPANY_ADDRESS', $company['address'] ?? '');

        $commentsParts = [];
        if (!empty($quotation['payment_terms'])) {
            $commentsParts[] = 'Payment terms: ' . $quotation['payment_terms'];
        }
        if (!empty($quotation['delivery_days'])) {
            $commentsParts[] = 'Delivery: ' . $quotation['delivery_days'] . ' days';
        } elseif (!empty($quotation['delivery_date'])) {
            $d = $quotation['delivery_date'];
            if ($d instanceof \DateTimeInterface) {
                $commentsParts[] = 'Delivery date: ' . Carbon::instance($d)->format('F d, Y');
            } else {
                $commentsParts[] = 'Delivery date: ' . (string) $d;
            }
        }
        if (!empty($quotation['warranty_period'])) {
            $commentsParts[] = 'Warranty: ' . $quotation['warranty_period'];
        }
        $commentsParts[] = 'MRF: ' . ($mrf['id'] ?? '') . ' — ' . ($mrf['title'] ?? '');
        $comments = implode("\n\n", array_filter($commentsParts));

        return View::make('pdf.purchase-order', [
            'emerald_green' => self::EMERALD_GREEN,
            'logo_html' => $this->logoHtml(),
            'po_number' => $poNumber,
            'po_date_formatted' => $date->format('F d, Y'),
            'document_title' => 'PURCHASE ORDER',
            'table_section_title' => 'PURCHASE ORDER LINES',
            'order_info_title' => 'Order Information',
            'supplier_info_title' => 'Supplier Information',
            'order_rows' => [
                ['label' => 'PO Number:', 'value' => $poNumber],
                ['label' => 'Date:', 'value' => $date->format('F d, Y')],
                ['label' => 'Bill / Ship To:', 'value' => $shipTo],
                ['label' => 'MRF Reference:', 'value' => ($mrf['id'] ?? '')],
                ['label' => 'Requested By:', 'value' => $mrf['requester_name'] ?? ''],
                ['label' => 'Department:', 'value' => $mrf['department'] ?? ''],
                ['label' => 'Buyer:', 'value' => $company['name'] ?? ''],
                ['label' => 'Buyer Address:', 'value' => $company['address'] ?? ''],
            ],
            'supplier_rows' => $this->supplierInfoRows($vendor),
            'line_items' => $lineItems,
            'subtotal' => $this->fmtMoney($subtotal),
            'tax' => $this->fmtMoney($tax),
            'tax_rate' => 0.0,
            'total' => $this->fmtMoney($total),
            'currency' => $currency,
            'show_tax_breakdown' => false,
            'comments' => $comments,
            'signature_blocks' => $this->signatureBlocksForWorkflow($user, $vendor),
        ])->render();
    }

    /**
     * @return list<array{title: string, name: string, position: string, phone: string, email: string}>
     */
    private function signatureBlocksForMrf(?object $requester, array $vendor): array
    {
        $reqName = $requester?->name ?? 'N/A';
        $reqRole = $requester?->role ?? ($requester?->department ?? '');
        $reqPhone = (string) ($requester?->phone ?? '');
        $reqEmail = (string) ($requester?->email ?? '');

        $vendorName = $vendor['name'] ?? 'N/A';

        return [
            [
                'title' => 'Vendor (order acknowledged by)',
                'name' => $vendorName,
                'position' => 'Vendor',
                'phone' => (string) ($vendor['phone'] ?? ''),
                'email' => (string) ($vendor['email'] ?? ''),
            ],
            [
                'title' => 'Emerald (Issued by)',
                'name' => $reqName,
                'position' => $reqRole !== '' ? $reqRole : 'Requester',
                'phone' => $reqPhone,
                'email' => $reqEmail,
            ],
            [
                'title' => 'Vendor (witnessed by)',
                'name' => 'N/A',
                'position' => '',
                'phone' => '',
                'email' => '',
            ],
            [
                'title' => 'Emerald (Supervised by)',
                'name' => 'N/A',
                'position' => 'Procurement Manager',
                'phone' => '',
                'email' => '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $vendor
     * @return list<array{title: string, name: string, position: string, phone: string, email: string}>
     */
    private function signatureBlocksForWorkflow(object $user, array $vendor): array
    {
        return [
            [
                'title' => 'Vendor (order acknowledged by)',
                'name' => $vendor['name'] ?? 'N/A',
                'position' => 'Vendor',
                'phone' => $vendor['phone'] ?? '',
                'email' => $vendor['email'] ?? '',
            ],
            [
                'title' => 'Emerald (Issued by)',
                'name' => $user->name ?? 'N/A',
                'position' => $user->role ?? ($user->department ?? ''),
                'phone' => $user->phone ?? '',
                'email' => $user->email ?? '',
            ],
            [
                'title' => 'Vendor (witnessed by)',
                'name' => 'N/A',
                'position' => '',
                'phone' => '',
                'email' => '',
            ],
            [
                'title' => 'Emerald (Supervised by)',
                'name' => 'N/A',
                'position' => 'Procurement Manager',
                'phone' => '',
                'email' => '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $vendor
     * @return list<array{label: string, value: string}>
     */
    private function supplierInfoRows(array $vendor): array
    {
        $rows = [
            ['label' => 'Supplier Name:', 'value' => (string) ($vendor['name'] ?? '')],
            ['label' => 'Supplier Address:', 'value' => (string) ($vendor['address'] ?? '')],
        ];
        foreach (
            [
                'Contact Person:' => 'contact_person',
                'Phone:' => 'phone',
                'Email:' => 'email',
                'Tax ID:' => 'tax_id',
            ] as $label => $key
        ) {
            $val = (string) ($vendor[$key] ?? '');
            if ($val !== '') {
                $rows[] = ['label' => $label, 'value' => $val];
            }
        }

        return $rows;
    }

    private function fmtMoney(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    private function fmtQty(float $qty): string
    {
        if (abs($qty - round($qty, 4)) < 0.00001) {
            return (string) (int) round($qty);
        }

        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
