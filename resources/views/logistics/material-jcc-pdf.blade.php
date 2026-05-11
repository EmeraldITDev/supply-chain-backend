<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Material Job Completion Certificate - {{ $jcc->reference_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            margin: 0;
            padding: 0;
            background: white;
        }

        .container {
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 15mm;
            background: white;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .company-info {
            font-size: 10px;
            color: #666;
            margin-bottom: 10px;
        }

        .company-name {
            font-weight: bold;
            font-size: 12px;
            color: #2c3e50;
        }

        .header h1 {
            margin: 10px 0 5px 0;
            color: #2c3e50;
            font-size: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .header .reference-number {
            color: #666;
            font-size: 11px;
            margin-top: 5px;
            font-style: italic;
        }

        .material-info {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 12px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .material-info .info-item {
            font-size: 10px;
        }

        .material-info .info-label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 3px;
        }

        .material-info .info-value {
            color: #333;
            word-break: break-word;
        }

        .vendor-info {
            background: #f0f8ff;
            border: 1px solid #b0d4ff;
            padding: 12px;
            margin-bottom: 20px;
        }

        .vendor-info .info-item {
            font-size: 10px;
            margin-bottom: 8px;
        }

        .vendor-info .info-item:last-child {
            margin-bottom: 0;
        }

        .vendor-info .info-label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 3px;
        }

        .vendor-info .info-value {
            color: #333;
        }

        .section-title {
            background: #f5f5f5;
            border-left: 4px solid #2c3e50;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: bold;
            color: #2c3e50;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .certification-text {
            border: 1px solid #ddd;
            padding: 12px;
            background: white;
            font-size: 10px;
            line-height: 1.6;
            margin-bottom: 15px;
            text-align: justify;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 15px;
        }

        table thead {
            background: #f0f0f0;
            border-bottom: 2px solid #333;
        }

        table th {
            padding: 8px;
            text-align: left;
            font-weight: bold;
            color: #2c3e50;
            border: 1px solid #ddd;
        }

        table td {
            padding: 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }

        table tbody tr:nth-child(even) {
            background: #f9f9f9;
        }

        .line-number {
            text-align: center;
            width: 5%;
            font-weight: bold;
        }

        .condition-good {
            background: #d4edda;
            color: #155724;
        }

        .condition-damaged {
            background: #f8d7da;
            color: #721c24;
        }

        .condition-partial {
            background: #fff3cd;
            color: #856404;
        }

        .condition-on-arrival-section {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 12px;
            margin: 15px 0;
            font-size: 10px;
        }

        .condition-options {
            margin-top: 8px;
        }

        .checkbox {
            width: 12px;
            height: 12px;
            border: 1px solid #333;
            display: inline-block;
            margin-right: 5px;
            vertical-align: middle;
        }

        .signatory-section {
            margin-top: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .signatory-box {
            text-align: center;
            border-top: 1px solid #333;
            padding-top: 20px;
            min-height: 100px;
        }

        .signatory-box .title {
            font-size: 11px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .signatory-box .name {
            font-size: 10px;
            margin: 15px 0 5px 0;
            text-decoration: underline;
            min-height: 15px;
        }

        .signatory-box .info {
            font-size: 9px;
            color: #666;
            margin-top: 3px;
        }

        .signature-line {
            height: 35px;
            border-top: 1px dashed #999;
            margin: 10px 0 5px 0;
            text-align: center;
            font-size: 8px;
            color: #999;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 9px;
            color: #666;
        }

        .footer-item {
            display: inline-block;
            margin: 0 10px;
        }

        .empty-row {
            height: 22px;
            border: 1px solid #ddd;
        }

        .remarks-cell {
            max-width: 100px;
            word-wrap: break-word;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .container {
                padding: 0;
                max-width: 100%;
            }
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <div class="company-info">
                <div class="company-name">{{ $companyName }}</div>
                @if($companyAddress)
                    <div>{{ $companyAddress }}</div>
                @endif
                @if($companyPhone || $companyEmail)
                    <div>
                        @if($companyPhone)
                            Tel: {{ $companyPhone }}
                        @endif
                        @if($companyEmail)
                            {{ $companyPhone ? ' | ' : '' }}Email: {{ $companyEmail }}
                        @endif
                    </div>
                @endif
            </div>
            <h1>Job Completion Certificate</h1>
            <h3 style="margin: 5px 0; font-size: 14px; color: #666;">Materials Delivery</h3>
            <div class="reference-number">Reference: {{ $jcc->reference_number }}</div>
        </div>

        <!-- MATERIAL INFORMATION -->
        <div class="section-title">Material & Shipment Details</div>
        <div class="material-info">
            <div class="info-item">
                <div class="info-label">Material Name</div>
                <div class="info-value">{{ $material->material_name }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Quantity</div>
                <div class="info-value">{{ $material->quantity }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Category</div>
                <div class="info-value">{{ $material->category }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Certificate Date</div>
                <div class="info-value">{{ $jcc->issued_at?->format('d/m/Y') ?? now()->format('d/m/Y') }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Pickup Location</div>
                <div class="info-value">{{ $material->pickup_location }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Delivery Location</div>
                <div class="info-value">{{ $material->destination }}</div>
            </div>
        </div>

        <!-- VENDOR INFORMATION -->
        @if($vendor || $jcc->vendor_name)
        <div class="section-title">Transporter / Vendor Details</div>
        <div class="vendor-info">
            <div class="info-item">
                <div class="info-label">Name</div>
                <div class="info-value">{{ $vendor->name ?? $jcc->vendor_name }}</div>
            </div>
            @if($material->vendor_phone || $jcc->vendor_phone)
            <div class="info-item">
                <div class="info-label">Contact</div>
                <div class="info-value">{{ $material->vendor_phone ?? $jcc->vendor_phone ?? 'N/A' }}</div>
            </div>
            @endif
            @if($jcc->po_number)
            <div class="info-item">
                <div class="info-label">PO Number</div>
                <div class="info-value">{{ $jcc->po_number }}</div>
            </div>
            @endif
        </div>
        @endif

        <!-- CERTIFICATION TEXT -->
        <div class="section-title">Certification</div>
        <div class="certification-text">
            {{ $jcc->certification_text }}
        </div>

        <!-- LINE ITEMS TABLE -->
        <div class="section-title">Delivered Items Details</div>
        <table>
            <thead>
                <tr>
                    <th class="line-number">SN</th>
                    <th style="width: 35%">Material Name</th>
                    <th style="width: 12%">Quantity</th>
                    <th style="width: 18%">Condition</th>
                    <th style="width: 35%" class="remarks-cell">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lineItems as $item)
                    <tr>
                        <td class="line-number">{{ $item->serial_number }}</td>
                        <td>{{ $item->material_name }}</td>
                        <td style="text-align: center;">{{ $item->quantity }}</td>
                        <td class="condition-{{ strtolower($item->condition ?? 'good') }}">{{ $item->condition ?? 'Good' }}</td>
                        <td class="remarks-cell">{{ $item->remarks ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="line-number">1</td>
                        <td>{{ $material->material_name }}</td>
                        <td style="text-align: center;">{{ $material->quantity }}</td>
                        <td class="condition-good">Good</td>
                        <td class="remarks-cell">-</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- CONDITION ON ARRIVAL -->
        <div class="section-title">Condition on Arrival</div>
        <div class="condition-on-arrival-section">
            <div><strong>Goods Condition:</strong></div>
            <div class="condition-options">
                <span>
                    <span class="checkbox" style="{{ $jcc->condition_on_arrival?->value === 'good' ? 'background: #155724;' : '' }}"></span>
                    Good Condition
                </span>
                &nbsp;&nbsp;&nbsp;
                <span>
                    <span class="checkbox" style="{{ $jcc->condition_on_arrival?->value === 'damaged' ? 'background: #721c24;' : '' }}"></span>
                    Damaged
                </span>
                &nbsp;&nbsp;&nbsp;
                <span>
                    <span class="checkbox" style="{{ $jcc->condition_on_arrival?->value === 'partial' ? 'background: #856404;' : '' }}"></span>
                    Partial Delivery
                </span>
            </div>
        </div>

        <!-- SIGNATORY SECTION -->
        <div class="section-title">Approvals & Sign-off</div>
        <div class="signatory-section">
            <div class="signatory-box">
                <div class="title">Issued By</div>
                <div class="name">{{ $jcc->issuedBy?->name ?? 'Name: ________________' }}</div>
                <div class="info">{{ $jcc->issuedBy?->email ?? '' }}</div>
                <div class="signature-line">Signature &amp; Date</div>
            </div>
            <div class="signatory-box">
                <div class="title">Approved By</div>
                <div class="name">{{ $jcc->approvedBy?->name ?? 'Name: ________________' }}</div>
                <div class="info">{{ $jcc->approvedBy?->email ?? '' }}</div>
                <div class="signature-line">Signature &amp; Date</div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <div class="footer-item">Reference: {{ $jcc->reference_number }}</div>
            <div class="footer-item">Generated: {{ $generatedAt }}</div>
            <div class="footer-item">© {{ $currentYear }} - Confidential</div>
        </div>
    </div>
</body>
</html>
