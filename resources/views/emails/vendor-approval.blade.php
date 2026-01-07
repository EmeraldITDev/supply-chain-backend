<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome to Emerald - Vendor Partnership</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 30px; border-radius: 5px; margin-bottom: 20px; text-align: center;">
        <h1 style="color: #ffffff; margin-top: 0; font-size: 28px;">🎉 Congratulations!</h1>
        <p style="color: #ffffff; font-size: 18px; margin: 10px 0 0 0;">Welcome to Emerald Partnership</p>
    </div>

    <div style="background-color: #fff; padding: 30px; border-radius: 5px; border: 1px solid #ddd;">
        <p style="font-size: 16px;">Dear {{ $registration->contact_person }},</p>

        <p>We are thrilled to inform you that <strong>{{ $registration->company_name }}</strong> has been approved as an official vendor partner with <strong>Emerald</strong>!</p>

        <p>This partnership represents an exciting opportunity for us to work together and achieve mutual success. We look forward to a long and prosperous collaboration.</p>

        <div style="background-color: #ecfdf5; padding: 20px; border-radius: 5px; margin: 25px 0; border-left: 4px solid #10b981;">
            <h2 style="color: #059669; margin-top: 0; font-size: 20px;">Your Vendor Portal Access</h2>
            <p style="margin: 15px 0 10px 0;">You can now access the Emerald Vendor Portal using the following credentials:</p>
            
            <div style="background-color: #fff; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <p style="margin: 8px 0; font-size: 15px;"><strong>Portal URL:</strong> <a href="{{ $vendorPortalUrl }}" style="color: #059669; text-decoration: none; font-weight: bold;">{{ $vendorPortalUrl }}</a></p>
                <p style="margin: 8px 0; font-size: 15px;"><strong>Email:</strong> <span style="color: #333;">{{ $registration->email }}</span></p>
                <p style="margin: 8px 0; font-size: 15px;"><strong>Temporary Password:</strong> <code style="background-color: #f3f4f6; padding: 4px 8px; border-radius: 3px; font-size: 16px; font-weight: bold; color: #059669; letter-spacing: 1px;">{{ $temporaryPassword }}</code></p>
            </div>
        </div>

        <div style="background-color: #fef3c7; padding: 20px; border-radius: 5px; margin: 25px 0; border-left: 4px solid #f59e0b;">
            <p style="margin: 0; font-size: 15px;"><strong>⚠️ Important Security Notice:</strong></p>
            <p style="margin: 10px 0 0 0;">For your account security, you <strong>must</strong> change your temporary password immediately upon your first login. The system will prompt you to set a new, secure password.</p>
        </div>

        <div style="background-color: #eff6ff; padding: 20px; border-radius: 5px; margin: 25px 0; border-left: 4px solid #3b82f6;">
            <h3 style="color: #1e40af; margin-top: 0; font-size: 18px;">What's Next?</h3>
            <ol style="margin: 10px 0; padding-left: 20px;">
                <li style="margin: 8px 0;">Log in to the Vendor Portal using the credentials above</li>
                <li style="margin: 8px 0;">Change your temporary password to a secure password of your choice</li>
                <li style="margin: 8px 0;">Explore the portal to view RFQs, submit quotations, and manage your vendor account</li>
                <li style="margin: 8px 0;">Stay updated on new opportunities and partnership announcements</li>
            </ol>
        </div>

        <p>If you have any questions, need assistance accessing the portal, or require support, please don't hesitate to contact our Supply Chain Management team. We're here to help ensure a smooth onboarding experience.</p>

        <p style="margin-top: 30px;">Once again, welcome to the Emerald family! We're excited to embark on this journey together.</p>

        <p style="margin-top: 25px;">Best regards,<br>
        <strong>Emerald Supply Chain Management Team</strong><br>
        <span style="color: #6b7280; font-size: 14px;">Partnership & Vendor Relations</span></p>
    </div>

    <div style="text-align: center; margin-top: 20px; padding: 15px; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb;">
        <p style="margin: 5px 0;">This is an automated message from Emerald's Supply Chain Management System.</p>
        <p style="margin: 5px 0;">Please do not reply to this email. For inquiries, contact your account manager.</p>
    </div>
</body>
</html>

