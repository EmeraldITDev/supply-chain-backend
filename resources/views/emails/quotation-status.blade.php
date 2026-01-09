<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #3B82F6;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .header.approved {
            background-color: #10B981;
        }
        .header.rejected {
            background-color: #EF4444;
        }
        .content {
            background-color: #f9fafb;
            padding: 30px;
            border: 1px solid #e5e7eb;
        }
        .status-box {
            background-color: #fff;
            border: 2px solid #3B82F6;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .status-box.approved {
            border-color: #10B981;
        }
        .status-box.rejected {
            border-color: #EF4444;
        }
        .button {
            display: inline-block;
            background-color: #3B82F6;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .remarks {
            background-color: #F3F4F6;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header {{ strtolower($status) }}">
        <h1>Quotation Status Update</h1>
    </div>
    
    <div class="content">
        <p>Dear {{ $companyName }},</p>
        
        <p>The status of your quotation has been updated.</p>
        
        <div class="status-box {{ strtolower($status) }}">
            <h3>Quotation Details:</h3>
            <p><strong>Quotation ID:</strong> {{ $quotationId }}</p>
            <p><strong>Status:</strong> <span style="text-transform: uppercase; font-weight: bold; color: {{ $status === 'Approved' ? '#10B981' : ($status === 'Rejected' ? '#EF4444' : '#3B82F6') }}">{{ $status }}</span></p>
        </div>
        
        @if($remarks)
        <div class="remarks">
            <strong>Remarks:</strong><br>
            {{ $remarks }}
        </div>
        @endif
        
        <div style="text-align: center;">
            <a href="{{ $portalUrl }}" class="button">View Quotation Details</a>
        </div>
        
        @if($status === 'Approved')
        <p>Congratulations! Your quotation has been approved. Our procurement team will contact you with the next steps for order processing.</p>
        @elseif($status === 'Rejected')
        <p>Unfortunately, your quotation was not selected for this RFQ. We appreciate your participation and encourage you to submit quotations for future opportunities.</p>
        @else
        <p>Your quotation is currently being reviewed. We will notify you once a final decision has been made.</p>
        @endif
        
        <p>Thank you for your participation in our procurement process.</p>
        
        <p>Best regards,<br>
        Procurement Team<br>
        Supply Chain Management</p>
    </div>
    
    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
        <p>&copy; {{ date('Y') }} Supply Chain Management System. All rights reserved.</p>
    </div>
</body>
</html>
