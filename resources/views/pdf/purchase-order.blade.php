<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - {{ $po_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            color: #111;
            padding: 16px 20px 24px;
        }
        .doc-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .doc-header td { vertical-align: top; }
        .logo-wrap { max-width: 200px; }
        .logo-img { max-width: 180px; max-height: 72px; object-fit: contain; display: block; }
        .logo-placeholder {
            width: 140px; height: 56px; border: 1px solid #ccc;
            display: table-cell; vertical-align: middle; text-align: center;
            font-size: 9px; color: #666;
        }
        .header-meta { text-align: right; padding-top: 4px; }
        .header-meta .meta-line { margin-bottom: 4px; font-size: 10px; }
        .header-meta .meta-label { font-weight: bold; }
        .doc-title {
            font-size: 17px;
            font-weight: bold;
            letter-spacing: 0.02em;
            margin-top: 10px;
            text-transform: uppercase;
        }
        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .info-grid td {
            width: 50%;
            vertical-align: top;
            padding: 8px 10px 10px 0;
            border: 1px solid #222;
        }
        .info-grid td:last-child { padding-right: 0; padding-left: 10px; }
        .info-col-title {
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 8px;
            border-bottom: 1px solid #333;
            padding-bottom: 4px;
        }
        .info-row { margin-bottom: 5px; font-size: 9.5px; }
        .info-row .lbl { font-weight: bold; display: inline; }
        .info-row .val { display: inline; }
        .section-banner {
            font-weight: bold;
            font-size: 11px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 12px 0 8px;
            padding: 6px 8px;
            border: 1px solid #222;
            background: #f4f4f4;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 9px;
        }
        .items-table th {
            background: {{ $emerald_green }};
            color: #fff;
            border: 1px solid #0a4a32;
            padding: 7px 4px;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }
        .items-table td {
            border: 1px solid #222;
            padding: 6px 4px;
            vertical-align: top;
        }
        .items-table .c-num { width: 28px; text-align: center; }
        .items-table .c-desc { text-align: left; }
        .items-table .c-uom { width: 44px; text-align: center; }
        .items-table .c-qty { width: 52px; text-align: center; }
        .items-table .c-money { width: 72px; text-align: right; white-space: nowrap; }
        .item-title { font-weight: bold; }
        .item-desc { font-size: 8.5px; color: #333; margin-top: 2px; }
        .totals-table {
            width: 100%;
            max-width: 320px;
            margin-left: auto;
            border-collapse: collapse;
            font-size: 9.5px;
            margin-bottom: 14px;
        }
        .totals-table td { padding: 4px 6px; border: 1px solid #222; }
        .totals-table .t-label { font-weight: bold; text-align: right; }
        .totals-table .t-val { text-align: right; white-space: nowrap; }
        .totals-table .t-grand td { font-weight: bold; background: #f0f0f0; }
        .comments-wrap {
            margin-top: 6px;
            margin-bottom: 16px;
        }
        .comments-label {
            font-weight: bold;
            font-size: 10px;
            margin-bottom: 4px;
        }
        .comments-box {
            border: 1px solid #222;
            min-height: 64px;
            padding: 8px 10px;
            font-size: 9px;
            white-space: pre-wrap;
        }
        .sig-heading {
            font-weight: bold;
            font-size: 10px;
            margin: 18px 0 10px;
            text-align: center;
            text-transform: uppercase;
        }
        .sig-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        .sig-grid td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #222;
            padding: 10px 12px 12px;
        }
        .sig-block-title {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 9.5px;
        }
        .sig-field { margin-bottom: 5px; }
        .sig-field .sl { font-weight: bold; }
        .sig-spacer { height: 28px; border-bottom: 1px solid #999; margin: 6px 0 4px; max-width: 95%; }
    </style>
</head>
<body>
    <table class="doc-header">
        <tr>
            <td style="width: 42%;">
                {!! $logo_html !!}
            </td>
            <td style="width: 58%;" class="header-meta">
                <div class="meta-line"><span class="meta-label">PO Number:</span> {{ $po_number }}</div>
                <div class="meta-line"><span class="meta-label">Date:</span> {{ $po_date_formatted }}</div>
                <div class="doc-title">{{ $document_title }}</div>
            </td>
        </tr>
    </table>

    <table class="info-grid">
        <tr>
            <td>
                <div class="info-col-title">{{ $order_info_title }}</div>
                @foreach ($order_rows as $row)
                    <div class="info-row">
                        <span class="lbl">{{ $row['label'] }}</span>
                        <span class="val">{!! nl2br(e($row['value'] ?? '')) !!}</span>
                    </div>
                @endforeach
            </td>
            <td>
                <div class="info-col-title">{{ $supplier_info_title }}</div>
                @foreach ($supplier_rows as $row)
                    <div class="info-row">
                        <span class="lbl">{{ $row['label'] }}</span>
                        <span class="val">{!! nl2br(e($row['value'] ?? '')) !!}</span>
                    </div>
                @endforeach
            </td>
        </tr>
    </table>

    <div class="section-banner">{{ $table_section_title }}</div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="c-num">ITEM</th>
                <th class="c-desc">DESCRIPTION</th>
                <th class="c-uom">UOM</th>
                <th class="c-qty">QTY</th>
                <th class="c-money">UNIT PRICE ({{ $currency }})</th>
                <th class="c-money">TOTAL ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($line_items as $line)
                <tr>
                    <td class="c-num">{{ $line['index'] }}</td>
                    <td class="c-desc">
                        <div class="item-title">{{ $line['title'] }}</div>
                        @if (!empty($line['description']))
                            <div class="item-desc">{!! nl2br(e($line['description'])) !!}</div>
                        @endif
                    </td>
                    <td class="c-uom">{{ $line['uom'] }}</td>
                    <td class="c-qty">{{ $line['qty'] }}</td>
                    <td class="c-money">{{ $line['unit_price'] }}</td>
                    <td class="c-money">{{ $line['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td class="t-label">Subtotal</td>
            <td class="t-val">{{ $subtotal }}</td>
        </tr>
        @if ($show_tax_breakdown)
            <tr>
                <td class="t-label">Tax @if ($tax_rate > 0)({{ number_format($tax_rate, 2) }}%)@endif</td>
                <td class="t-val">{{ $tax }}</td>
            </tr>
        @endif
        <tr class="t-grand">
            <td class="t-label">Total ({{ $currency }})</td>
            <td class="t-val">{{ $total }}</td>
        </tr>
    </table>

    <div class="comments-wrap">
        <div class="comments-label">COMMENTS:</div>
        <div class="comments-box">@if ($comments !== ''){!! nl2br(e($comments)) !!}@else &nbsp; @endif</div>
    </div>

    <div class="sig-heading">Authorized signatories</div>
    <table class="sig-grid">
        <tr>
            @foreach (array_slice($signature_blocks, 0, 2) as $sig)
                <td>
                    <div class="sig-block-title">{{ $sig['title'] }}</div>
                    <div class="sig-field"><span class="sl">Name:</span> {{ $sig['name'] }}</div>
                    <div class="sig-field"><span class="sl">Position:</span> {{ $sig['position'] }}</div>
                    <div class="sig-spacer"></div>
                    <div class="sig-field"><span class="sl">Sign/Date:</span></div>
                    <div class="sig-field"><span class="sl">Phone:</span> {{ $sig['phone'] }}</div>
                    <div class="sig-field"><span class="sl">Email:</span> {{ $sig['email'] }}</div>
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach (array_slice($signature_blocks, 2, 2) as $sig)
                <td>
                    <div class="sig-block-title">{{ $sig['title'] }}</div>
                    <div class="sig-field"><span class="sl">Name:</span> {{ $sig['name'] }}</div>
                    <div class="sig-field"><span class="sl">Position:</span> {{ $sig['position'] }}</div>
                    <div class="sig-spacer"></div>
                    <div class="sig-field"><span class="sl">Sign/Date:</span></div>
                    <div class="sig-field"><span class="sl">Phone:</span> {{ $sig['phone'] }}</div>
                    <div class="sig-field"><span class="sl">Email:</span> {{ $sig['email'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>
</body>
</html>
