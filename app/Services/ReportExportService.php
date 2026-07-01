<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public const DEFAULT_EXPORT_MAX_ROWS = 10000;

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            // UTF-8 BOM so Excel opens non-ASCII characters correctly.
            echo "\xEF\xBB\xBF";
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    static fn ($cell) => $cell === null ? '' : (string) $cell,
                    $row,
                ));
            }
            fclose($handle);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function streamSpreadsheet(
        string $filename,
        array $headers,
        array $rows,
        string $extension = 'xlsx',
    ): StreamedResponse {
        $extension = ltrim(strtolower($extension), '.');

        if ($extension === 'xlsx') {
            return $this->streamXlsx($filename, $headers, $rows);
        }

        return $this->streamLegacyXlsXml($filename, $headers, $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function streamXlsx(string $filename, array $headers, array $rows): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Export');

        foreach ($headers as $colIndex => $header) {
            $sheet->setCellValue([$colIndex + 1, 1], $header);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $cell) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 2], $cell === null ? '' : $cell);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
        ]);
    }

    /**
     * Legacy Excel 2003 XML (.xls) for backward compatibility.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function streamLegacyXlsXml(string $filename, array $headers, array $rows): StreamedResponse
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
                    $value = $cell === null ? '' : (string) $cell;
                    $type = is_numeric($value) && $value !== '' ? 'Number' : 'String';
                    echo '<Cell><Data ss:Type="'.$type.'">'.htmlspecialchars($value, ENT_XML1).'</Data></Cell>';
                }
                echo '</Row>';
            }

            echo '</Table></Worksheet></Workbook>';
        }, $filename.'.xls', [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xls"',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
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
        }, $filename.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }

    /**
     * Resolve export row limit from request. `all` or empty = cap at DEFAULT_EXPORT_MAX_ROWS.
     */
    public static function resolveExportLimit(mixed $raw, int $max = self::DEFAULT_EXPORT_MAX_ROWS): int
    {
        if ($raw === null || $raw === '' || $raw === 'all' || $raw === 'All') {
            return $max;
        }

        $limit = (int) $raw;

        if ($limit <= 0) {
            return $max;
        }

        return min($limit, $max);
    }
}
