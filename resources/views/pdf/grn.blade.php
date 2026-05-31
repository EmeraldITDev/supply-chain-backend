<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Goods Received Note - {{ $grn_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #000;
            padding: 18px 22px 28px;
        }
        .header { margin-bottom: 16px; }
        .title {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 8px;
        }
        .meta { font-size: 9.5px; margin-bottom: 4px; }
        .meta strong { font-weight: bold; }
        .pair-grid {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 16px;
        }
        .pair-grid td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #000;
            padding: 10px 12px;
        }
        .pair-label {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 9.5px;
        }
        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: left;
        }
        .items-table th { background: #f0f0f0; font-weight: bold; }
        .col-num { width: 6%; text-align: center; }
        .col-qty { width: 12%; text-align: center; }
        .col-unit { width: 12%; text-align: center; }
        .notes-block {
            margin-top: 14px;
            font-size: 9.5px;
        }
        .notes-block .lbl {
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .signature-row {
            margin-top: 28px;
            width: 100%;
        }
        .signature-row td {
            width: 50%;
            vertical-align: top;
            padding-top: 24px;
        }
        .sig-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 4px;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Goods Received Note (GRN)</div>
        <div class="meta"><strong>GRN No:</strong> {{ $grn_number }}</div>
        <div class="meta"><strong>Date:</strong> {{ $grn_date }}</div>
        @if (!empty($po_number))
            <div class="meta"><strong>PO No:</strong> {{ $po_number }}</div>
        @endif
        @if (!empty($mrf_reference))
            <div class="meta"><strong>MRF:</strong> {{ $mrf_reference }}</div>
        @endif
    </div>

    <table class="pair-grid">
        <tr>
            <td>
                <div class="pair-label">Supplier</div>
                <div>{{ $supplier_name }}</div>
                @if (!empty($supplier_address))
                    <div style="margin-top:4px; white-space:pre-wrap;">{{ $supplier_address }}</div>
                @endif
            </td>
            <td>
                <div class="pair-label">Received At</div>
                <div>{{ $received_at }}</div>
                @if (!empty($department))
                    <div style="margin-top:6px;"><strong>Department:</strong> {{ $department }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-num">#</th>
                <th>Description</th>
                <th class="col-qty">Qty</th>
                <th class="col-unit">Unit</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($line_items as $index => $line)
                <tr>
                    <td class="col-num">{{ $index + 1 }}</td>
                    <td>
                        <strong>{{ $line['name'] }}</strong>
                        @if (!empty($line['description']))
                            <div style="margin-top:2px;">{{ $line['description'] }}</div>
                        @endif
                    </td>
                    <td class="col-qty">{{ $line['quantity'] }}</td>
                    <td class="col-unit">{{ $line['unit'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (!empty($remarks))
        <div class="notes-block">
            <div class="lbl">Remarks</div>
            <div>{!! nl2br(e($remarks)) !!}</div>
        </div>
    @endif

    <table class="signature-row">
        <tr>
            <td>
                <div class="sig-line">Received By (Procurement)</div>
            </td>
            <td>
                <div class="sig-line">Verified By</div>
            </td>
        </tr>
    </table>
</body>
</html>
