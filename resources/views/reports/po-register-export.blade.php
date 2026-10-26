<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
            margin: 18px;
        }
        .header {
            border-bottom: 3px solid #047857;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .brand {
            font-size: 18px;
            font-weight: 700;
            color: #047857;
        }
        .subhead {
            color: #475569;
            margin-top: 4px;
            font-size: 12px;
        }
        .meta {
            margin-top: 10px;
            font-size: 10px;
            color: #475569;
            line-height: 1.5;
        }
        .summary-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0 20px;
        }
        .summary-row {
            display: table-row;
        }
        .summary-cell {
            display: table-cell;
            width: 25%;
            border: 1px solid #dbe4ef;
            background: #f8fafc;
            padding: 8px 10px;
            font-size: 10px;
            color: #475569;
        }
        .summary-cell strong {
            display: block;
            font-size: 15px;
            color: #0f172a;
            margin-top: 4px;
        }
        .filters {
            margin: 10px 0 18px;
            background: #f8fafc;
            border: 1px solid #dbe4ef;
            padding: 10px;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        thead {
            display: table-header-group;
        }
        tr {
            page-break-inside: avoid;
        }
        th, td {
            border: 1px solid #dbe4ef;
            padding: 4px 5px;
            text-align: left;
            vertical-align: top;
            word-wrap: break-word;
        }
        th {
            background: #e2e8f0;
            color: #0f172a;
            font-size: 8px;
            font-weight: 700;
        }
        td {
            font-size: 8px;
            color: #1f2937;
        }
        .page-number {
            position: fixed;
            bottom: -10mm;
            right: 0;
            font-size: 9px;
            color: #475569;
            text-align: right;
        }
        .page-number:after {
            content: "Page " counter(page) " of " counter(pages);
        }
        @page {
            size: A4 landscape;
            margin: 18mm 12mm;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Emerald Industrial Co. CFZE</div>
        <div class="subhead">{{ $title }}</div>
        <div class="meta">
            Generated: {{ $generatedAt }}<br>
            Confidentiality: Internal — Confidential
        </div>
    </div>

    @if (!empty($summary))
        <div class="summary-grid">
            <div class="summary-row">
                @foreach ($summary as $label => $value)
                    <div class="summary-cell">
                        {{ $label }}
                        <strong>{{ $value }}</strong>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (!empty($filters))
        <div class="filters">
            <strong>Applied filters:</strong>
            @foreach ($filters as $key => $value)
                <div>{{ $key }}: {{ $value }}</div>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="page-number"></div>
</body>
</html>
