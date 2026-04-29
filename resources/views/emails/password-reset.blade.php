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
            background-color: #F59E0B;
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
            border: 2px solid #F59E0B;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .button {
            display: inline-block;
            background-color: #F59E0B;
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
        <h1>Password Reset</h1>
    </div>

    <div class="content">
        <p>Dear {{ $name }},</p>

        <p>Your password has been reset as requested.</p>

        <h3>Your New Temporary Password:</h3>
        <div class="credentials">
            <p><strong>Temporary Password:</strong> <code>{{ $temporaryPassword }}</code></p>
        </div>

        <p>For security reasons, you will be required to change this password upon your next login.</p>

        <div style="text-align: center;">
            <a href="{{ $loginUrl }}" class="button">Login Now</a>
        </div>

        <p><strong>Security Notice:</strong> If you did not request this password reset, please contact our support team immediately.</p>

        <p>Best regards,<br>
        Emerald Industrial CFZE Team</p>
    </div>

    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
        <p>&copy; {{ date('Y') }} Emerald Industrial CFZE. All rights reserved.</p>
    </div>
</body>
</html>
