<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     */
    public function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Excel 2003 XML (opens in Excel without extra PHP packages).
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     */
    public function streamSpreadsheet(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ';
            echo 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
            echo '<Worksheet ss:Name="Report"><Table>';

            echo '<Row>';
            foreach ($headers as $header) {
                echo '<Cell><Data ss:Type="String">'.htmlspecialchars((string) $header, ENT_XML1).'</Data></Cell>';
            }
            echo '</Row>';

            foreach ($rows as $row) {
                echo '<Row>';
                foreach ($row as $cell) {
                    $type = is_numeric($cell) ? 'Number' : 'String';
                    echo '<Cell><Data ss:Type="'.$type.'">'.htmlspecialchars((string) $cell, ENT_XML1).'</Data></Cell>';
                }
                echo '</Row>';
            }

            echo '</Table></Worksheet></Workbook>';
        }, $filename.'.xls', [
            'Content-Type' => 'application/vnd.ms-excel',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     */
    public function streamPdf(string $filename, string $title, array $headers, array $rows): StreamedResponse
    {
        $html = view('reports.table-export', [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->toDateTimeString(),
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $binary = $dompdf->output();

        return response()->streamDownload(static function () use ($binary): void {
            echo $binary;
        }, $filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
