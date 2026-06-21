<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #10B981; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .credentials { background-color: #fff; border: 2px solid #10B981; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .button { display: inline-block; background-color: #10B981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .warning { background-color: #FEF3C7; border-left: 4px solid #F59E0B; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Welcome to the Vendor Portal</h1>
    </div>
    <div class="content">
        <p>Dear {{ $companyName }},</p>
        <p>You have been registered as a supplier on Emerald Industrial CFZE's Supply Chain platform following a purchase order. Your Vendor Portal account is ready.</p>
        <h3>Your login credentials</h3>
        <div class="credentials">
            <p><strong>Email:</strong> {{ $email }}</p>
            <p><strong>Temporary password:</strong> <code>{{ $temporaryPassword }}</code></p>
        </div>
        <div class="warning">
            <strong>Important:</strong> You must change your password on first login and complete your company profile (category, address, tax ID, website, and other business details).
        </div>
        <div style="text-align: center;">
            <a href="{{ $loginUrl }}" class="button">Open Vendor Portal</a>
        </div>
        <p>If you need assistance, contact the procurement team.</p>
        <p>Best regards,<br>{{ config('app.name', 'Emerald Industrial CFZE') }} Team</p>
    </div>
    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
    </div>
</body>
</html>
