<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Goods Received Note - {{ $grn_number }}</title>
    <style>
        @page {
            margin: 2.5cm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            color: #000;
        }
        .top-band {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .top-band td { vertical-align: top; }
        .brand-cell { width: 58%; padding-right: 10px; }
        .title-cell { width: 42%; text-align: right; vertical-align: top; }
        .logo-wrap { display: block; margin-bottom: 6px; }
        .logo-img { max-width: 180px; max-height: 58px; object-fit: contain; display: block; }
        .logo-placeholder {
            width: 150px; height: 48px; border: 1px solid #ccc;
            text-align: center; line-height: 48px; font-size: 9px; color: #666;
        }
        .company-name {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .company-lines {
            font-size: 9px;
            white-space: pre-wrap;
        }
        .doc-title {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 0.03em;
            line-height: 1.1;
        }
        .header-meta {
            margin: 12px 0 14px;
            font-size: 10px;
        }
        .header-meta div { margin-bottom: 4px; }
        .header-meta strong { font-weight: bold; }
        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 9.5px;
        }
        .info-grid td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #000;
            padding: 8px 10px;
        }
        .section-heading {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 10px;
        }
        .field-row { margin-bottom: 5px; }
        .field-row .lbl { font-weight: bold; }
        .material-heading {
            text-align: center;
            font-weight: bold;
            font-size: 11px;
            margin: 8px 0 6px;
            text-transform: uppercase;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 9px;
        }
        .items-table th,
        .items-table td {
            border: 1px solid #000;
            padding: 6px 5px;
            vertical-align: top;
        }
        .items-table th {
            font-weight: bold;
            text-align: center;
        }
        .items-table th.col-item { width: 5%; }
        .items-table th.col-desc { width: 28%; text-align: left; }
        .items-table th.col-uom { width: 8%; }
        .items-table th.col-qty { width: 11%; }
        .items-table th.col-price { width: 13%; }
        .items-table th.col-total { width: 13%; }
        .items-table td.col-item,
        .items-table td.col-uom,
        .items-table td.col-qty,
        .items-table td.col-price,
        .items-table td.col-total {
            text-align: center;
        }
        .items-table td.col-price,
        .items-table td.col-total {
            white-space: nowrap;
        }
        .comments-row td {
            height: 42px;
            vertical-align: top;
        }
        .comments-label {
            font-weight: bold;
            margin-bottom: 4px;
        }
        .signatories-heading {
            font-weight: bold;
            margin: 16px 0 8px;
            font-size: 10px;
        }
        .signatories-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        .signatories-table td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #000;
            padding: 8px 10px;
        }
        .sig-block-title {
            font-weight: bold;
            margin-bottom: 8px;
        }
        .sig-field { margin-bottom: 5px; }
        .sig-field .lbl { font-weight: bold; }
        .sig-blank-line {
            min-height: 18px;
            border-bottom: 1px solid #000;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <table class="top-band">
        <tr>
            <td class="brand-cell">
                {!! $logo_html !!}
                <div class="company-name">{{ $company_name }}</div>
                @if (!empty($company_address))
                    <div class="company-lines">{{ $company_address }}</div>
                @endif
            </td>
            <td class="title-cell">
                <div class="doc-title">GOODS RECEIVED NOTE</div>
            </td>
        </tr>
    </table>

    <div class="header-meta">
        <div><strong>GRN Number:</strong> {{ $grn_number }}</div>
        <div><strong>Date of Receipt:</strong> {{ $date_of_receipt }}</div>
    </div>

    <table class="info-grid">
        <tr>
            <td>
                <div class="section-heading">Delivery Information</div>
                <div class="field-row"><span class="lbl">Delivery Note Number:</span> {{ $delivery_note_number }}</div>
                <div class="field-row"><span class="lbl">Delivery Date:</span> {{ $delivery_date }}</div>
                <div class="field-row"><span class="lbl">Carrier/Driver Name:</span> {{ $carrier_name }}</div>
                <div class="field-row"><span class="lbl">Number:</span> {{ $driver_number }}</div>
                <div class="field-row"><span class="lbl">Vehicle Plate Number:</span> {{ $vehicle_plate_number }}</div>
            </td>
            <td>
                <div class="section-heading">Supplier Information</div>
                <div class="field-row"><span class="lbl">Supplier Name:</span> {{ $supplier_name }}</div>
                <div class="field-row"><span class="lbl">Supplier Address:</span></div>
                <div style="white-space: pre-wrap; margin-top: 2px;">{{ $supplier_address }}</div>
            </td>
        </tr>
    </table>

    <div class="material-heading">Material Received Note</div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-item">Item</th>
                <th class="col-desc">Description</th>
                <th class="col-uom">UOM</th>
                <th class="col-qty">Quantity<br>Ordered</th>
                <th class="col-qty">Quantity<br>Received</th>
                <th class="col-price">Unit Price (₦)</th>
                <th class="col-total">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($line_items as $line)
                <tr>
                    <td class="col-item">{{ $line['item'] }}</td>
                    <td class="col-desc">{{ $line['description'] }}</td>
                    <td class="col-uom">{{ $line['uom'] }}</td>
                    <td class="col-qty">{{ $line['quantity_ordered'] }}</td>
                    <td class="col-qty">{{ $line['quantity_received'] }}</td>
                    <td class="col-price">{{ $line['unit_price'] }}</td>
                    <td class="col-total">{{ $line['total'] }}</td>
                </tr>
            @endforeach
            <tr class="comments-row">
                <td colspan="7">
                    <div class="comments-label">COMMENTS:</div>
                    @if (!empty($comments))
                        {!! nl2br(e($comments)) !!}
                    @else
                        <div class="sig-blank-line"></div>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    <div class="signatories-heading">Authorized signatories</div>

    <table class="signatories-table">
        <tr>
            <td>
                <div class="sig-block-title">Vendor (delivered by)</div>
                <div class="sig-field"><span class="lbl">Name:</span> {{ $signatories['vendor_delivered']['name'] }}</div>
                <div class="sig-field"><span class="lbl">Position:</span> {{ $signatories['vendor_delivered']['position'] }}</div>
                <div class="sig-field"><span class="lbl">Sign/Date:</span> {{ $signatories['vendor_delivered']['sign_date'] }}</div>
                <div class="sig-field"><span class="lbl">Phone:</span> {{ $signatories['vendor_delivered']['phone'] }}</div>
                <div class="sig-field"><span class="lbl">Email:</span> {{ $signatories['vendor_delivered']['email'] }}</div>
            </td>
            <td>
                <div class="sig-block-title">Emerald (Received by)</div>
                <div class="sig-field"><span class="lbl">Name:</span> {{ $signatories['emerald_received']['name'] }}</div>
                <div class="sig-field"><span class="lbl">Position:</span> {{ $signatories['emerald_received']['position'] }}</div>
                <div class="sig-field"><span class="lbl">Sign/Date:</span> {{ $signatories['emerald_received']['sign_date'] }}</div>
                <div class="sig-field"><span class="lbl">Phone:</span> {{ $signatories['emerald_received']['phone'] }}</div>
                <div class="sig-field"><span class="lbl">Email:</span> {{ $signatories['emerald_received']['email'] }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="sig-block-title">Vendor (witnessed by)</div>
                <div class="sig-field"><span class="lbl">Name:</span> {{ $signatories['vendor_witnessed']['name'] }}</div>
                <div class="sig-field"><span class="lbl">Position:</span> {{ $signatories['vendor_witnessed']['position'] }}</div>
                <div class="sig-field"><span class="lbl">Sign/Date:</span> {{ $signatories['vendor_witnessed']['sign_date'] }}</div>
                <div class="sig-field"><span class="lbl">Phone:</span> {{ $signatories['vendor_witnessed']['phone'] }}</div>
                <div class="sig-field"><span class="lbl">Email:</span> {{ $signatories['vendor_witnessed']['email'] }}</div>
            </td>
            <td>
                <div class="sig-block-title">Emerald (supervised by)</div>
                <div class="sig-field"><span class="lbl">Name:</span> {{ $signatories['emerald_supervised']['name'] }}</div>
                <div class="sig-field"><span class="lbl">Position:</span> {{ $signatories['emerald_supervised']['position'] }}</div>
                <div class="sig-field"><span class="lbl">Sign/Date:</span> {{ $signatories['emerald_supervised']['sign_date'] }}</div>
                <div class="sig-field"><span class="lbl">Phone:</span> {{ $signatories['emerald_supervised']['phone'] }}</div>
                <div class="sig-field"><span class="lbl">Email:</span> {{ $signatories['emerald_supervised']['email'] }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
