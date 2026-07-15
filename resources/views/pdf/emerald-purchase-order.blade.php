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
            color: #000;
            padding: 16px 20px 24px;
        }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .header-table td { vertical-align: top; }
        .brand-block { width: 68%; }
        .logo-row { margin-bottom: 6px; min-height: 20px; }
        .logo-img { max-width: 180px; max-height: 56px; object-fit: contain; display: block; }
        .logo-placeholder { width: 140px; height: 44px; border: 1px solid #ccc; text-align: center; line-height: 44px; font-size: 9px; color: #666; }
        .company-name { font-size: 11px; font-weight: bold; margin-bottom: 3px; }
        .company-line { font-size: 9px; color: #111; }
        .doc-title {
            font-size: 20px;
            font-weight: normal;
            color: #4696b9;
            margin-top: 10px;
            letter-spacing: 0.01em;
        }
        .meta-labels {
            width: 100%;
            margin: 12px 0 2px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .meta-labels td { width: 33.33%; vertical-align: bottom; padding-right: 8px; }
        .meta-values {
            width: 100%;
            margin-bottom: 4px;
            font-size: 8.5px;
        }
        .meta-values td { width: 33.33%; vertical-align: top; padding-right: 8px; }
        .meta-values .po-num { font-weight: bold; }
        .meta-date { margin: 4px 0 10px; font-size: 8px; }
        .meta-date .lbl { font-weight: bold; text-transform: uppercase; margin-right: 6px; }
        .rule { border-top: 1px solid #b4b4b4; margin: 8px 0 10px; }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 8px;
        }
        .items-table th {
            background: #c8e4f0;
            color: #2d4152;
            border: none;
            padding: 6px 4px;
            font-weight: bold;
            text-align: left;
        }
        .items-table th.col-qty { text-align: center; width: 36px; }
        .items-table th.col-rate { text-align: right; width: 72px; }
        .items-table th.col-tax { text-align: center; width: 40px; }
        .items-table th.col-amt { text-align: right; width: 80px; }
        .items-table td {
            border-bottom: 1px solid #dcdcdc;
            padding: 6px 4px;
            vertical-align: top;
        }
        .items-table td.col-qty { text-align: center; }
        .items-table td.col-rate { text-align: right; white-space: nowrap; }
        .items-table td.col-tax { text-align: center; }
        .items-table td.col-amt { text-align: right; white-space: nowrap; }
        .desc-category { font-weight: bold; font-size: 8px; margin-bottom: 2px; }
        .desc-body { font-size: 8px; }
        .footer-wrap { width: 100%; margin-top: 10px; }
        .footer-wrap td { vertical-align: top; }
        .footer-left { width: 58%; padding-right: 12px; font-size: 8px; }
        .footer-right { width: 42%; }
        .footer-line { margin-bottom: 3px; }
        .footer-lbl { font-weight: bold; }
        .terms-list { margin: 4px 0 6px 0; padding-left: 0; list-style: none; }
        .terms-list li { margin-bottom: 2px; }
        .milestones-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0 12px;
            font-size: 8px;
        }
        .milestones-table th,
        .milestones-table td {
            border: 1px solid #dcdcdc;
            padding: 5px 4px;
            vertical-align: top;
        }
        .milestones-table th {
            background: #eef7fb;
            font-weight: bold;
            text-align: left;
        }
        .milestones-table .col-number { width: 32px; text-align: center; }
        .milestones-table .col-percentage { width: 58px; text-align: right; }
        .milestones-table .col-amount { width: 90px; text-align: right; white-space: nowrap; }
        .milestones-table .col-trigger { width: 110px; }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5px;
        }
        .totals-table td {
            border: 1px solid #000;
            padding: 5px 6px;
        }
        .totals-table .t-label { font-weight: bold; text-align: left; }
        .totals-table .t-val { text-align: right; white-space: nowrap; }
        .totals-table tr.grand td { font-weight: bold; background: #ebebeb; }
        .approval { margin-top: 18px; max-width: 280px; font-size: 9px; }
        .approval .lbl { font-weight: bold; margin-bottom: 4px; }
        .approval .val { margin-bottom: 10px; min-height: 12px; }
        .signature-img { max-width: 130px; max-height: 52px; display: block; margin-bottom: 6px; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="brand-block">
                <div class="logo-row">{!! $logo_html !!}</div>
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="company-line">{!! nl2br(e($company['address'] ?? '')) !!}</div>
                @if (!empty($company['email']))
                    <div class="company-line" style="margin-top: 3px;">{{ $company['email'] }}</div>
                @endif
                @if (!empty($company['website']))
                    <div class="company-line">{{ $company['website'] }}</div>
                @endif
                <div class="doc-title">{{ $document_title }}</div>
            </td>
            <td></td>
        </tr>
    </table>

    <table class="meta-labels">
        <tr>
            <td>Supplier</td>
            <td>Ship To</td>
            <td>P.O. No.</td>
        </tr>
    </table>
    <table class="meta-values">
        <tr>
            <td>{{ $supplier_name }}</td>
            <td>{{ $ship_to_display }}</td>
            <td class="po-num">{{ $po_number }}</td>
        </tr>
    </table>
    <div class="meta-date">
        <span class="lbl">Date</span>
        <span>{{ $po_date_short }}</span>
    </div>

    <div class="rule"></div>

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
                        @if (!empty($line['category']))
                            <div class="desc-category">{{ $line['category'] }}</div>
                        @endif
                        <div class="desc-body">{{ $line['description'] }}</div>
                    </td>
                    <td class="col-qty">{{ $line['qty'] }}</td>
                    <td class="col-rate">{{ $line['rate'] }}</td>
                    <td class="col-tax">{{ $line['tax_label'] }}</td>
                    <td class="col-amt">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="footer-wrap">
        <tr>
            <td class="footer-left">
                @if (!empty($invoice_submission_line))
                    <div class="footer-line">{{ $invoice_submission_line }}</div>
                @endif
                @if (!empty($standard_terms_lines))
                    <div class="footer-line" style="margin-top: 8px;"><span class="footer-lbl">Standard terms:</span></div>
                    <ul class="terms-list">
                        @foreach ($standard_terms_lines as $term)
                            <li>- {{ $term }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (!empty($payment_milestones))
                    <div class="footer-line" style="margin-top: 8px;"><span class="footer-lbl">Payment schedule:</span></div>
                    <table class="milestones-table">
                        <thead>
                            <tr>
                                <th class="col-number">No.</th>
                                <th>Milestone</th>
                                <th class="col-percentage">%</th>
                                <th class="col-amount">Amount</th>
                                <th class="col-trigger">Trigger</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payment_milestones as $milestone)
                                <tr>
                                    <td class="col-number">{{ $milestone['number'] ?? '' }}</td>
                                    <td>{{ $milestone['label'] ?? '' }}</td>
                                    <td class="col-percentage">{{ $milestone['percentage'] ?? '' }}</td>
                                    <td class="col-amount">{{ $milestone['amount'] ?? '' }}</td>
                                    <td class="col-trigger">{{ $milestone['trigger'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="footer-line">
                        <span class="footer-lbl">Payment Terms:</span>
                        {{ $payment_terms_display }}
                    </div>
                @endif
                <div class="footer-line">
                    <span class="footer-lbl">Contract type:</span>
                    {{ $contract_type_display }}
                </div>
            </td>
            <td class="footer-right">
                <table class="totals-table">
                    <tr>
                        <td class="t-label">SUBTOTAL</td>
                        <td class="t-val">{{ $subtotal }}</td>
                    </tr>
                    <tr>
                        <td class="t-label">TAX</td>
                        <td class="t-val">{{ $tax }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="t-label">TOTAL {{ $currency }}</td>
                        <td class="t-val">{{ $total }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="approval">
        <div class="lbl">Approved By</div>
        @if (!empty($signature_html))
            {!! $signature_html !!}
        @endif
        <div class="val">{{ $approved_by_name }}</div>
        <div class="lbl">Date</div>
        <div class="val">{{ $approved_by_date }}</div>
    </div>
</body>
</html>
