<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Job Completion Certificate - {{ $jcc->reference_number }}</title>
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

        .header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .header .reference-number {
            color: #666;
            font-size: 11px;
            margin-top: 5px;
            font-style: italic;
        }

        .trip-info {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 12px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .trip-info .info-item {
            font-size: 10px;
        }

        .trip-info .info-label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 3px;
        }

        .trip-info .info-value {
            color: #333;
            word-break: break-word;
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

        .condition-fair {
            background: #fff3cd;
            color: #856404;
        }

        .condition-damaged {
            background: #f8d7da;
            color: #721c24;
        }

        .condition-lost {
            background: #f8d7da;
            color: #721c24;
        }

        .delivery-section {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 12px;
            margin: 15px 0;
        }

        .delivery-row {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 15px;
            margin-bottom: 10px;
        }

        .delivery-row:last-child {
            margin-bottom: 0;
        }

        .checkbox {
            width: 15px;
            height: 15px;
            border: 1px solid #333;
            display: inline-block;
            margin-right: 5px;
        }

        .condition-text {
            border: 1px solid #ddd;
            padding: 8px;
            min-height: 50px;
            background: white;
            font-size: 10px;
            line-height: 1.4;
        }

        .signatory-section {
            margin-top: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .signatory-box {
            text-align: center;
            border-top: 1px solid #333;
            padding-top: 20px;
            min-height: 80px;
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
        }

        .signatory-box .info {
            font-size: 9px;
            color: #666;
        }

        .signature-line {
            height: 40px;
            border-top: 1px dashed #999;
            margin: 10px 0;
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

        .empty-rows {
            height: 25px;
            border: 1px solid #ddd;
        }

        .remarks-cell {
            max-width: 120px;
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
            <h1>Job Completion Certificate</h1>
            <div class="reference-number">{{ $jcc->reference_number }}</div>
        </div>

        <!-- TRIP INFORMATION -->
        <div class="trip-info">
            <div class="info-item">
                <div class="info-label">Trip Code</div>
                <div class="info-value">{{ $trip->trip_code }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Certificate Date</div>
                <div class="info-value">{{ $jcc->issued_at?->format('Y-m-d') ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Trip Title</div>
                <div class="info-value">{{ $trip->title }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Vendor</div>
                <div class="info-value">{{ $vendor->name ?? 'N/A' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Origin</div>
                <div class="info-value">{{ $trip->origin }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Destination</div>
                <div class="info-value">{{ $trip->destination }}</div>
            </div>
        </div>

        <!-- LINE ITEMS TABLE -->
        <div class="section-title">Vehicle / Service Details</div>
        <table>
            <thead>
                <tr>
                    <th class="line-number">No.</th>
                    <th style="width: 25%">Description</th>
                    <th style="width: 12%">Type</th>
                    <th style="width: 18%">Reference</th>
                    <th style="width: 15%">Condition</th>
                    <th style="width: 30%" class="remarks-cell">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lineItems as $item)
                    <tr>
                        <td class="line-number">{{ $item->line_number }}</td>
                        <td>{{ $item->description }}</td>
                        <td>{{ $item->getItemTypeLabel() }}</td>
                        <td>{{ $item->reference_number ?? '-' }}</td>
                        <td class="condition-{{ $item->condition ?? 'good' }}">
                            {{ $item->getConditionLabel() ?? 'Good' }}
                        </td>
                        <td class="remarks-cell">{{ $item->remarks ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px; color: #999;">
                            No line items added
                        </td>
                    </tr>
                @endforelse

                @if($lineItems->count() < 5)
                    @for($i = $lineItems->count(); $i < 5; $i++)
                        <tr>
                            <td colspan="6" class="empty-rows"></td>
                        </tr>
                    @endfor
                @endif
            </tbody>
        </table>

        <!-- DELIVERY CONFIRMATION -->
        <div class="section-title">Delivery Confirmation</div>
        <div class="delivery-section">
            <div class="delivery-row">
                <div>
                    <span class="checkbox" style="{{ $jcc->delivery_confirmed ? 'background: #2c3e50;' : '' }}"></span>
                    Yes - Delivered
                </div>
                <div>
                    <span class="checkbox" style="{{ !$jcc->delivery_confirmed ? 'background: #2c3e50;' : '' }}"></span>
                    No - Not Delivered / Partial
                </div>
            </div>
            <div style="margin-top: 10px;">
                <strong style="font-size: 10px;">Remarks:</strong>
                <div class="condition-text">{{ $jcc->remarks ?? '' }}</div>
            </div>
        </div>

        <!-- CONDITION OF GOODS -->
        <div class="section-title">Condition of Goods / Materials</div>
        <div class="condition-text">{{ $jcc->condition_of_goods ?? '' }}</div>

        <!-- SIGNATORY SECTION -->
        <div class="signatory-section">
            <!-- Issued By -->
            <div class="signatory-box">
                <div class="title">Issued By</div>
                <div class="signature-line">_____________________</div>
                <div class="name">{{ $jcc->issuedBy?->name ?? '_____________________' }}</div>
                <div class="info">{{ now()->format('Y-m-d') }}</div>
            </div>

            <!-- Approved By -->
            <div class="signatory-box">
                <div class="title">Approved By</div>
                <div class="signature-line">_____________________</div>
                <div class="name">{{ $jcc->approvedBy?->name ?? '_____________________' }}</div>
                <div class="info">{{ $jcc->approved_at?->format('Y-m-d') ?? '___________' }}</div>
            </div>

            <!-- Witness (Optional) -->
            <div class="signatory-box">
                <div class="title">Witness (Optional)</div>
                <div class="signature-line">_____________________</div>
                <div class="name">_____________________</div>
                <div class="info">___________</div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <div class="footer-item">
                <strong>Reference:</strong> {{ $jcc->reference_number }}
            </div>
            <div class="footer-item">
                <strong>Printed:</strong> {{ $generatedAt }}
            </div>
        </div>
    </div>
</body>
</html>
