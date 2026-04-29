<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\View;

class PurchaseOrderPdfService
{
    /**
     * Embed company logo as HTML for Dompdf (data URI). Prefer Emerald assets in public/images.
     */
    public function logoHtml(): string
    {
        $logoPaths = [
            public_path('images/emerald-logo.png'),
            public_path('images/emerald-logo.jpg'),
            public_path('images/logo.png'),
            public_path('images/logo.jpg'),
            public_path('images/company-logo.png'),
            public_path('images/company-logo.jpg'),
        ];

        foreach ($logoPaths as $logoPath) {
            if (file_exists($logoPath)) {
                $imageData = file_get_contents($logoPath);
                $imageInfo = getimagesize($logoPath);
                $mimeType = $imageInfo['mime'] ?? 'image/png';
                $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

                return '<div class="logo-wrap"><img src="' . $dataUri . '" alt="Logo" class="logo-img" /></div>';
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
        $company = $data['company'];
        $vendor = $data['vendor'];
        $items = $data['items'];
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $subtotal = (float) $data['subtotal'];
        $tax = (float) $data['tax'];
        $total = (float) $data['total'];
        $currency = $data['currency'] ?? 'NGN';
        $mrfDepartment = isset($data['mrf_department']) ? trim((string) $data['mrf_department']) : '';

        $poDateRaw = (string) $data['po_date'];
        try {
            $poDate = preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $poDateRaw)
                ? Carbon::createFromFormat('d/m/Y', $poDateRaw)
                : Carbon::parse($poDateRaw);
        } catch (\Throwable) {
            $poDate = Carbon::now();
        }

        $lineItems = [];
        $rowIndex = 0;
        foreach ($items as $item) {
            $itemName = $item->item_name ?? $item->name ?? 'Item';
            $description = (string) ($item->description ?? $item->specifications ?? '');
            $quantity = (float) ($item->quantity ?? 1);
            $unitPrice = (float) ($item->unit_price ?? (($item->total_price ?? 0) / max($quantity, 1)));
            $lineTotal = $unitPrice * $quantity;

            $deptPrefix = $rowIndex === 0 ? ($mrfDepartment !== '' ? $mrfDepartment : null) : null;

            $lineItems[] = [
                'description_segments' => $this->descriptionSegments($deptPrefix, $itemName, $description),
                'qty' => $this->fmtQty($quantity),
                'rate' => $this->fmtMoney($unitPrice),
                'tax_label' => $taxRate > 0 ? rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%' : '—',
                'amount' => $this->fmtMoney($lineTotal),
            ];
            $rowIndex++;
        }

        $paymentTerms = (string) ($data['payment_terms'] ?? '');
        $additionalNotes = $this->buildAdditionalNotesMrf($data);

        $approverName = (string) ($data['approved_by_name'] ?? env('PO_APPROVER_NAME', ''));
        $approverDate = (string) ($data['approved_by_date'] ?? '');
        if ($approverName !== '' && $approverDate === '') {
            $approverDate = $poDate->format('F j, Y');
        }

        return View::make('pdf.purchase-order', $this->baseViewVars(
            $company,
            $vendor,
            $data['ship_to'],
            $data['po_number'],
            $poDate,
            $lineItems,
            $paymentTerms,
            $additionalNotes,
            $subtotal,
            $tax,
            $total,
            $currency,
            $tax > 0 || $taxRate > 0,
            $approverName,
            $approverDate,
        ))->render();
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
        $company = $this->normalizeCompany($data['company']);
        $items = $data['items'];

        $date = now()->setTimezone('Africa/Lagos');
        $mrfDepartment = trim((string) ($mrf['department'] ?? ''));

        $lineItems = [];
        $rowIndex = 0;
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineTotal = (float) ($item['total_price'] ?? ($unitPrice * $qty));
            $subtotal += $lineTotal;

            $name = (string) ($item['item_name'] ?? $item['name'] ?? 'Item');
            $desc = (string) ($item['description'] ?? $item['specifications'] ?? '');
            $deptPrefix = $rowIndex === 0 ? ($mrfDepartment !== '' ? $mrfDepartment : null) : null;

            $lineItems[] = [
                'description_segments' => $this->descriptionSegments($deptPrefix, $name, $desc),
                'qty' => $this->fmtQty($qty),
                'rate' => $this->fmtMoney($unitPrice),
                'tax_label' => '—',
                'amount' => $this->fmtMoney($lineTotal),
            ];
            $rowIndex++;
        }

        $tax = 0.0;
        $total = $subtotal + $tax;
        $currency = $quotation['currency'] ?? 'NGN';

        $shipTo = env('COMPANY_ADDRESS', $company['address'] ?? '');
        $paymentTerms = (string) ($quotation['payment_terms'] ?? '');

        $additionalParts = [];
        if (!empty($quotation['delivery_days'])) {
            $additionalParts[] = 'Delivery: ' . $quotation['delivery_days'] . ' days';
        } elseif (!empty($quotation['delivery_date'])) {
            $d = $quotation['delivery_date'];
            try {
                $additionalParts[] = 'Delivery date: ' . Carbon::parse($d)->format('F d, Y');
            } catch (\Throwable) {
                $additionalParts[] = 'Delivery date: ' . (string) $d;
            }
        }
        if (!empty($quotation['warranty_period'])) {
            $additionalParts[] = 'Warranty: ' . $quotation['warranty_period'];
        }
        if (!empty($quotation['validity_days'])) {
            $additionalParts[] = 'Validity: ' . $quotation['validity_days'] . ' days';
        }
        $additionalParts[] = 'MRF: ' . ($mrf['id'] ?? '') . ' — ' . ($mrf['title'] ?? '');
        $additionalParts[] = 'Payment shall be made according to the payment terms above. Goods must be delivered as per specifications with proper documentation.';

        return View::make('pdf.purchase-order', $this->baseViewVars(
            $company,
            $vendor,
            $shipTo,
            $poNumber,
            $date,
            $lineItems,
            $paymentTerms,
            implode("\n\n", array_filter($additionalParts)),
            $subtotal,
            $tax,
            $total,
            $currency,
            false,
            (string) ($user->name ?? ''),
            $date->format('F j, Y'),
        ))->render();
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $vendor
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, mixed>
     */
    private function baseViewVars(
        array $company,
        array $vendor,
        string $shipTo,
        string $poNumber,
        Carbon $poDate,
        array $lineItems,
        string $paymentTerms,
        string $additionalNotes,
        float $subtotal,
        float $tax,
        float $total,
        string $currency,
        bool $showTaxBreakdown,
        string $approvedByName,
        string $approvedByDate
    ): array {
        $company = $this->normalizeCompany($company);

        return [
            'logo_html' => $this->logoHtml(),
            'company' => $company,
            'supplier_name' => (string) ($vendor['name'] ?? ''),
            'supplier_address' => (string) ($vendor['address'] ?? ''),
            'buyer_name' => (string) ($company['name'] ?? ''),
            'ship_to_address' => $shipTo,
            'po_number' => $poNumber,
            'po_date_short' => $poDate->format('d/m/Y'),
            'document_title' => 'Purchase Order',
            'line_items' => $lineItems,
            'payment_terms' => $paymentTerms,
            'additional_notes' => trim($additionalNotes),
            'subtotal' => $this->fmtMoney($subtotal),
            'tax' => $this->fmtMoney($tax),
            'total' => $this->fmtMoney($total),
            'currency' => $currency,
            'show_tax_breakdown' => $showTaxBreakdown,
            'approved_by_name' => $approvedByName,
            'approved_by_date' => $approvedByDate,
        ];
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array<string, string>
     */
    private function normalizeCompany(array $company): array
    {
        return [
            'name' => (string) ($company['name'] ?? env('COMPANY_NAME', 'Emerald Industrial Co. FZE')),
            'address' => (string) ($company['address'] ?? env('COMPANY_ADDRESS', '')),
            'email' => (string) ($company['email'] ?? env('COMPANY_EMAIL', '')),
            'website' => (string) ($company['website'] ?? env('COMPANY_WEBSITE', 'https://emeraldcfze.com/')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildAdditionalNotesMrf(array $data): string
    {
        $parts = [];
        if (!empty($data['invoice_submission_email'])) {
            $cc = !empty($data['invoice_submission_cc']) ? ' cc: ' . $data['invoice_submission_cc'] : '';
            $parts[] = 'Invoice submission: ' . $data['invoice_submission_email'] . $cc;
        }
        if (!empty($data['special_terms'])) {
            $parts[] = $data['special_terms'];
        } else {
            $parts[] = "Standard terms:\n"
                . "- All items must be high quality, brand new and according to the specification.\n"
                . "- All packages must be clearly marked.\n"
                . "- Package contents must be marked with item number, material number, description, manufacturer's part number and quantity.\n"
                . "- Small items with the same part numbers must be tagged and packed together.\n"
                . "- Items must be packed in a sturdy case to withstand handling.\n"
                . '- Delivery must be accompanied by Airway Bill, Invoice and Delivery Note, duly signed by an Emerald representative at site.';
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * @return list<array{text: string, class: string}>
     */
    private function descriptionSegments(?string $departmentPrefix, string $itemName, string $description): array
    {
        $segments = [];
        if ($departmentPrefix !== null && trim($departmentPrefix) !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $departmentPrefix) as $ln) {
                $t = trim($ln);
                if ($t !== '') {
                    $segments[] = ['text' => $t, 'class' => 'sub'];
                }
            }
        }

        $segments[] = ['text' => $itemName, 'class' => 'title'];

        $desc = trim($description);
        if ($desc !== '' && strcasecmp($desc, trim($itemName)) !== 0) {
            $segments[] = ['text' => $desc, 'class' => 'sub'];
        }

        return $segments;
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
