<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 24px; }
        .top-band { width: 100%; margin-bottom: 16px; border-bottom: 2px solid #0d5c3f; padding-bottom: 10px; }
        .brand-cell { vertical-align: top; width: 55%; }
        .title-cell { vertical-align: top; text-align: right; width: 45%; }
        .logo-wrap img, .logo-img { max-height: 48px; max-width: 140px; }
        .logo-text-fallback { font-weight: bold; font-size: 14px; color: #0d5c3f; }
        .company-name { font-size: 13px; font-weight: bold; color: #0d5c3f; margin-top: 6px; }
        .company-lines { font-size: 9px; color: #444; margin-top: 2px; white-space: pre-line; }
        .doc-title { font-size: 16px; font-weight: bold; color: #111; }
        .meta { color: #555; margin-bottom: 8px; }
        .filter-note { color: #92400e; background: #fffbeb; border: 1px solid #fcd34d; padding: 6px 8px; margin-bottom: 10px; font-size: 9px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; word-wrap: break-word; }
        table.data th { background: #f3f4f6; font-weight: bold; }
    </style>
</head>
<body>
    <table class="top-band">
        <tr>
            <td class="brand-cell">
                {!! $logoHtml !!}
                <div class="company-name">{{ $companyName }}</div>
                @if (!empty($companyAddress))
                    <div class="company-lines">{{ $companyAddress }}</div>
                @endif
            </td>
            <td class="title-cell">
                <div class="doc-title">{{ $title }}</div>
            </td>
        </tr>
    </table>

    <p class="meta">Generated at {{ $generatedAt }} &middot; {{ $recordCount }} vendor(s)</p>

    @if (!empty($filterNote))
        <div class="filter-note">{{ $filterNote }}</div>
    @endif

    <table class="data">
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
</body>
</html>
