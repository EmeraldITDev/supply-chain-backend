# Vendor Onboarding & Communication API Documentation

This document outlines the vendor invitation, onboarding, profile management, and email notification features.

## Table of Contents

1. [Email Service](#email-service)
2. [Vendor Invitation API](#vendor-invitation-api)
3. [Vendor Profile Management API](#vendor-profile-management-api)
4. [Password Reset API](#password-reset-api)
5. [Email Configuration](#email-configuration)

---

## Email Service

The system includes a centralized `EmailService` that handles all vendor-related email notifications.

### Email Types

1. **Vendor Invitation** - Sent when inviting a prospective vendor
2. **Vendor Approval** - Sent when registration is approved (includes login credentials)
3. **Password Reset** - Sent when a vendor requests password reset
4. **Document Expiry Reminder** - Sent when vendor documents are expiring
5. **RFQ Notification** - Sent when a new RFQ is assigned to a vendor
6. **Quotation Status** - Sent when quotation status changes

### Service Location

```
app/Services/EmailService.php
```

### Email Templates Location

```
resources/views/emails/
├── vendor-invitation.blade.php
├── vendor-approval.blade.php
├── password-reset.blade.php
├── document-expiry.blade.php
├── rfq-notification.blade.php
└── quotation-status.blade.php
```

---

## Vendor Invitation API

### Send Vendor Invitation

**Endpoint:** `POST /api/vendors/invite`

**Authorization:** Required (Bearer Token)

**Allowed Roles:**
- `procurement_manager`
- `supply_chain_director`
- `supply_chain`
- `executive`
- `chairman`
- `admin`

**Request Body:**

```json
{
  "email": "vendor@example.com",
  "company_name": "ABC Supplies Ltd"
}
```

**Validation Rules:**

| Field | Type | Rules |
|-------|------|-------|
| email | string | required, email, max:255 |
| company_name | string | required, max:255 |

**Success Response (200 OK):**

```json
{
  "success": true,
  "message": "Vendor invitation sent successfully",
  "data": {
    "email": "vendor@example.com",
    "companyName": "ABC Supplies Ltd",
    "sentAt": "2026-01-09T10:30:00Z",
    "sentBy": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
}
```

**Error Responses:**

**403 Forbidden - Insufficient Permissions:**
```json
{
  "success": false,
  "error": "Insufficient permissions to invite vendors",
  "code": "FORBIDDEN"
}
```

**409 Conflict - Vendor Already Exists:**
```json
{
  "success": false,
  "error": "A vendor with this email already exists",
  "code": "VENDOR_EXISTS"
}
```

**409 Conflict - Registration Pending:**
```json
{
  "success": false,
  "error": "A registration with this email is already pending",
  "code": "REGISTRATION_PENDING"
}
```

**422 Validation Error:**
```json
{
  "success": false,
  "error": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  },
  "code": "VALIDATION_ERROR"
}
```

**500 Server Error - Email Failed:**
```json
{
  "success": false,
  "error": "Failed to send invitation email",
  "code": "EMAIL_FAILED"
}
```

**Example cURL:**

```bash
curl -X POST https://api.example.com/api/vendors/invite \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "vendor@example.com",
    "company_name": "ABC Supplies Ltd"
  }'
```

---

## Vendor Profile Management API

### Get Vendor Profile

**Endpoint:** `GET /api/vendors/auth/profile`

**Authorization:** Required (Vendor Bearer Token)

**Allowed Roles:** Vendors only

**Success Response (200 OK):**

```json
{
  "success": true,
  "data": {
    "vendor": {
      "id": "V001",
      "name": "ABC Supplies Ltd",
      "email": "vendor@example.com",
      "phone": "+1234567890",
      "address": "123 Business St, City, Country",
      "contactPerson": "Jane Smith",
      "category": "Electronics",
      "status": "Active",
      "rating": 4.5,
      "totalOrders": 25,
      "taxId": "TAX123456",
      "createdAt": "2026-01-01T00:00:00Z",
      "updatedAt": "2026-01-09T10:30:00Z"
    },
    "user": {
      "id": 10,
      "name": "Jane Smith",
      "email": "vendor@example.com",
      "mustChangePassword": false
    }
  }
}
```

**Error Responses:**

**403 Forbidden:**
```json
{
  "success": false,
  "error": "Only vendors can access this endpoint",
  "code": "FORBIDDEN"
}
```

**404 Not Found:**
```json
{
  "success": false,
  "error": "Vendor profile not found",
  "code": "NOT_FOUND"
}
```

---

### Update Vendor Profile

**Endpoint:** `PUT /api/vendors/auth/profile`

**Authorization:** Required (Vendor Bearer Token)

**Allowed Roles:** Vendors only

**Request Body:**

```json
{
  "contact_person": "Jane Smith",
  "phone": "+1234567890",
  "address": "123 Updated Business St, City, Country",
  "email": "newemail@example.com"
}
```

**Validation Rules:**

| Field | Type | Rules |
|-------|------|-------|
| contact_person | string | optional, max:255 |
| phone | string | optional, max:20 |
| address | string | optional, max:500 |
| email | string | optional, email, max:255, unique |

**Notes:**
- All fields are optional - only send fields you want to update
- Updating `email` will update both vendor and user email
- Updating `contact_person` will update both vendor contact person and user name

**Success Response (200 OK):**

```json
{
  "success": true,
  "message": "Profile updated successfully",
  "data": {
    "vendor": {
      "id": "V001",
      "name": "ABC Supplies Ltd",
      "email": "newemail@example.com",
      "phone": "+1234567890",
      "address": "123 Updated Business St, City, Country",
      "contactPerson": "Jane Smith",
      "category": "Electronics",
      "status": "Active",
      "rating": 4.5,
      "updatedAt": "2026-01-09T11:00:00Z"
    }
  }
}
```

**Error Responses:**

**403 Forbidden:**
```json
{
  "success": false,
  "error": "Only vendors can access this endpoint",
  "code": "FORBIDDEN"
}
```

**404 Not Found:**
```json
{
  "success": false,
  "error": "Vendor profile not found",
  "code": "NOT_FOUND"
}
```

**422 Validation Error:**
```json
{
  "success": false,
  "error": "Validation failed",
  "errors": {
    "email": ["The email has already been taken."]
  },
  "code": "VALIDATION_ERROR"
}
```

**Example cURL:**

```bash
curl -X PUT https://api.example.com/api/vendors/auth/profile \
  -H "Authorization: Bearer VENDOR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "+1234567890",
    "address": "123 Updated Business St, City, Country"
  }'
```

---

## Password Reset API

### Request Password Reset (Public)

**Endpoint:** `POST /api/vendors/auth/password-reset`

**Authorization:** Not required (public endpoint)

**Request Body:**

```json
{
  "email": "vendor@example.com"
}
```

**Validation Rules:**

| Field | Type | Rules |
|-------|------|-------|
| email | string | required, email |

**Success Response (200 OK):**

```json
{
  "success": true,
  "message": "If the email exists, a password reset link has been sent"
}
```

**Notes:**
- This endpoint always returns success for security reasons (doesn't reveal if email exists)
- If the email exists and belongs to a vendor, a temporary password is generated and sent via email
- The vendor will be required to change the password upon next login

**Example cURL:**

```bash
curl -X POST https://api.example.com/api/vendors/auth/password-reset \
  -H "Content-Type: application/json" \
  -d '{
    "email": "vendor@example.com"
  }'
```

---

### Change Password (Authenticated)

**Endpoint:** `POST /api/vendors/auth/change-password`

**Authorization:** Required (Vendor Bearer Token)

**Request Body:**

```json
{
  "current_password": "OldPassword123!",
  "new_password": "NewSecurePassword123!",
  "new_password_confirmation": "NewSecurePassword123!"
}
```

**Validation Rules:**

| Field | Type | Rules |
|-------|------|-------|
| current_password | string | required |
| new_password | string | required, min:8, confirmed |
| new_password_confirmation | string | required, must match new_password |

**Success Response (200 OK):**

```json
{
  "success": true,
  "message": "Password changed successfully"
}
```

**Error Responses:**

**401 Unauthorized - Wrong Current Password:**
```json
{
  "success": false,
  "error": "Current password is incorrect",
  "code": "INVALID_PASSWORD"
}
```

**422 Validation Error:**
```json
{
  "success": false,
  "error": "Validation failed",
  "errors": {
    "new_password": ["The new password must be at least 8 characters."]
  },
  "code": "VALIDATION_ERROR"
}
```

**Example cURL:**

```bash
curl -X POST https://api.example.com/api/vendors/auth/change-password \
  -H "Authorization: Bearer VENDOR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "OldPassword123!",
    "new_password": "NewSecurePassword123!",
    "new_password_confirmation": "NewSecurePassword123!"
  }'
```

---

## Email Configuration

### Environment Variables

Add the following to your `.env` file:

```env
# Frontend URL (for email links)
FRONTEND_URL=https://your-frontend-url.com

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Supported Mail Services

#### 1. SMTP (Generic)
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
```

#### 2. SendGrid
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your_sendgrid_api_key
MAIL_ENCRYPTION=tls
```

#### 3. Mailgun
```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your-domain.com
MAILGUN_SECRET=your-mailgun-secret
MAILGUN_ENDPOINT=api.mailgun.net
```

#### 4. Amazon SES
```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
```

#### 5. Resend
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=your_resend_api_key
MAIL_ENCRYPTION=tls
```

### Testing Email Configuration

Use Laravel Tinker to test email configuration:

```bash
php artisan tinker
```

```php
use App\Services\EmailService;

$emailService = app(EmailService::class);
$emailService->sendVendorInvitation('test@example.com', 'Test Company');
```

### Email Logging

All email operations are logged in `storage/logs/laravel.log`:

- Successful sends: `INFO` level
- Failed sends: `ERROR` level

Example log entries:

```
[2026-01-09 10:30:00] local.INFO: Vendor invitation email sent {"email":"vendor@example.com","company":"ABC Supplies Ltd"}

[2026-01-09 10:31:00] local.ERROR: Failed to send vendor invitation email {"email":"vendor@example.com","error":"Connection timeout"}
```

---

## Integration with Existing Features

### Vendor Approval Process

When a vendor registration is approved via `POST /api/vendors/registrations/{id}/approve`, the system now:

1. Creates vendor record
2. Creates user account with temporary password
3. **Sends approval email with login credentials** (using EmailService)
4. Logs the operation

### Future Email Integrations

The EmailService is ready to be integrated with:

1. **Document Expiry Reminders** - Scheduled job to check expiring documents
2. **RFQ Notifications** - When RFQs are assigned to vendors
3. **Quotation Status Updates** - When quotations are approved/rejected

---

## Security Notes

1. **Password Reset**: Always returns success to prevent email enumeration
2. **Temporary Passwords**: Auto-generated with 12 characters (uppercase, lowercase, numbers, special chars)
3. **Force Password Change**: Users must change temporary passwords on first login
4. **Email Logging**: All email operations are logged for audit purposes
5. **Authorization**: Proper role-based access control on all endpoints

---

## API Workflow Examples

### Complete Vendor Onboarding Flow

```
1. Admin invites vendor
   POST /api/vendors/invite
   → Email sent to vendor with registration link

2. Vendor registers
   POST /api/vendors/register
   → Registration submitted for approval

3. Admin approves registration
   POST /api/vendors/registrations/{id}/approve
   → Vendor account created
   → Email sent with login credentials

4. Vendor logs in
   POST /api/vendors/auth/login
   → Must change password on first login

5. Vendor changes password
   POST /api/vendors/auth/change-password
   → Can now access system

6. Vendor updates profile
   PUT /api/vendors/auth/profile
   → Profile information updated
```

### Password Reset Flow

```
1. Vendor requests password reset
   POST /api/vendors/auth/password-reset
   → Temporary password generated and emailed

2. Vendor logs in with temporary password
   POST /api/vendors/auth/login
   → Must change password

3. Vendor changes password
   POST /api/vendors/auth/change-password
   → Can now access system normally
```

---

## Error Handling

All endpoints follow a consistent error response format:

```json
{
  "success": false,
  "error": "Human-readable error message",
  "code": "ERROR_CODE",
  "errors": {
    "field": ["Validation error messages"]
  }
}
```

### Common Error Codes

- `FORBIDDEN` - Insufficient permissions
- `NOT_FOUND` - Resource not found
- `VALIDATION_ERROR` - Invalid input data
- `VENDOR_EXISTS` - Vendor already exists
- `REGISTRATION_PENDING` - Registration already pending
- `EMAIL_FAILED` - Email delivery failed
- `INVALID_PASSWORD` - Wrong password provided

---

## Testing

### Testing Email Delivery

For development/testing, use Mailtrap or Laravel Log driver:

```env
MAIL_MAILER=log
```

This will write emails to `storage/logs/laravel.log` instead of sending them.

### API Testing with Postman/Insomnia

Import the following collection structure:

```
Vendor Onboarding
├── Send Invitation (POST /api/vendors/invite)
├── Get Profile (GET /api/vendors/auth/profile)
├── Update Profile (PUT /api/vendors/auth/profile)
├── Change Password (POST /api/vendors/auth/change-password)
└── Request Password Reset (POST /api/vendors/auth/password-reset)
```

---

## Support

For issues or questions:
- Check logs in `storage/logs/laravel.log`
- Verify email configuration in `.env`
- Ensure frontend URL is correctly set for email links
- Test email service using Laravel Tinker

---

**Last Updated:** January 9, 2026  
**Version:** 1.0
