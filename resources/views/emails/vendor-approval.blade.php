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
            background-color: #10B981;
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
        .credentials {
            background-color: #fff;
            border: 2px solid #10B981;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .credentials strong {
            color: #10B981;
        }
        .button {
            display: inline-block;
            background-color: #10B981;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .warning {
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
        <h1>✓ Vendor Registration Approved</h1>
    </div>

    <div class="content">
        <p>Dear {{ $companyName }},</p>

        <p>Congratulations! Your vendor registration has been approved. Welcome to Emerald Industrial CFZE's vendor network!</p>

        <h3>Your Login Credentials:</h3>
        <div class="credentials">
            <p><strong>Email:</strong> {{ $email }}</p>
            <p><strong>Temporary Password:</strong> <code>{{ $temporaryPassword }}</code></p>
        </div>

        <div class="warning">
            <strong>⚠️ Important:</strong> For security reasons, you will be required to change your password upon first login.
        </div>

        <div style="text-align: center;">
            <a href="{{ $loginUrl }}" class="button">Login to Vendor Portal</a>
        </div>

        <h3>What's Next?</h3>
        <ol>
            <li>Click the login button above</li>
            <li>Enter your email and temporary password</li>
            <li>Set your new secure password</li>
            <li>Complete your vendor profile</li>
            <li>Start receiving RFQs and submitting quotations</li>
        </ol>

        <p>If you encounter any issues logging in, please contact our support team.</p>

        <p>We look forward to a successful partnership!</p>

        <p>Best regards,<br>
        Emerald Industrial CFZE Team</p>
    </div>

    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
        <p>Keep your credentials secure and do not share them with anyone.</p>
        <p>&copy; {{ date('Y') }} Emerald Industrial CFZE. All rights reserved.</p>
    </div>
</body>
</html>
