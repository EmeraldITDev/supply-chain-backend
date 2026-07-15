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
        .document-title { font-size: 28px; font-weight: 300; color: #1f4c6b; margin: 0 0 16px; letter-spacing: 0.01em; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .meta-table th,
        .meta-table td { vertical-align: top; padding: 6px 6px; }
        .meta-table th { font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; color: #333; }
        .meta-table td { font-size: 10px; line-height: 1.45; }
        .meta-value { margin-top: 4px; }
        .meta-value strong { font-weight: bold; }
        .meta-group { margin-bottom: 8px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 9px; }
        .items-table th { padding: 10px 8px; background: #f1f5f9; color: #1f2937; font-weight: bold; text-align: left; border-bottom: 1px solid #cbd5e1; }
        .items-table td { padding: 10px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .items-table tbody tr:last-child td { border-bottom: 1px dashed #94a3b8; }
        .items-table .col-category { width: 16%; }
        .items-table .col-qty { width: 8%; text-align: center; }
        .items-table .col-rate { width: 16%; text-align: right; }
        .items-table .col-tax { width: 10%; text-align: center; }
        .items-table .col-amount { width: 16%; text-align: right; }
        .item-category { font-weight: bold; font-size: 9px; margin-bottom: 3px; }
        .footer-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .footer-left { width: 68%; padding-right: 14px; vertical-align: top; font-size: 9px; }
        .footer-right { width: 32%; vertical-align: top; }
        .footer-block { margin-bottom: 10px; line-height: 1.45; }
        .footer-label { font-weight: bold; }
        .terms-list { list-style: none; padding-left: 14px; margin: 6px 0 0; }
        .terms-list li { margin-bottom: 2px; }
        .totals-table { width: 100%; border-collapse: collapse; font-size: 9px; }
        .totals-table td { padding: 8px 10px; border: 1px solid #cbd5e1; }
        .totals-table .t-label { font-weight: bold; }
        .totals-table .t-val { text-align: right; }
        .totals-table tr.grand td { font-weight: bold; background: #f8fafc; }
        .approval-block { margin-top: 20px; font-size: 9px; }
        .approval-block .label { font-weight: bold; margin-bottom: 6px; }
        .approval-block .name { margin-bottom: 10px; }
        .signature-img { max-width: 160px; max-height: 70px; display: block; margin-bottom: 8px; }
        .signature-rule { border: none; border-top: 1px solid #94a3b8; margin: 10px 0; }
        .signature-row { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
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
                    <div class="company-contact"><a href="{{ $company['website'] }}">{{ $company['website'] }}</a></div>
                @endif
            </td>
            <td class="header-right">
                <img src="{{ $logo_path }}"
                    style="width: 90px; height: auto; max-height: 60px;"
                    alt="{{ $company['name'] }}" />
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
                <div class="meta-value">{{ $supplier_code_display }}</div>
            </td>
            <td>
                <div class="meta-value">{{ $ship_to_company }}</div>
            </td>
            <td>
                <div class="meta-group">
                    <div class="footer-label">P.O. NO.</div>
                    <div class="meta-value strong">{{ $po_number }}</div>
                </div>
                <div class="meta-group">
                    <div class="footer-label">DATE</div>
                    <div class="meta-value">{{ $po_date_short }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-category">CATEGORY</th>
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
                    <td class="col-category">{{ $line['category'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="col-qty">{{ $line['qty'] }}</td>
                    <td class="col-rate">{{ $line['rate'] }}</td>
                    <td class="col-tax">{{ $line['tax_label'] }}</td>
                    <td class="col-amount">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

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
                    <span class="footer-label">Standard terms:</span>
                    <ul class="terms-list">
                        @foreach ($standard_terms_lines as $term)
                            <li>- {{ $term }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="footer-block">
                    <span class="footer-label">Payment Terms:</span>
                    {{ $payment_terms_display }}
                </div>

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
        <div class="signature-row">
            <div class="label">Date</div>
            <div>{{ $approved_by_date }}</div>
        </div>
        <hr class="signature-rule" />
    </div>
</body>
</html>
