<?php

namespace App\Services;

use App\Support\PurchaseOrderCurrency;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;

class PurchaseOrderPdfService
{
    public const EMERALD_LAYOUT_VIEW = 'pdf.emerald-purchase-order';

    /**
     * Render workflow PO payload to PDF bytes (Emerald layout).
     *
     * @param  array<string, mixed>  $data
     */
    public function renderWorkflowPdf(array $data, string $poNumber, object $user): string
    {
        $html = $this->htmlFromWorkflow($data, $poNumber, $user);

        return $this->renderHtmlToPdf($html);
    }

    /**
     * Render MRF-backed PO dataset to PDF bytes (Emerald layout).
     *
     * @param  array<string, mixed>  $data
     */
    public function renderMrfPdf(array $data): string
    {
        $html = $this->htmlFromMrf($data);

        return $this->renderHtmlToPdf($html);
    }

    public function renderHtmlToPdf(string $html): string
    {
        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', public_path());
        $options->set('pdfBackend', 'CPDF');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function renderEmeraldLayout(array $viewData): string
    {
        if (! View::exists(self::EMERALD_LAYOUT_VIEW)) {
            throw new \RuntimeException('Emerald PO layout unavailable: view '.self::EMERALD_LAYOUT_VIEW.' is missing.');
        }

        return View::make(self::EMERALD_LAYOUT_VIEW, $viewData)->render();
    }
    /**
     * Embed company logo as HTML for Dompdf (data URI). Prefer Emerald assets in public/images.
     * Dompdf needs PHP GD (or compatible stack) to rasterize PNG/JPEG in PDFs; without GD, use text-only branding.
     */
    public function logoHtml(): string
    {
        if (!extension_loaded('gd')) {
            return '<div class="logo-wrap logo-text-fallback" style="font-weight:bold;font-size:11px;color:#0d5c3f;">Emerald</div>';
        }

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
                $imageInfo = @getimagesize($logoPath);
                $mimeType = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : 'image/png';
                $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

                return '<div class="logo-wrap"><img src="' . $dataUri . '" alt="Logo" class="logo-img" /></div>';
            }
        }

        return '<div class="logo-placeholder">LOGO</div>';
    }

    /**
     * Inline signature image for Dompdf (no remote HTTP). Accepts data URI or local storage path.
     */
    public function signatureHtml(?string $signatureImageUrl): string
    {
        if ($signatureImageUrl === null || trim($signatureImageUrl) === '') {
            return '';
        }

        $value = trim($signatureImageUrl);

        if (str_starts_with($value, 'data:image/')) {
            return '<img src="'.$value.'" alt="Signature" class="signature-img" />';
        }

        $localPath = $this->resolveLocalImagePath($value);
        if ($localPath !== null && is_readable($localPath)) {
            $imageData = file_get_contents($localPath);
            $imageInfo = @getimagesize($localPath);
            $mimeType = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : 'image/png';

            return '<img src="data:'.$mimeType.';base64,'.base64_encode($imageData).'" alt="Signature" class="signature-img" />';
        }

        return '';
    }

    private function resolveLocalImagePath(string $value): ?string
    {
        if (is_file($value)) {
            return $value;
        }

        $publicPath = public_path(ltrim($value, '/'));
        if (is_file($publicPath)) {
            return $publicPath;
        }

        $signaturesDisk = config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));
        try {
            if (\Illuminate\Support\Facades\Storage::disk($signaturesDisk)->exists($value)) {
                return \Illuminate\Support\Facades\Storage::disk($signaturesDisk)->path($value);
            }
        } catch (\Throwable) {
            // Fall through
        }

        return null;
    }

    /**
     * Canonical approver block on generated PO PDFs (matches frontend Emerald layout).
     */
    public const EMERALD_PO_APPROVER_NAME = 'Mrs. Viva Musa';

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
        $paymentMilestones = $data['payment_milestones'] ?? [];
        $categoryLine = $this->resolveCategoryLine(
            (string) ($data['mrf_category'] ?? ''),
            (string) ($data['mrf_department'] ?? ''),
        );

        $poDateRaw = (string) $data['po_date'];
        try {
            $poDate = preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $poDateRaw)
                ? Carbon::createFromFormat('d/m/Y', $poDateRaw)
                : Carbon::parse($poDateRaw);
        } catch (\Throwable) {
            $poDate = Carbon::now();
        }

        $lineItems = [];
        foreach ($items as $item) {
            $itemName = trim((string) ($item->item_name ?? $item->name ?? 'Item'));
            $extraDesc = trim((string) ($item->description ?? $item->specifications ?? ''));
            $description = $itemName;
            if ($extraDesc !== '' && strcasecmp($extraDesc, $itemName) !== 0) {
                $description = $extraDesc;
            }
            $quantity = (float) ($item->quantity ?? 1);
            $unitPrice = (float) ($item->unit_price ?? (($item->total_price ?? 0) / max($quantity, 1)));
            $lineTotal = $unitPrice * $quantity;

            $lineItems[] = [
                'category' => $categoryLine,
                'description' => $description,
                'qty' => $this->fmtQty($quantity),
                'rate' => $this->fmtMoney($unitPrice),
                'tax_label' => $this->fmtTaxLabel($taxRate),
                'amount' => $this->fmtMoney($lineTotal),
            ];
        }

        $approverName = (string) ($data['approved_by_name'] ?? self::EMERALD_PO_APPROVER_NAME);
        $approverDate = (string) ($data['approved_by_date'] ?? '');
        if ($approverName !== '' && $approverDate === '') {
            $approverDate = $poDate->format('F j, Y');
        }

        return $this->renderEmeraldLayout($this->baseViewVars(
            $company,
            $vendor,
            (string) ($data['ship_to'] ?? ''),
            $data['po_number'],
            $poDate,
            $lineItems,
            $subtotal,
            $tax,
            $total,
            $currency,
            $approverName,
            $approverDate,
            $data['signature_image_url'] ?? null,
            invoiceEmail: (string) ($data['invoice_submission_email'] ?? ''),
            invoiceCc: (string) ($data['invoice_submission_cc'] ?? ''),
            poType: (string) ($data['po_type'] ?? 'goods'),
            termsMode: (string) ($data['po_terms_mode'] ?? 'standard'),
            customTerms: (string) ($data['custom_terms'] ?? ''),
            specialTerms: (string) ($data['special_terms'] ?? ''),
            paymentTermsRaw: (string) ($data['payment_terms'] ?? ''),
            contractType: (string) ($data['contract_type'] ?? ''),
            paymentMilestones: $paymentMilestones,
        ));
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
        $taxRate = (float) ($data['tax_rate'] ?? $mrf['tax_rate'] ?? 0);

        $date = now()->setTimezone('Africa/Lagos');
        $categoryLine = $this->resolveCategoryLine(
            (string) ($mrf['category'] ?? ''),
            (string) ($mrf['department'] ?? ''),
        );

        $lineItems = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineTotal = (float) ($item['total_price'] ?? ($unitPrice * $qty));
            $subtotal += $lineTotal;

            $name = trim((string) ($item['item_name'] ?? $item['name'] ?? 'Item'));
            $desc = trim((string) ($item['description'] ?? $item['specifications'] ?? ''));
            $description = $name;
            if ($desc !== '' && strcasecmp($desc, $name) !== 0) {
                $description = $desc;
            }

            $lineItems[] = [
                'category' => $categoryLine,
                'description' => $description,
                'qty' => $this->fmtQty($qty),
                'rate' => $this->fmtMoney($unitPrice),
                'tax_label' => $this->fmtTaxLabel($taxRate),
                'amount' => $this->fmtMoney($lineTotal),
            ];
        }

        $tax = (float) ($data['tax_amount'] ?? 0);
        if ($tax <= 0 && $taxRate > 0) {
            $tax = ($subtotal * $taxRate) / 100;
        }
        $total = $subtotal + $tax;
        $paymentMilestones = $data['payment_milestones'] ?? [];
        $currency = PurchaseOrderCurrency::normalize($mrf['currency'] ?? $quotation['currency'] ?? 'NGN');

        $shipTo = (string) ($data['ship_to'] ?? $mrf['ship_to_address'] ?? env('COMPANY_ADDRESS', $company['address'] ?? ''));

        return $this->renderEmeraldLayout($this->baseViewVars(
            $company,
            $vendor,
            $shipTo,
            $poNumber,
            $date,
            $lineItems,
            $subtotal,
            $tax,
            $total,
            $currency,
            self::EMERALD_PO_APPROVER_NAME,
            $date->format('F j, Y'),
            $data['signature_image_url'] ?? null,
            invoiceEmail: (string) ($data['invoice_submission_email'] ?? $mrf['invoice_submission_email'] ?? ''),
            invoiceCc: (string) ($data['invoice_submission_cc'] ?? $mrf['invoice_submission_cc'] ?? ''),
            poType: (string) ($mrf['po_type'] ?? 'goods'),
            termsMode: (string) ($mrf['po_terms_mode'] ?? 'standard'),
            customTerms: (string) ($mrf['custom_terms'] ?? ''),
            specialTerms: (string) ($mrf['po_special_terms'] ?? $data['special_terms'] ?? ''),
            paymentTermsRaw: (string) ($mrf['po_payment_terms'] ?? $quotation['payment_terms'] ?? ''),
            contractType: (string) ($mrf['contract_type'] ?? ''),
            paymentMilestones: $paymentMilestones,
        ));
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
        float $subtotal,
        float $tax,
        float $total,
        string $currency,
        string $approvedByName,
        string $approvedByDate,
        ?string $signatureImageUrl = null,
        string $invoiceEmail = '',
        string $invoiceCc = '',
        string $poType = 'goods',
        string $termsMode = 'standard',
        string $customTerms = '',
        string $specialTerms = '',
        string $paymentTermsRaw = '',
        string $contractType = '',
        array $paymentMilestones = [],
    ): array {
        $company = $this->normalizeCompany($company);

        return [
            'logo_html' => $this->logoHtml(),
            'company' => $company,
            'supplier_name' => (string) ($vendor['name'] ?? ''),
            'ship_to_display' => $shipTo !== '' ? $shipTo : (string) ($company['name'] ?? ''),
            'po_number' => $poNumber,
            'po_date_short' => $poDate->format('d/m/Y'),
            'document_title' => 'Purchase Order',
            'line_items' => $lineItems,
            'invoice_submission_line' => $this->buildInvoiceSubmissionLine($invoiceEmail, $invoiceCc),
            'standard_terms_lines' => $this->resolveStandardTermsLines($poType, $termsMode, $customTerms, $specialTerms),
            'payment_terms_display' => $this->resolvePaymentTermsDisplay($paymentTermsRaw, $customTerms),
            'contract_type_display' => $this->formatContractType($contractType),
            'payment_milestones' => $paymentMilestones,
            'has_payment_milestones' => $paymentMilestones !== [],
            'subtotal' => $this->fmtMoney($subtotal),
            'tax' => $this->fmtMoney($tax),
            'total' => $this->fmtMoney($total),
            'currency' => $currency,
            'approved_by_name' => $approvedByName !== '' ? $approvedByName : self::EMERALD_PO_APPROVER_NAME,
            'approved_by_date' => $approvedByDate,
            'signature_html' => $this->signatureHtml($signatureImageUrl),
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
            'address' => (string) ($company['address'] ?? env('COMPANY_ADDRESS', 'Plot A10, Calabar Free Trade Zone, Calabar, Cross River 540001 NG')),
            'email' => (string) ($company['email'] ?? env('COMPANY_EMAIL', 'info@emeraldcfze.com')),
            'website' => (string) ($company['website'] ?? env('COMPANY_WEBSITE', 'https://emeraldcfze.com/')),
        ];
    }

    private function resolveCategoryLine(string $category, string $department): string
    {
        $raw = trim($category !== '' ? $category : $department);

        if ($raw === '') {
            return 'Procurement';
        }

        return str_replace('-', ' ', $raw);
    }

    private function fmtTaxLabel(float $taxRate): string
    {
        if ($taxRate <= 0) {
            return '0%';
        }

        return rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.').'%';
    }

    private function buildInvoiceSubmissionLine(string $email, string $cc): string
    {
        $to = trim($email) !== '' ? trim($email) : 'accountpayables@emeraldcfze.com';
        $ccLine = trim($cc);
        if ($ccLine === '') {
            $ccLine = 'lateef.olanrewaju@emeraldcfze.com, procurement@emeraldcfze.com';
        }

        return 'Invoice submission: '.$to.' cc: '.$ccLine;
    }

    /**
     * @return list<string>
     */
    private function resolveStandardTermsLines(
        string $poType,
        string $termsMode,
        string $customTerms,
        string $specialTerms,
    ): array {
        $mode = strtolower(trim($termsMode));
        if (! in_array($mode, ['standard', 'custom', 'both'], true)) {
            $mode = 'standard';
        }

        $templateLines = $this->templateTermLines($poType);
        $customLines = $this->splitTermLines($customTerms);
        $specialLines = $this->splitTermLines($specialTerms);

        if ($mode === 'custom') {
            return $customLines !== [] ? $customLines : ($specialLines !== [] ? $specialLines : $templateLines);
        }

        if ($mode === 'both') {
            return array_values(array_unique(array_merge($templateLines, $customLines, $specialLines)));
        }

        return $templateLines;
    }

    /**
     * @return list<string>
     */
    private function templateTermLines(string $poType): array
    {
        $key = in_array(strtolower($poType), ['goods', 'services', 'logistics'], true)
            ? strtolower($poType)
            : 'goods';
        $body = (string) (config("po_terms_templates.{$key}") ?? config('po_terms_templates.goods', ''));

        return $this->splitTermLines($body);
    }

    /**
     * @return list<string>
     */
    private function splitTermLines(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (string $line): string {
                $line = trim($line);
                $line = preg_replace('/^Standard terms:\s*/i', '', $line) ?? $line;

                return ltrim($line, "-•* \t");
            },
            preg_split('/\r\n|\r|\n/', $text) ?: [],
        )));
    }

    private function resolvePaymentTermsDisplay(string $paymentTermsRaw, string $customTerms): string
    {
        $raw = trim($paymentTermsRaw);
        if ($raw !== '') {
            return $raw;
        }

        if (preg_match('/Payment\s*Terms:\s*([^\n]+)/i', $customTerms, $m)) {
            $parsed = trim($m[1] ?? '');
            if ($parsed !== '') {
                return $parsed;
            }
        }

        return 'Net 30 days';
    }

    private function formatContractType(?string $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '—';
        }

        $key = strtolower($value);
        $labels = [
            'emerald' => 'Emerald Contract',
            'oando' => 'Oando Contract',
            'dangote' => 'Dangote Contract',
            'heritage' => 'Heritage Contract',
        ];

        return $labels[$key] ?? $value;
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
