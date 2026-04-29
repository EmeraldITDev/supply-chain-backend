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
            background-color: #8B5CF6;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background-color: #f9fafb;
            padding: 30px;
            border: 1px solid #e5e7eb;
        }
        .rfq-details {
            background-color: #fff;
            border: 2px solid #8B5CF6;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .button {
            display: inline-block;
            background-color: #8B5CF6;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .deadline {
            background-color: #FEF3C7;
            border-left: 4px solid #F59E0B;
            padding: 15px;
            margin: 20px 0;
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
    <div class="header">
        <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" alt="Emerald Industrial CFZE" style="height: 60px; margin-bottom: 10px;">
        <h1>New RFQ Assigned</h1>
    </div>

    <div class="content">
        <p>Dear {{ $companyName }},</p>

        <p>A new Request for Quotation (RFQ) has been assigned to your company.</p>

        <div class="rfq-details">
            <h3>RFQ Details:</h3>
            <p><strong>RFQ ID:</strong> {{ $rfqId }}</p>
            <p><strong>Title:</strong> {{ $rfqTitle }}</p>
        </div>

        <div class="deadline">
            <strong>⏰ Submission Deadline:</strong> {{ $deadline }}
        </div>

        <p>Please review the RFQ details and submit your quotation before the deadline.</p>

        <div style="text-align: center;">
            <a href="{{ $rfqUrl }}" class="button">View RFQ & Submit Quotation</a>
        </div>

        <h3>Important Notes:</h3>
        <ul>
            <li>Carefully review all requirements and specifications</li>
            <li>Ensure your quotation includes all requested items</li>
            <li>Submit before the deadline to be considered</li>
            <li>Contact us if you have any questions or clarifications</li>
        </ul>

        <p>We look forward to receiving your competitive quotation.</p>

        <p>Best regards,<br>
        Procurement Team<br>
        {{ config('app.name', 'Emerald Industrial CFZE') }}</p>
    </div>

    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name', 'Emerald Industrial CFZE') }}. All rights reserved.</p>
    </div>
</body>
</html>
