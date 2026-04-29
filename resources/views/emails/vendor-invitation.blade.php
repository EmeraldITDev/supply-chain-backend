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
            background-color: #4F46E5;
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
        .button {
            display: inline-block;
            background-color: #4F46E5;
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
        <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" alt="Emerald Industrial CFZE" style="height: 60px; margin-bottom: 10px;">
        <h1>Vendor Registration Invitation</h1>
    </div>

    <div class="content">
        <p>Dear {{ $companyName }},</p>

        <p>You have been invited to register as a vendor in Emerald Industrial CFZE's procurement system.</p>

        <p>We value potential partnerships with quality vendors and would like to invite you to join our vendor network.</p>

        <h3>Next Steps:</h3>
        <ol>
            <li>Click the registration button below</li>
            <li>Complete the vendor registration form</li>
            <li>Upload required documents (business license, tax certificates, etc.)</li>
            <li>Submit your registration for approval</li>
        </ol>

        <div style="text-align: center;">
            <a href="{{ $registrationUrl }}" class="button">Register Now</a>
        </div>

        <p>Once your registration is approved, you will receive login credentials to access the vendor portal.</p>

        <p>If you have any questions, please contact our procurement team.</p>

        <p>Best regards,<br>
        Emerald Industrial CFZE Team</p>
    </div>

    <div class="footer">
        <p>This is an automated email. Please do not reply.</p>
        <p>&copy; {{ date('Y') }} Emerald Industrial CFZE. All rights reserved.</p>
    </div>
</body>
</html>
