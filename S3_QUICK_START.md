# S3 Quick Start Guide

## Immediate Fix for "Missing Flysystem AWS S3 Class" Error

### Step 1: Install the Package

```bash
composer require league/flysystem-aws-s3-v3
```

### Step 2: Configure for Local Development (Temporary)

Until S3 is configured, the system will automatically use local storage. No action needed - just ensure `DOCUMENTS_DISK` is not set to `s3` in your `.env`:

```env
# Use local storage for now
DOCUMENTS_DISK=public
```

Or simply don't set `DOCUMENTS_DISK` at all (defaults to `public`).

### Step 3: Clear Config Cache

```bash
php artisan config:clear
```

**That's it!** Vendor registration should now work with local file storage.

---

## When Ready to Use S3

### 1. Install Package (if not already done)
```bash
composer require league/flysystem-aws-s3-v3
```

### 2. Set Up AWS S3

1. Create an S3 bucket in AWS Console
2. Create an IAM user with S3 access
3. Generate access keys for the IAM user

### 3. Update `.env` File

```env
DOCUMENTS_DISK=s3
AWS_ACCESS_KEY_ID=your_access_key_here
AWS_SECRET_ACCESS_KEY=your_secret_key_here
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

### 4. Clear Config Cache

```bash
php artisan config:clear
```

### 5. Test S3 Connection

```bash
php artisan tinker
```

```php
use Illuminate\Support\Facades\Storage;
Storage::disk('s3')->put('test.txt', 'Hello S3!');
echo "✓ S3 is working!";
Storage::disk('s3')->delete('test.txt');
```

---

## Current Status

✅ **System automatically falls back to local storage** if:
- S3 package is not installed
- AWS credentials are missing
- S3 connection fails

✅ **Files are stored in**: `storage/app/public/vendor_documents/`

✅ **Public access**: Run `php artisan storage:link` to create symlink

---

## Full Documentation

See `S3_SETUP_GUIDE.md` for complete setup instructions, troubleshooting, and best practices.
