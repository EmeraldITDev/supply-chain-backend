<?php

namespace App\Support;

use App\Services\ReportExportService;
use Illuminate\Http\Request;

class ExportLimit
{
    public static function fromRequest(Request $request, int $max = ReportExportService::DEFAULT_EXPORT_MAX_ROWS): int
    {
        $raw = $request->input('limit', $request->query('limit'));

        return ReportExportService::resolveExportLimit($raw, $max);
    }
}
