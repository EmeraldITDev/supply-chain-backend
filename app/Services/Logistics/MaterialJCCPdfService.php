<?php

namespace App\Services\Logistics;

use App\Models\Logistics\MaterialJCC;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class MaterialJCCPdfService
{
    /**
     * Generate PDF for Material Job Completion Certificate
     *
     * Layout Structure:
     * - Header: Company letterhead, title "JOB COMPLETION CERTIFICATE - MATERIALS"
     * - Material Info: Material name, quantity, category, delivery location
     * - Vendor Details: Vendor name, phone, address
     * - PO Reference: PO number (if applicable)
     * - Certification Paragraph: Custom certification text
     * - Line Items Table: SN, Material Name, Quantity, Condition, Remarks
     * - Signatory Block: Name, title, digital signature, date
     * - Footer: Reference number, generation date
     */
    public function generatePdf(MaterialJCC $jcc)
    {
        $jcc->load(['materialMovement', 'vendor', 'issuedBy', 'approvedBy', 'lineItems']);

        $data = [
            'jcc' => $jcc,
            'material' => $jcc->materialMovement,
            'vendor' => $jcc->vendor,
            'lineItems' => $jcc->lineItems()->orderBy('serial_number')->get(),
            'generatedAt' => now()->format('d/m/Y H:i'),
            'currentYear' => now()->year,
            'companyName' => config('app.name', 'Supply Chain Company'),
            'companyAddress' => config('app.address', ''),
            'companyPhone' => config('app.phone', ''),
            'companyEmail' => config('app.email', ''),
        ];

        $pdf = Pdf::loadView('logistics.material-jcc-pdf', $data)
            ->setPaper('a4')
            ->setOption('margin-top', 15)
            ->setOption('margin-bottom', 15)
            ->setOption('margin-left', 15)
            ->setOption('margin-right', 15);

        return $pdf->output();
    }

    /**
     * Download Material JCC as PDF
     */
    public function downloadPdf(MaterialJCC $jcc): Response
    {
        $pdf = $this->generatePdf($jcc);
        $fileName = "Material-JCC-{$jcc->reference_number}.pdf";

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
    }

    /**
     * Get PDF as base64 encoded string (for embedding or API response)
     */
    public function getPdfAsBase64(MaterialJCC $jcc): string
    {
        $pdf = $this->generatePdf($jcc);
        return base64_encode($pdf);
    }

    /**
     * Get PDF layout structure metadata
     *
     * This defines the exact layout structure for the physical document
     */
    public function getLayoutStructure(): array
    {
        return [
            'page_size' => 'A4',
            'orientation' => 'portrait',
            'margins' => [
                'top' => 15,
                'bottom' => 15,
                'left' => 15,
                'right' => 15,
            ],
            'sections' => [
                'header' => [
                    'height' => 80,
                    'content' => [
                        'company_logo' => 'logo.png (25mm x 25mm)',
                        'company_name' => 'Company Name',
                        'company_contact' => 'Address, Phone, Email',
                        'title' => 'JOB COMPLETION CERTIFICATE - MATERIALS',
                        'reference_number' => 'Reference: JCC/MAT/YYYYMM-XX',
                    ],
                    'position' => 'top',
                ],
                'material_info' => [
                    'height' => 50,
                    'fields' => [
                        'material_name' => 'Material Name',
                        'quantity' => 'Quantity',
                        'category' => 'Category',
                        'destination' => 'Delivery Location',
                    ],
                    'layout' => '2 columns',
                ],
                'vendor_info' => [
                    'height' => 40,
                    'fields' => [
                        'vendor_name' => 'Vendor/Transporter Name',
                        'vendor_phone' => 'Contact Phone',
                        'po_number' => 'PO Number (if applicable)',
                    ],
                ],
                'certification_section' => [
                    'height' => 50,
                    'label' => 'Certification',
                    'field' => 'certification_text',
                ],
                'line_items_table' => [
                    'height' => 'auto',
                    'columns' => [
                        'serial_number' => 'SN',
                        'material_name' => 'Material Name',
                        'quantity' => 'Quantity',
                        'condition' => 'Condition',
                        'remarks' => 'Remarks',
                    ],
                    'min_rows' => 5,
                ],
                'condition_on_arrival' => [
                    'height' => 30,
                    'fields' => [
                        'condition_on_arrival' => 'Condition on Arrival: ☐ Good ☐ Damaged ☐ Partial',
                    ],
                ],
                'signatory_section' => [
                    'height' => 80,
                    'layout' => '2 columns',
                    'columns' => [
                        'issued_by' => [
                            'label' => 'Issued By',
                            'fields' => ['name', 'title', 'signature', 'date'],
                        ],
                        'approved_by' => [
                            'label' => 'Approved By',
                            'fields' => ['name', 'title', 'signature', 'date'],
                        ],
                    ],
                ],
                'footer' => [
                    'height' => 20,
                    'content' => [
                        'reference_number' => 'Reference: {reference_number}',
                        'printed_date' => 'Generated: {date}',
                    ],
                    'position' => 'bottom',
                    'alignment' => 'center',
                ],
            ],
            'fonts' => [
                'primary' => 'Arial, sans-serif',
                'heading' => 'Arial Bold, sans-serif',
                'size' => '11pt',
            ],
        ];
    }
}
