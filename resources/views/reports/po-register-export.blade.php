<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 15mm 12mm 17mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #172334; margin: 0; }
        .header { border-bottom: 2px solid #047857; padding-bottom: 9px; margin-bottom: 12px; }
        .brand { color: #047857; font-size: 17px; font-weight: bold; }
        .report-title { font-size: 13px; font-weight: bold; margin-top: 4px; }
        .meta { color: #526174; font-size: 8px; margin-top: 6px; line-height: 1.5; }
        .section-title { font-size: 11px; font-weight: bold; color: #075b48; margin: 13px 0 7px; }
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 5px 5px; table-layout: fixed; }
        .summary-table td { width: 33.33%; padding: 9px 10px; background: #f3f7f7; border: 1px solid #d8e5e2; vertical-align: top; }
        .metric-label { font-size: 8px; color: #4b6070; line-height: 1.4; }
        .metric-value { font-size: 12px; font-weight: bold; color: #132c29; margin-top: 5px; line-height: 1.4; overflow-wrap: break-word; }
        .filters { border: 1px solid #dbe4ed; background: #f8fafc; padding: 8px 10px; margin: 8px 0 13px; }
        .filters-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .filters-table td { width: 50%; vertical-align: top; padding: 3px 9px 3px 0; font-size: 8px; overflow-wrap: break-word; }
        .filter-label { color: #526174; font-weight: bold; }
        .data-table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 12px; }
        .data-table th { background: #e4f1ed; color: #153d33; font-size: 8px; font-weight: bold; text-align: left; padding: 7px 6px; border: 1px solid #cadbd5; }
        .data-table td { font-size: 8px; line-height: 1.45; padding: 6px; border: 1px solid #dce5e9; vertical-align: top; overflow-wrap: break-word; }
        .data-table tbody tr:nth-child(even) { background: #f7fafb; }
        .data-table thead { display: table-header-group; }
        .data-table tr { page-break-inside: avoid; }
        .number { text-align: right !important; white-space: nowrap; font-size: 7.5px !important; }
        .muted { color: #526174; font-size: 8px; line-height: 1.5; }
        .page-break { page-break-before: always; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; border-top: 1px solid #dce5e9; padding-top: 4px; color: #64748b; font-size: 7px; }
        .footer-right { float: right; }
    </style>
</head>
<body>
    <div class="footer"><span>Emerald Industrial Co. CFZE · Internal — Confidential</span><span class="footer-right">Purchase Order Register</span></div>
    <div class="header">
        <div class="brand">Emerald Industrial Co. CFZE</div>
        <div class="report-title">{{ $title }}</div>
        <div class="meta">Generated: {{ $generatedAt }} &nbsp; | &nbsp; Classification: Internal — Confidential</div>
    </div>

    @if (!empty($summary))
        <div class="section-title">Executive Summary</div>
        <table class="summary-table">
            <tbody>
                @foreach (array_chunk($summary, 3, true) as $group)
                    <tr>
                        @foreach ($group as $label => $value)
                            <td><div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $value }}</div></td>
                        @endforeach
                        @for ($i = count($group); $i < 3; $i++)
                            <td style="background: #fff; border: none;"></td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (!empty($filters))
        <div class="section-title">Report Filters</div>
        <div class="filters">
            <table class="filters-table">
                @foreach (array_chunk($filters, 2, true) as $group)
                    <tr>
                        @foreach ($group as $key => $value)
                            <td><span class="filter-label">{{ $key }}:</span> {{ is_array($value) ? implode(', ', $value) : $value }}</td>
                        @endforeach
                        @if (count($group) === 1)<td></td>@endif
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @php
        // The export service supplies 17 columns in a fixed order. Keep every field,
        // but split them into two readable tables linked by PO Number.
        $mainIndexes = [0, 2, 3, 4, 7, 8, 10, 13];
        $detailIndexes = [0, 1, 5, 6, 9, 11, 12, 14, 15, 16];
    @endphp

    <div class="section-title">Purchase Order Register</div>
    <div class="muted" style="margin-bottom: 7px;">{{ count($rows) }} purchase order(s) in this report. Monetary values are shown in their original currencies.</div>
    <table class="data-table">
        <colgroup>
            <col style="width: 15%;"><col style="width: 15%;"><col style="width: 17%;"><col style="width: 16%;">
            <col style="width: 7%;"><col style="width: 11%;"><col style="width: 10%;"><col style="width: 9%;">
        </colgroup>
        <thead><tr>@foreach ($mainIndexes as $index)<th>{{ $headers[$index] ?? '' }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($mainIndexes as $index)
                        <td class="{{ $index === 8 ? 'number' : '' }}">{{ $row[$index] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="8">No purchase orders match the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="page-break"></div>
    <div class="section-title">Additional Purchase Order Details</div>
    <div class="muted" style="margin-bottom: 8px;">Matched to the main register by PO Number. All additional export fields are retained.</div>
    <table class="data-table">
        <colgroup>
            <col style="width: 15%;"><col style="width: 6%;"><col style="width: 12%;"><col style="width: 11%;">
            <col style="width: 10%;"><col style="width: 10%;"><col style="width: 10%;"><col style="width: 9%;">
            <col style="width: 8%;"><col style="width: 9%;">
        </colgroup>
        <thead><tr>@foreach ($detailIndexes as $index)<th>{{ $headers[$index] ?? '' }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($detailIndexes as $index)
                        <td class="{{ $index === 9 ? 'number' : '' }}">{{ $row[$index] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="10">No additional purchase order details.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
