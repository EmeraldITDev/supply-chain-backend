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
            line-height: 1.4;
            color: #000;
            padding: 18px 22px 28px;
        }
        .top-band {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .top-band td { vertical-align: top; }
        .brand-cell { width: 62%; padding-right: 12px; }
        .logo-row { margin-bottom: 8px; }
        .logo-wrap { display: block; }
        .logo-img { max-width: 200px; max-height: 64px; object-fit: contain; display: block; }
        .logo-placeholder {
            width: 160px; height: 52px; border: 1px solid #ccc;
            text-align: center; line-height: 52px; font-size: 9px; color: #666;
        }
        .company-name {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .company-lines {
            font-size: 9.5px;
            color: #111;
            white-space: pre-wrap;
        }
        .doc-title {
            font-size: 20px;
            font-weight: bold;
            margin-top: 16px;
            letter-spacing: 0.02em;
        }
        .pair-grid {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0 12px;
        }
        .pair-grid td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #000;
            padding: 10px 12px;
            min-height: 72px;
        }
        .pair-label {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.04em;
        }
        .pair-body {
            font-size: 9.5px;
        }
        .pair-body .primary { font-weight: bold; margin-bottom: 4px; }
        .po-meta {
            margin: 10px 0 14px;
            font-size: 10px;
        }
        .po-meta span { margin-right: 28px; }
        .po-meta strong { font-weight: bold; }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 9.5px;
        }
        .items-table th {
            background: #e8e8e8;
            color: #000;
            border: 1px solid #000;
            padding: 8px 6px;
            font-weight: bold;
            text-align: left;
        }
        .items-table th.col-qty { text-align: center; width: 44px; }
        .items-table th.col-rate { text-align: right; width: 88px; }
        .items-table th.col-tax { text-align: center; width: 52px; }
        .items-table th.col-amt { text-align: right; width: 96px; }
        .items-table td {
            border: 1px solid #000;
            padding: 8px 6px;
            vertical-align: top;
        }
        .items-table td.col-qty { text-align: center; }
        .items-table td.col-rate { text-align: right; white-space: nowrap; }
        .items-table td.col-tax { text-align: center; }
        .items-table td.col-amt { text-align: right; white-space: nowrap; }
        .desc-line { margin-bottom: 2px; }
        .desc-line.sub { font-size: 8.5px; color: #333; }
        .desc-line.title { font-weight: bold; font-size: 10px; }
        .payment-block {
            margin: 16px 0 12px;
            font-size: 9.5px;
        }
        .payment-block .lbl {
            font-weight: bold;
            margin-bottom: 6px;
        }
        .payment-text {
            white-space: pre-wrap;
            max-width: 100%;
        }
        .notes-block {
            margin-top: 12px;
            font-size: 8.5px;
            color: #333;
        }
        .notes-block .lbl { font-weight: bold; margin-bottom: 4px; color: #000; }
        .totals-wrap {
            width: 100%;
            margin: 14px 0 20px;
        }
        .totals-table {
            width: 260px;
            margin-left: auto;
            border-collapse: collapse;
            font-size: 10px;
        }
        .totals-table td {
            padding: 5px 8px;
            border: 1px solid #000;
        }
        .totals-table .t-label { font-weight: bold; text-align: left; }
        .totals-table .t-val { text-align: right; white-space: nowrap; }
        .totals-table tr.grand td { font-weight: bold; background: #f5f5f5; }
        .approval {
            margin-top: 28px;
            max-width: 280px;
            font-size: 10px;
        }
        .approval .lbl { font-weight: bold; margin-bottom: 4px; }
        .approval .val { margin-bottom: 14px; min-height: 14px; }
        .approval .rule { border-bottom: 1px solid #000; min-height: 1px; margin-top: 4px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <table class="top-band">
        <tr>
            <td class="brand-cell">
                <div class="logo-row">{!! $logo_html !!}</div>
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="company-lines">{!! nl2br(e($company['address'] ?? '')) !!}</div>
                @if (!empty($company['email']))
                    <div class="company-lines" style="margin-top: 4px;">{{ $company['email'] }}</div>
                @endif
                @if (!empty($company['website']))
                    <div class="company-lines">{{ $company['website'] }}</div>
                @endif
                <div class="doc-title">{{ $document_title }}</div>
            </td>
            <td></td>
        </tr>
    </table>

    <table class="pair-grid">
        <tr>
            <td>
                <div class="pair-label">Supplier</div>
                <div class="pair-body">
                    <div class="primary">{{ $supplier_name }}</div>
                    @if ($supplier_address !== '')
                        <div>{!! nl2br(e($supplier_address)) !!}</div>
                    @endif
                </div>
            </td>
            <td>
                <div class="pair-label">Ship To</div>
                <div class="pair-body">
                    <div class="primary">{{ $buyer_name }}</div>
                    @if ($ship_to_address !== '')
                        <div>{!! nl2br(e($ship_to_address)) !!}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="po-meta">
        <span><strong>P.O. NO.</strong> {{ $po_number }}</span>
        <span><strong>DATE</strong> {{ $po_date_short }}</span>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="col-qty">Qty</th>
                <th class="col-rate">Rate</th>
                <th class="col-tax">Tax</th>
                <th class="col-amt">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($line_items as $line)
                <tr>
                    <td>
                        @foreach ($line['description_segments'] as $seg)
                            @if (($seg['text'] ?? '') !== '')
                                <div class="desc-line {{ $seg['class'] ?? '' }}">{!! nl2br(e($seg['text'])) !!}</div>
                            @endif
                        @endforeach
                    </td>
                    <td class="col-qty">{{ $line['qty'] }}</td>
                    <td class="col-rate">{{ $line['rate'] }}</td>
                    <td class="col-tax">{{ $line['tax_label'] }}</td>
                    <td class="col-amt">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="payment-block">
        <div class="lbl">PAYMENT TERMS:</div>
        <div class="payment-text">{!! nl2br(e($payment_terms)) !!}</div>
    </div>

    @if ($additional_notes !== '')
        <div class="notes-block">
            <div class="lbl">NOTES:</div>
            <div class="payment-text">{!! nl2br(e($additional_notes)) !!}</div>
        </div>
    @endif

    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td class="t-label">SUBTOTAL</td>
                <td class="t-val">{{ $subtotal }}</td>
            </tr>
            @if ($show_tax_breakdown)
                <tr>
                    <td class="t-label">TAX</td>
                    <td class="t-val">{{ $tax }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td class="t-label">TOTAL {{ $currency }}</td>
                <td class="t-val">{{ $total }}</td>
            </tr>
        </table>
    </div>

    <div class="approval">
        <div class="lbl">Approved By</div>
        <div class="val">{{ $approved_by_name }}</div>
        <div class="lbl">Date</div>
        <div class="val">{{ $approved_by_date }}</div>
    </div>
</body>
</html>
