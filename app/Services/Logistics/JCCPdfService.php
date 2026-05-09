<?php

namespace App\Services\Logistics;

use App\Models\Logistics\JobCompletionCertificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class JCCPdfService
{
    /**
     * Generate PDF for Job Completion Certificate
     * 
     * Layout Structure:
     * - Header: Company logo, title "JOB COMPLETION CERTIFICATE"
     * - Trip Info: Trip code, date, vendor details
     * - Line Items Table: Vehicle/service details with condition
     * - Delivery Confirmation: Yes/No with remarks
     * - Condition of Goods: Text field
     * - Signatory Section: Issued by, Approved by with date/signature fields
     * - Footer: Reference number, document date
     */
    public function generatePdf(JobCompletionCertificate $jcc): string
    {
        $jcc->load(['trip.vendor', 'trip.creator', 'issuedBy', 'approvedBy', 'lineItems']);

        $data = [
            'jcc' => $jcc,
            'trip' => $jcc->trip,
            'vendor' => $jcc->trip->vendor,
            'lineItems' => $jcc->lineItems()->orderBy('line_number')->get(),
            'generatedAt' => now()->format('Y-m-d H:i'),
            'currentYear' => now()->year,
        ];

        $pdf = Pdf::loadView('logistics.jcc-pdf', $data)
            ->setPaper('a4')
            ->setOption('margin-top', 15)
            ->setOption('margin-bottom', 15)
            ->setOption('margin-left', 15)
            ->setOption('margin-right', 15);

        return $pdf->output();
    }

    /**
     * Download JCC as PDF
     */
    public function downloadPdf(JobCompletionCertificate $jcc): \Illuminate\Http\Response
    {
        $pdf = $this->generatePdf($jcc);
        $fileName = "JCC-{$jcc->reference_number}-{$jcc->trip->trip_code}.pdf";

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
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
                    'height' => 60,
                    'content' => [
                        'company_logo' => 'logo.png (25mm x 25mm)',
                        'title' => 'JOB COMPLETION CERTIFICATE',
                        'reference_number' => 'JCC/SERVIZO/YYYYMMDD-XX',
                    ],
                    'position' => 'top',
                ],
                'trip_info' => [
                    'height' => 40,
                    'fields' => [
                        'trip_code' => 'Trip Code',
                        'trip_title' => 'Trip Title',
                        'date' => 'Certificate Date (YYYY-MM-DD)',
                        'vendor_name' => 'Vendor Name',
                    ],
                    'layout' => '2 columns',
                ],
                'line_items_table' => [
                    'height' => 'auto',
                    'columns' => [
                        'line_number' => 'No.',
                        'description' => 'Description',
                        'item_type' => 'Type',
                        'reference_number' => 'Reference',
                        'condition' => 'Condition',
                        'remarks' => 'Remarks',
                    ],
                    'min_rows' => 5,
                    'max_rows' => 20,
                ],
                'delivery_section' => [
                    'height' => 30,
                    'fields' => [
                        'delivery_confirmed' => 'Delivery Confirmed: □ Yes □ No',
                        'remarks' => 'Remarks',
                    ],
                ],
                'condition_section' => [
                    'height' => 40,
                    'fields' => [
                        'condition_of_goods' => 'Condition of Goods / Materials',
                    ],
                ],
                'signatory_section' => [
                    'height' => 60,
                    'layout' => '3 columns',
                    'columns' => [
                        'issued_by' => [
                            'label' => 'Issued By',
                            'fields' => ['name', 'signature', 'date'],
                        ],
                        'approved_by' => [
                            'label' => 'Approved By',
                            'fields' => ['name', 'signature', 'date'],
                        ],
                        'witness' => [
                            'label' => 'Witness (Optional)',
                            'fields' => ['name', 'signature', 'date'],
                        ],
                    ],
                ],
                'footer' => [
                    'height' => 20,
                    'content' => [
                        'reference_number' => 'Reference: {reference_number}',
                        'printed_date' => 'Printed: {date} {time}',
                        'page_numbers' => 'Page {page} of {pages}',
                    ],
                    'position' => 'bottom',
                    'alignment' => 'center',
                ],
            ],
            'fonts' => [
                'title' => 'Arial, 16pt, bold',
                'section_header' => 'Arial, 12pt, bold',
                'body' => 'Arial, 10pt',
                'footer' => 'Arial, 8pt, italic',
            ],
            'colors' => [
                'header_bg' => '#f5f5f5',
                'section_border' => '#cccccc',
                'text' => '#000000',
                'accent' => '#2c3e50',
            ],
        ];
    }
}
