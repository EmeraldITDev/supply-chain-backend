# Email Service Setup Guide

This guide will help you configure email services for the Supply Chain Management system.

## Quick Start

### 1. Choose Your Email Provider

Select one of the following email service providers:

- **Mailtrap** (Recommended for Development/Testing)
- **SendGrid** (Recommended for Production)
- **Resend** (Modern alternative)
- **Amazon SES** (High volume)
- **Mailgun** (Reliable and popular)
- **SMTP** (Any generic SMTP server)

### 2. Update Environment Variables

Copy the appropriate configuration from the examples below to your `.env` file.

---

## Email Provider Configurations

### Mailtrap (Development/Testing)

**Best for:** Testing email delivery without sending real emails

1. Sign up at [https://mailtrap.io](https://mailtrap.io)
2. Create an inbox
3. Copy the SMTP credentials
4. Add to `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@scm.com
MAIL_FROM_NAME="${APP_NAME}"

# Frontend URL for email links
FRONTEND_URL=http://localhost:3000
```

**Pros:**
- ✅ Free tier available
- ✅ No real emails sent (safe for testing)
- ✅ Beautiful email preview interface
- ✅ Email HTML/text inspection
- ✅ Spam score analysis

**Cons:**
- ❌ Not for production use
- ❌ Emails not actually delivered

---

### SendGrid (Production)

**Best for:** Production environments, reliable delivery

1. Sign up at [https://sendgrid.com](https://sendgrid.com)
2. Verify your sender domain (recommended) or use Single Sender Verification
3. Create an API key with "Mail Send" permissions
4. Add to `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your_sendgrid_api_key_here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

# Frontend URL for email links
FRONTEND_URL=https://your-frontend-domain.com
```

**Setup Steps:**

1. **Create SendGrid Account**
   - Sign up for free (100 emails/day free forever)
   - Or choose a paid plan for higher volume

2. **Verify Sender Identity**
   
   **Option A: Single Sender Verification (Quick)**
   - Go to Settings → Sender Authentication → Single Sender Verification
   - Add your email address
   - Verify via email confirmation
   
   **Option B: Domain Authentication (Recommended for Production)**
   - Go to Settings → Sender Authentication → Authenticate Your Domain
   - Add your domain
   - Add DNS records provided by SendGrid
   - Wait for DNS propagation (can take up to 48 hours)

3. **Create API Key**
   - Go to Settings → API Keys
   - Click "Create API Key"
   - Name it (e.g., "SCM Production")
   - Choose "Restricted Access"
   - Enable only "Mail Send" permission
   - Click "Create & View"
   - **Copy the API key immediately** (you won't see it again)

4. **Test Configuration**
   ```bash
   php artisan tinker
   ```
   ```php
   use App\Services\EmailService;
   $emailService = app(EmailService::class);
   $emailService->sendVendorInvitation('your-test-email@example.com', 'Test Company');
   ```

**Pros:**
- ✅ Free tier (100 emails/day)
- ✅ Excellent deliverability
- ✅ Detailed analytics
- ✅ Easy setup
- ✅ Good documentation

**Cons:**
- ❌ Requires sender verification
- ❌ Free tier limited to 100 emails/day

**Pricing:**
- Free: 100 emails/day forever
- Essentials: $19.95/month (40,000 emails/month)
- Pro: $89.95/month (150,000 emails/month)

---

### Resend (Modern Alternative)

**Best for:** Modern API, developer-friendly

1. Sign up at [https://resend.com](https://resend.com)
2. Verify your domain
3. Create an API key
4. Add to `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=re_your_api_key_here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

FRONTEND_URL=https://your-frontend-domain.com
```

**Setup Steps:**

1. Sign up at [resend.com](https://resend.com)
2. Add and verify your domain
3. Create an API key
4. Use API key as password with username "resend"

**Pros:**
- ✅ Modern, developer-friendly API
- ✅ Simple setup
- ✅ Good free tier (100 emails/day)
- ✅ Beautiful dashboard

**Cons:**
- ❌ Relatively new service
- ❌ Smaller community

**Pricing:**
- Free: 3,000 emails/month
- Pro: $20/month (50,000 emails/month)

---

### Amazon SES

**Best for:** High volume, cost-effective at scale

1. Sign up for AWS account
2. Verify your domain or email in SES console
3. Request production access (starts in sandbox mode)
4. Create IAM user with SES permissions
5. Add to `.env`:

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

FRONTEND_URL=https://your-frontend-domain.com
```

**Setup Steps:**

1. **Verify Domain/Email**
   - Go to AWS SES Console
   - Choose "Verified identities"
   - Add your domain or email
   - Complete verification

2. **Request Production Access**
   - By default, SES starts in sandbox mode
   - Submit a request to move to production
   - Provide use case details

3. **Create IAM User**
   - Go to IAM Console
   - Create new user
   - Attach policy: `AmazonSESFullAccess` or create custom policy
   - Generate access key

4. **Configure Laravel**
   - Install AWS SDK: `composer require aws/aws-sdk-php`
   - Update `.env` as shown above

**Pros:**
- ✅ Extremely cost-effective at scale
- ✅ Highly reliable
- ✅ Scales automatically
- ✅ Integrates with other AWS services

**Cons:**
- ❌ Complex setup
- ❌ Starts in sandbox mode (limited)
- ❌ Requires AWS account

**Pricing:**
- First 62,000 emails/month: Free (if sent from EC2)
- $0.10 per 1,000 emails thereafter

---

### Mailgun

**Best for:** Reliable, widely used

1. Sign up at [https://mailgun.com](https://mailgun.com)
2. Verify your domain
3. Get your API key and domain
4. Add to `.env`:

```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.yourdomain.com
MAILGUN_SECRET=your_mailgun_api_key
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS=noreply@mg.yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

FRONTEND_URL=https://your-frontend-domain.com
```

**Pros:**
- ✅ Very reliable
- ✅ Good free tier
- ✅ Excellent documentation
- ✅ Powerful API

**Cons:**
- ❌ Domain verification required
- ❌ Setup can be complex

**Pricing:**
- Free: 5,000 emails/month for 3 months
- Foundation: $35/month (50,000 emails)

---

### Generic SMTP

**Best for:** Using your own mail server

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourserver.com
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

FRONTEND_URL=https://your-frontend-domain.com
```

---

## Testing Email Configuration

### Method 1: Laravel Tinker

```bash
php artisan tinker
```

Then run:

```php
use App\Services\EmailService;

$emailService = app(EmailService::class);

// Test vendor invitation
$emailService->sendVendorInvitation('test@example.com', 'Test Company');

// Test vendor approval
$emailService->sendVendorApprovalEmail('test@example.com', 'Test Company', 'TempPass123!');

// Test password reset
$emailService->sendPasswordResetEmail('test@example.com', 'John Doe', 'TempPass123!');
```

### Method 2: Log Driver (Development)

To test without sending real emails, use the log driver:

```env
MAIL_MAILER=log
```

Emails will be written to `storage/logs/laravel.log`

### Method 3: Test Route (Create Temporarily)

Add to `routes/web.php` (for testing only):

```php
Route::get('/test-email', function () {
    $emailService = app(\App\Services\EmailService::class);
    $emailService->sendVendorInvitation('test@example.com', 'Test Company');
    return 'Email sent! Check your inbox.';
});
```

Visit: `http://your-domain.com/test-email`

**⚠️ Remember to remove this route after testing!**

---

## Troubleshooting

### Problem: Emails not being sent

**Check 1: Verify .env configuration**
```bash
php artisan config:clear
php artisan cache:clear
```

**Check 2: Check Laravel logs**
```bash
tail -f storage/logs/laravel.log
```

**Check 3: Verify email credentials**
- Try logging into your email provider's dashboard
- Check if API key is valid
- Verify sender domain is verified

**Check 4: Test connection**
```bash
php artisan tinker
```
```php
Mail::raw('Test email', function($msg) {
    $msg->to('test@example.com')->subject('Test');
});
```

### Problem: Emails going to spam

**Solutions:**
1. **Verify your domain** with your email provider
2. **Add SPF record** to your domain's DNS:
   ```
   v=spf1 include:_spf.sendgrid.net ~all
   ```
   (Adjust for your provider)

3. **Add DKIM record** (provided by your email service)

4. **Set up DMARC record**:
   ```
   _dmarc.yourdomain.com TXT "v=DMARC1; p=none; rua=mailto:dmarc@yourdomain.com"
   ```

5. **Use a professional "from" address** (not gmail, yahoo, etc.)

### Problem: Rate limiting

Most email services have rate limits:

- **SendGrid Free:** 100/day
- **Resend Free:** 100/day
- **Mailgun Free:** 5,000/month for 3 months

**Solutions:**
- Upgrade to paid plan
- Implement queue system (see below)
- Use multiple providers with fallback

---

## Advanced Configuration

### Queue Email Delivery

For better performance, queue email jobs:

1. **Update .env:**
```env
QUEUE_CONNECTION=database
```

2. **Create queue table:**
```bash
php artisan queue:table
php artisan migrate
```

3. **Update EmailService to use queues:**

```php
Mail::to($email)->queue(new VendorInvitationMail($data));
```

4. **Run queue worker:**
```bash
php artisan queue:work
```

### Email Templates Customization

Email templates are located in:
```
resources/views/emails/
```

You can customize:
- Colors (change hex codes)
- Logo (add your company logo)
- Footer text
- Button styles
- Layout

Example customization in `vendor-invitation.blade.php`:

```php
<style>
    .header {
        background-color: #YOUR_BRAND_COLOR; /* Change this */
        color: white;
        padding: 20px;
    }
    .button {
        background-color: #YOUR_BRAND_COLOR; /* Change this */
        color: white;
        padding: 12px 30px;
    }
</style>
```

### Multiple Email Providers (Failover)

For high availability, implement failover:

```php
try {
    Mail::to($email)->send($mail);
} catch (\Exception $e) {
    // Try backup provider
    config(['mail.default' => 'backup']);
    Mail::to($email)->send($mail);
}
```

---

## Production Checklist

Before going to production:

- [ ] Email provider account created and verified
- [ ] Sender domain authenticated
- [ ] API keys/credentials added to `.env`
- [ ] MAIL_FROM_ADDRESS set to your domain
- [ ] FRONTEND_URL set to production URL
- [ ] SPF record added to DNS
- [ ] DKIM record added to DNS
- [ ] Test emails delivered successfully
- [ ] Emails not going to spam
- [ ] Queue system configured (optional but recommended)
- [ ] Error logging configured
- [ ] Rate limits understood
- [ ] Backup email provider configured (optional)

---

## Monitoring and Logging

### View Email Logs

All email operations are logged:

```bash
tail -f storage/logs/laravel.log | grep "email"
```

### Success logs:
```
[2026-01-09 10:30:00] local.INFO: Vendor invitation email sent {"email":"vendor@example.com","company":"ABC Ltd"}
```

### Error logs:
```
[2026-01-09 10:31:00] local.ERROR: Failed to send vendor invitation email {"email":"vendor@example.com","error":"Connection timeout"}
```

### Monitor Email Provider Dashboard

Most providers offer dashboards with:
- Delivery rates
- Bounce rates
- Spam complaints
- Open rates (if tracking enabled)

---

## Email Service Comparison

| Provider | Free Tier | Price/Month | Best For | Setup Difficulty |
|----------|-----------|-------------|----------|------------------|
| Mailtrap | Unlimited (test only) | $10+ | Development | ⭐ Easy |
| SendGrid | 100/day | $20+ | Production | ⭐⭐ Medium |
| Resend | 3,000/month | $20+ | Modern API | ⭐ Easy |
| Amazon SES | 62,000/month | $0.10/1000 | High Volume | ⭐⭐⭐ Hard |
| Mailgun | 5,000/month (3mo) | $35+ | Reliability | ⭐⭐ Medium |

---

## Recommendation

### For Development:
✅ **Mailtrap** or **Log Driver**

### For Small-Medium Production:
✅ **SendGrid** (reliable, good free tier) or **Resend** (modern, simple)

### For Large Production:
✅ **Amazon SES** (cost-effective at scale) or **SendGrid Pro**

---

## Support Resources

- **Laravel Mail Documentation:** [https://laravel.com/docs/mail](https://laravel.com/docs/mail)
- **SendGrid Docs:** [https://docs.sendgrid.com](https://docs.sendgrid.com)
- **Resend Docs:** [https://resend.com/docs](https://resend.com/docs)
- **AWS SES Docs:** [https://docs.aws.amazon.com/ses/](https://docs.aws.amazon.com/ses/)

---

**Last Updated:** January 9, 2026  
**Version:** 1.0
