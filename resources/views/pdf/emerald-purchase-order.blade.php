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
            color: #111;
            padding: 20px;
        }
        a { color: inherit; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .header-left { width: 70%; vertical-align: top; }
        .header-right { width: 30%; text-align: right; vertical-align: top; }
        .company-name { font-size: 16px; font-weight: bold; margin-bottom: 6px; }
        .company-contact { font-size: 8.5px; line-height: 1.5; color: #222; margin-bottom: 4px; }
        .company-contact a { color: #222; }
        .company-contact a.website { color: #1a4b8c; text-decoration: underline; }
        .document-title { font-size: 28px; font-weight: 300; color: #4696b9; margin: 0 0 16px; letter-spacing: 0.01em; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border-bottom: 1px solid #cbd5e1; }
        .meta-table th,
        .meta-table td { vertical-align: top; padding: 6px 6px; }
        .meta-table th { font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; color: #333; text-align: left; }
        .meta-table td { font-size: 10px; line-height: 1.45; }
        .meta-value { margin-top: 2px; }
        .meta-value.strong { font-weight: bold; }
        .meta-sub { font-size: 8.5px; color: #555; margin-top: 2px; }
        .meta-group { margin-bottom: 8px; }
        .meta-group .footer-label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.08em; color: #333; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 0; font-size: 9px; }
        .items-table th { padding: 8px 6px; background: #c8e4f0; color: #2d4152; font-weight: bold; text-align: left; }
        .items-table td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .items-table .col-category { width: 20%; }
        .items-table .col-qty { width: 6%; text-align: right; }
        .items-table .col-rate { width: 14%; text-align: right; }
        .items-table .col-tax { width: 8%; text-align: right; }
        .items-table .col-amount { width: 16%; text-align: right; }
        .item-category { font-weight: bold; }
        .dashed-sep { border: none; border-top: 1px dashed #94a3b8; margin: 10px 0 14px; }
        .footer-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .footer-left { width: 65%; padding-right: 14px; vertical-align: top; font-size: 9px; }
        .footer-right { width: 35%; vertical-align: top; }
        .footer-block { margin-bottom: 8px; line-height: 1.45; }
        .footer-label { font-weight: bold; }
        .terms-list { list-style: none; padding-left: 0; margin: 4px 0 0; }
        .terms-list li { margin-bottom: 2px; }
        .payment-schedule { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 8.5px; }
        .payment-schedule th,
        .payment-schedule td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        .payment-schedule th { background: #f1f5f9; font-weight: bold; }
        .payment-schedule td.num { text-align: right; }
        .totals-table { width: 100%; border-collapse: collapse; font-size: 9px; }
        .totals-table td { padding: 6px 8px; border: 1px solid #000; }
        .totals-table .t-label { font-weight: bold; }
        .totals-table .t-val { text-align: right; }
        .totals-table tr.grand td { font-weight: bold; background: #e5e7eb; }
        .approval-block { margin-top: 20px; font-size: 9px; width: 45%; }
        .approval-block .label { font-weight: bold; margin-bottom: 4px; }
        .approval-block .name { margin-bottom: 4px; }
        .signature-img { max-width: 160px; max-height: 60px; display: block; margin: 0 0 2px; }
        .signature-rule { border: none; border-top: 1px solid #000; margin: 4px 0 10px; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="header-left">
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="company-contact">{!! nl2br(e($company['address'] ?? '')) !!}</div>
                @if (!empty($company['email']))
                    <div class="company-contact"><a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a></div>
                @endif
                @if (!empty($company['website']))
                    <div class="company-contact"><a class="website" href="{{ $company['website'] }}">{{ $company['website'] }}</a></div>
                @endif
            </td>
            <td class="header-right">
                {!! $logo_html !!}
            </td>
        </tr>
    </table>

    <div class="document-title">{{ $document_title }}</div>

    <table class="meta-table">
        <tr>
            <th>Supplier</th>
            <th>Ship To</th>
            <th>P.O. No.</th>
        </tr>
        <tr>
            <td>
                <div class="meta-value strong">{{ $supplier_name }}</div>
                @if (!empty($supplier_code_display) && $supplier_code_display !== $supplier_name)
                    <div class="meta-sub">{{ $supplier_code_display }}</div>
                @endif
            </td>
            <td>
                <div class="meta-value">{{ $ship_to_company }}</div>
            </td>
            <td>
                <div class="meta-value strong">{{ $po_number }}</div>
                <div class="meta-group" style="margin-top:8px;">
                    <div class="footer-label">Date</div>
                    <div class="meta-value">{{ $po_date_short }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-category">&nbsp;</th>
                <th>DESCRIPTION</th>
                <th class="col-qty">QTY</th>
                <th class="col-rate">RATE</th>
                <th class="col-tax">TAX</th>
                <th class="col-amount">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($line_items as $line)
                <tr>
                    <td class="col-category item-category">{{ $line['category'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="col-qty">{{ $line['qty'] }}</td>
                    <td class="col-rate">{{ $line['rate'] }}</td>
                    <td class="col-tax">{{ $line['tax_label'] }}</td>
                    <td class="col-amount">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <hr class="dashed-sep" />

    <table class="footer-table">
        <tr>
            <td class="footer-left">
                <div class="footer-block">
                    <span class="footer-label">Invoice submission:</span>
                    <a href="mailto:{{ $invoice_submission_to }}">{{ $invoice_submission_to }}</a>
                    @if (!empty($invoice_submission_cc))
                        <br />
                        <span class="footer-label">cc:</span>
                        {{ $invoice_submission_cc }}
                    @endif
                </div>

                <div class="footer-block">
                    <div class="footer-label">Standard terms:</div>
                    <ul class="terms-list">
                        @foreach ($standard_terms_lines as $term)
                            <li>- {{ $term }}</li>
                        @endforeach
                    </ul>
                </div>

                @if (!empty($has_payment_milestones))
                    <div class="footer-block">
                        <div class="footer-label">Payment Schedule:</div>
                        <table class="payment-schedule">
                            <thead>
                                <tr>
                                    <th style="width:20px;">#</th>
                                    <th>Milestone</th>
                                    <th style="width:36px;" class="num">%</th>
                                    <th style="width:80px;" class="num">Amount</th>
                                    <th>Trigger</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payment_milestones as $m)
                                    <tr>
                                        <td class="num">{{ $m['milestone_number'] ?? $loop->iteration }}</td>
                                        <td>{{ $m['label'] ?? '' }}</td>
                                        <td class="num">{{ $m['percentage'] ?? '' }}</td>
                                        <td class="num">{{ $m['amount_display'] ?? $m['amount'] ?? '' }}</td>
                                        <td>{{ $m['trigger_label'] ?? $m['trigger'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="footer-block">
                        <span class="footer-label">Payment Terms:</span>
                        {{ $payment_terms_display }}
                    </div>
                @endif

                <div class="footer-block">
                    <span class="footer-label">Contract type:</span>
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

    <div class="approval-block">
        <div class="label">Approved By</div>
        <div class="name">{{ $approved_by_name }}</div>
        {!! $signature_html !!}
        <hr class="signature-rule" />
        <div class="label">Date</div>
        <div>{{ $approved_by_date }}</div>
        <hr class="signature-rule" />
    </div>
</body>
</html>
