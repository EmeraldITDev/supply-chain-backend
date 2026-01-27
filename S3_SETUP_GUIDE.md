# AWS S3 Storage Setup Guide

This guide explains how to configure AWS S3 for persistent file storage in the Supply Chain Management backend.

## Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [AWS Configuration](#aws-configuration)
4. [Environment Variables](#environment-variables)
5. [Testing S3 Connection](#testing-s3-connection)
6. [Local Development (Fallback)](#local-development-fallback)
7. [Troubleshooting](#troubleshooting)
8. [Migration from Local to S3](#migration-from-local-to-s3)

---

## Overview

The backend uses Laravel's Storage facade with Flysystem to support multiple storage backends:
- **S3**: Production storage (persistent, scalable, secure)
- **Public/Local**: Development storage (files stored in `storage/app/public`)

The system automatically falls back to local storage if S3 is not configured or unavailable.

### What Files Are Stored in S3?

- Vendor registration documents
- MRF attachments (PFI, PO documents)
- GRN documents
- SRF invoices
- Generated PO PDFs
- Signed PO documents

---

## Installation

### Step 1: Install the Flysystem AWS S3 Package

Laravel 12 requires the Flysystem AWS S3 adapter package:

```bash
composer require league/flysystem-aws-s3-v3
```

This package provides the `League\Flysystem\AwsS3V3\AwsS3V3Adapter` class that Laravel uses to interact with S3.

### Step 2: Verify Installation

After installation, verify the package is available:

```bash
php artisan tinker
```

```php
class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
// Should return: true
```

---

## AWS Configuration

### Step 1: Create an AWS Account

If you don't have an AWS account:
1. Go to [AWS Console](https://aws.amazon.com/)
2. Sign up for an account
3. Complete the registration process

### Step 2: Create an S3 Bucket

1. Log in to AWS Console
2. Navigate to **S3** service
3. Click **Create bucket**
4. Configure:
   - **Bucket name**: e.g., `supply-chain-documents-prod` (must be globally unique)
   - **AWS Region**: Choose closest to your users (e.g., `us-east-1`, `eu-west-1`)
   - **Block Public Access**: **Enable** (documents should be private)
   - **Bucket Versioning**: Optional (recommended for production)
   - **Default encryption**: Enable (SSE-S3 or SSE-KMS)
5. Click **Create bucket**

### Step 3: Create IAM User for Application Access

**Never use your root AWS credentials in applications!**

1. Navigate to **IAM** service in AWS Console
2. Click **Users** → **Create user**
3. User name: `supply-chain-backend`
4. **Do NOT** check "Provide user access to the AWS Management Console"
5. Click **Next**

#### Attach Permissions Policy

1. Click **Attach policies directly**
2. Search for and select: **AmazonS3FullAccess** (or create a custom policy with minimal permissions)
3. Click **Next** → **Create user**

#### Create Access Keys

1. Click on the newly created user
2. Go to **Security credentials** tab
3. Click **Create access key**
4. Select **Application running outside AWS**
5. Click **Next** → **Create access key**
6. **IMPORTANT**: Copy both:
   - **Access key ID**
   - **Secret access key** (shown only once!)

Store these securely. You'll need them for the `.env` file.

### Step 4: (Optional) Create Custom IAM Policy

For better security, create a custom policy that only allows access to your specific bucket:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::your-bucket-name",
                "arn:aws:s3:::your-bucket-name/*"
            ]
        }
    ]
}
```

Replace `your-bucket-name` with your actual bucket name.

---

## Environment Variables

Add the following to your `.env` file:

```env
# Storage Configuration
DOCUMENTS_DISK=s3

# AWS S3 Configuration
AWS_ACCESS_KEY_ID=your_access_key_id_here
AWS_SECRET_ACCESS_KEY=your_secret_access_key_here
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
AWS_URL=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### Variable Descriptions

| Variable | Required | Description | Example |
|----------|----------|-------------|---------|
| `DOCUMENTS_DISK` | Yes | Storage disk to use (`s3` or `public`) | `s3` |
| `AWS_ACCESS_KEY_ID` | Yes | IAM user access key ID | `AKIAIOSFODNN7EXAMPLE` |
| `AWS_SECRET_ACCESS_KEY` | Yes | IAM user secret access key | `wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY` |
| `AWS_DEFAULT_REGION` | Yes | AWS region where bucket is located | `us-east-1` |
| `AWS_BUCKET` | Yes | S3 bucket name | `supply-chain-documents-prod` |
| `AWS_URL` | No | Custom S3 URL (leave empty for default) | (empty) |
| `AWS_ENDPOINT` | No | Custom endpoint (for S3-compatible services) | (empty) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | No | Use path-style URLs (usually `false`) | `false` |

### Example `.env` Configuration

```env
# Production S3 Configuration
DOCUMENTS_DISK=s3
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=supply-chain-documents-prod
```

---

## Testing S3 Connection

### Method 1: Using Artisan Tinker

```bash
php artisan tinker
```

```php
use Illuminate\Support\Facades\Storage;

// Test S3 connection
try {
    $disk = Storage::disk('s3');
    
    // Test write
    $testFile = 'test/connection-test.txt';
    $disk->put($testFile, 'S3 connection test - ' . now());
    echo "✓ Write successful\n";
    
    // Test read
    $content = $disk->get($testFile);
    echo "✓ Read successful: " . $content . "\n";
    
    // Test URL generation
    $url = $disk->temporaryUrl($testFile, now()->addHour());
    echo "✓ URL generation successful: " . $url . "\n";
    
    // Clean up
    $disk->delete($testFile);
    echo "✓ Delete successful\n";
    
    echo "\n✅ S3 connection is working correctly!\n";
} catch (\Exception $e) {
    echo "❌ S3 connection failed: " . $e->getMessage() . "\n";
}
```

### Method 2: Test Vendor Registration

1. Set `DOCUMENTS_DISK=s3` in `.env`
2. Clear config cache: `php artisan config:clear`
3. Register a test vendor with a document
4. Check your S3 bucket - you should see the file in `vendor_documents/YYYY/company-name/`

### Method 3: Check Logs

After attempting to use S3, check Laravel logs:

```bash
tail -f storage/logs/laravel.log
```

Look for:
- ✅ `S3 connection successful` messages
- ❌ `S3 disk requested but...` warnings (indicates fallback to local)

---

## Local Development (Fallback)

For local development, you can use local file storage instead of S3:

### Option 1: Use Local Storage

```env
DOCUMENTS_DISK=public
```

Files will be stored in `storage/app/public/vendor_documents/`

**Important**: Create a symbolic link for public access:

```bash
php artisan storage:link
```

This creates a symlink from `public/storage` → `storage/app/public`

### Option 2: Use S3 (Recommended for Testing)

Even in development, using S3 helps catch configuration issues early:

```env
DOCUMENTS_DISK=s3
# ... AWS credentials ...
```

### Automatic Fallback

The system automatically falls back to `public` disk if:
- `DOCUMENTS_DISK` is not set (defaults to `public`)
- S3 package is not installed
- AWS credentials are missing
- S3 connection fails

Check logs to see if fallback occurred.

---

## Troubleshooting

### Error: "Class 'League\Flysystem\AwsS3V3\AwsS3V3Adapter' not found"

**Solution**: Install the package:
```bash
composer require league/flysystem-aws-s3-v3
```

### Error: "AWS credentials not configured"

**Solution**: 
1. Check `.env` file has all AWS variables set
2. Clear config cache: `php artisan config:clear`
3. Verify credentials are correct (no extra spaces, quotes, etc.)

### Error: "Access Denied" or "403 Forbidden"

**Causes**:
- IAM user doesn't have S3 permissions
- Bucket policy is blocking access
- Access keys are incorrect

**Solution**:
1. Verify IAM user has `AmazonS3FullAccess` policy (or custom policy)
2. Check bucket permissions in S3 console
3. Regenerate access keys if needed

### Error: "Bucket not found"

**Solution**:
1. Verify `AWS_BUCKET` in `.env` matches your bucket name exactly
2. Check `AWS_DEFAULT_REGION` matches bucket region
3. Verify bucket exists in AWS Console

### Files Not Appearing in S3

**Check**:
1. Check Laravel logs for errors
2. Verify `DOCUMENTS_DISK=s3` in `.env`
3. Clear config cache: `php artisan config:clear`
4. Check S3 bucket in AWS Console (may take a few seconds)

### Temporary URLs Not Working

**Solution**:
1. Ensure bucket is in a region that supports presigned URLs
2. Check IAM user has `s3:GetObject` permission
3. Verify `AWS_DEFAULT_REGION` is correct

### System Falls Back to Local Storage

**Check logs** for warnings like:
- "S3 disk requested but AWS credentials not configured"
- "S3 disk requested but league/flysystem-aws-s3-v3 package not installed"
- "S3 disk requested but connection failed"

**Solution**: Address the specific issue mentioned in the log.

---

## Migration from Local to S3

If you have existing files in local storage and want to migrate to S3:

### Step 1: Backup Local Files

```bash
# Backup local storage
tar -czf storage-backup-$(date +%Y%m%d).tar.gz storage/app/public/vendor_documents/
```

### Step 2: Configure S3

Follow the [AWS Configuration](#aws-configuration) and [Environment Variables](#environment-variables) sections above.

### Step 3: Test S3 Connection

Use the [Testing S3 Connection](#testing-s3-connection) methods above.

### Step 4: Migrate Files (Manual Script)

Create a migration script:

```php
// migrate-to-s3.php (run with: php migrate-to-s3.php)

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;

$localDisk = Storage::disk('public');
$s3Disk = Storage::disk('s3');

$localPath = 'vendor_documents';
$files = $localDisk->allFiles($localPath);

echo "Found " . count($files) . " files to migrate\n";

foreach ($files as $file) {
    try {
        $content = $localDisk->get($file);
        $s3Disk->put($file, $content);
        echo "✓ Migrated: {$file}\n";
    } catch (\Exception $e) {
        echo "✗ Failed: {$file} - " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete!\n";
```

### Step 5: Update Database Records

After migration, update database records to reflect S3 URLs (if needed). The system will automatically generate new S3 URLs when documents are accessed.

### Step 6: Switch to S3

```env
DOCUMENTS_DISK=s3
```

Clear cache:
```bash
php artisan config:clear
```

### Step 7: Verify

Test vendor registration and check files appear in S3 bucket.

---

## Security Best Practices

1. **Never commit `.env` file** to version control
2. **Use IAM users** with minimal required permissions (not root credentials)
3. **Rotate access keys** regularly
4. **Enable S3 bucket encryption** (SSE-S3 or SSE-KMS)
5. **Use bucket policies** to restrict access
6. **Enable CloudTrail** for S3 access logging (production)
7. **Set up lifecycle policies** to archive old files (optional)

---

## Cost Optimization

S3 pricing is based on:
- **Storage**: ~$0.023 per GB/month (varies by region)
- **Requests**: PUT/GET requests (very cheap)
- **Data transfer**: Outbound data transfer

**Tips**:
- Use lifecycle policies to move old files to Glacier (cheaper)
- Delete unused files regularly
- Use appropriate storage class (Standard, Intelligent-Tiering, etc.)

---

## Support

If you encounter issues:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Verify AWS credentials in AWS Console
3. Test S3 connection using tinker (see above)
4. Check this guide's troubleshooting section

For AWS-specific issues, consult [AWS S3 Documentation](https://docs.aws.amazon.com/s3/).
