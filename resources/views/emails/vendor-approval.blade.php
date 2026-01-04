<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vendor Registration Approved</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
        <h1 style="color: #2c3e50; margin-top: 0;">Vendor Registration Approved</h1>
    </div>

    <div style="background-color: #fff; padding: 20px; border-radius: 5px; border: 1px solid #ddd;">
        <p>Dear {{ $registration->contact_person }},</p>

        <p>We are pleased to inform you that your vendor registration for <strong>{{ $registration->company_name }}</strong> has been approved.</p>

        <p>You can now access the vendor portal using the following credentials:</p>

        <div style="background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3498db;">
            <p style="margin: 5px 0;"><strong>Email:</strong> {{ $registration->email }}</p>
            <p style="margin: 5px 0;"><strong>Temporary Password:</strong> <code style="background-color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 14px;">{{ $temporaryPassword }}</code></p>
        </div>

        <div style="background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ffc107;">
            <p style="margin: 0;"><strong>Important:</strong> For security reasons, you will be required to change your password on your first login.</p>
        </div>

        <p>Please log in to the vendor portal and update your password as soon as possible.</p>

        <p>If you have any questions or need assistance, please contact our support team.</p>

        <p>Best regards,<br>
        Supply Chain Management Team</p>
    </div>

    <div style="text-align: center; margin-top: 20px; padding: 10px; color: #777; font-size: 12px;">
        <p>This is an automated message. Please do not reply to this email.</p>
    </div>
</body>
</html>

