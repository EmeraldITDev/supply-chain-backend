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
            background-color: #EF4444;
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
        .document-list {
            background-color: #fff;
            border: 2px solid #EF4444;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .document-item {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .document-item:last-child {
            border-bottom: none;
        }
        .button {
            display: inline-block;
            background-color: #EF4444;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
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
        <h1>⚠️ Document Expiry Reminder</h1>
    </div>
    
    <div class="content">
        <p>Dear {{ $companyName }},</p>
        
        <p>This is a reminder that the following documents in your vendor profile are expiring soon or have already expired:</p>
        
        <div class="document-list">
            @foreach($documents as $document)
            <div class="document-item">
                <strong>{{ $document['name'] }}</strong><br>
                <span style="color: #EF4444;">Expires: {{ $document['expiryDate'] }}</span>
            </div>
            @endforeach
        </div>
        
        <p><strong>Action Required:</strong> Please update these documents as soon as possible to maintain your vendor status and continue receiving business opportunities.</p>
        
        <div style="text-align: center;">
            <a href="{{ $portalUrl }}" class="button">Update Documents</a>
        </div>
        
        <p>Failure to update expired documents may result in suspension of your vendor account.</p>
        
        <p>If you have any questions, please contact our vendor support team.</p>
        
        <p>Best regards,<br>
        Supply Chain Management Team</p>
    </div>
    
    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
        <p>&copy; {{ date('Y') }} Supply Chain Management System. All rights reserved.</p>
    </div>
</body>
</html>
