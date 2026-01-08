# AWS S3 Storage Setup for Vendor Documents

## Overview

Vendor registration documents are now stored in AWS S3 for persistent, reliable storage across deployments. This eliminates the 404 errors that occurred with ephemeral local storage on Render.

---

## ✅ Changes Made

### 1. **Package Installation**
Added AWS S3 Flysystem adapter to `composer.json`:
```json
"league/flysystem-aws-s3-v3": "^3.0"
```

### 2. **Updated VendorDocumentService**
- Documents now stored in configurable disk (S3 or local)
- Generates temporary signed URLs for S3 (1-hour expiration)
- Added `getDocumentContent()` for secure downloads
- Added `documentExists()` for storage verification

### 3. **Updated Filesystem Configuration**
- Added `documents_disk` configuration option
- Set S3 visibility to `private` for security
- Added default AWS region

### 4. **New Download Endpoint**
- **Route:** `GET /api/vendors/registrations/{registrationId}/documents/{documentId}`
- Secure document downloads with authorization check
- Supports executive-level roles

---

## 🔧 AWS S3 Configuration

### Step 1: Create AWS S3 Bucket

1. **Log into AWS Console** → Go to S3
2. **Create Bucket:**
   - Name: `supply-chain-vendor-documents` (or your preferred name)
   - Region: Choose closest to your users (e.g., `us-east-1`)
   - **Block all public access:** ✅ Enable (documents should be private)
   - **Versioning:** Optional (recommended for document recovery)
   - **Encryption:** Enable server-side encryption (AES-256)

3. **Note:** Do NOT make the bucket public. Documents will be accessed via signed URLs.

### Step 2: Create IAM User for Application

1. **Go to IAM** → Users → Create User
2. **User name:** `supply-chain-app-s3`
3. **Attach Policies:** Create custom policy:

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
                "arn:aws:s3:::supply-chain-vendor-documents",
                "arn:aws:s3:::supply-chain-vendor-documents/*"
            ]
        }
    ]
}
```

4. **Create Access Keys:**
   - Go to Security Credentials
   - Create Access Key → Application running outside AWS
   - **Save the Access Key ID and Secret Access Key** (shown only once!)

---

## 🔐 Environment Variables

Add these environment variables to your `.env` file (locally) and Render dashboard (production):

### Required AWS Variables

```bash
# AWS S3 Configuration
AWS_ACCESS_KEY_ID=your_access_key_id_here
AWS_SECRET_ACCESS_KEY=your_secret_access_key_here
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=supply-chain-vendor-documents

# Document Storage Disk (use 's3' in production, 'public' in development)
DOCUMENTS_DISK=s3

# Optional: Custom S3 endpoint (only if using S3-compatible storage like MinIO)
# AWS_ENDPOINT=
# AWS_URL=
# AWS_USE_PATH_STYLE_ENDPOINT=false
```

### Development vs Production

#### **Local Development (.env):**
```bash
DOCUMENTS_DISK=public  # Use local storage for development
```

#### **Production/Render (.env or Dashboard):**
```bash
DOCUMENTS_DISK=s3      # Use S3 for persistent storage
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=supply-chain-vendor-documents
```

---

## 🚀 Deployment to Render

### Step 1: Add Environment Variables to Render

1. **Go to Render Dashboard** → Your Service → Environment
2. **Add the following environment variables:**

| Key | Value | Description |
|-----|-------|-------------|
| `DOCUMENTS_DISK` | `s3` | Use S3 for document storage |
| `AWS_ACCESS_KEY_ID` | `YOUR_KEY` | AWS IAM user access key |
| `AWS_SECRET_ACCESS_KEY` | `YOUR_SECRET` | AWS IAM user secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | Your S3 bucket region |
| `AWS_BUCKET` | `supply-chain-vendor-documents` | Your S3 bucket name |

3. **Click "Save Changes"**

### Step 2: Install Dependencies

The deployment will automatically run:
```bash
composer install
```

This installs the `league/flysystem-aws-s3-v3` package.

### Step 3: Deploy

1. **Commit and push your changes:**
```bash
git add composer.json composer.lock
git add app/Services/VendorDocumentService.php
git add app/Http/Controllers/Api/VendorController.php
git add config/filesystems.php
git add routes/api.php
git commit -m "feat: implement persistent S3 storage for vendor documents"
git push
```

2. Render will automatically deploy the changes

### Step 4: Verify

After deployment:
1. Test document upload via vendor registration
2. Verify document appears in S3 bucket
3. Test document download via dashboard
4. Restart service and verify documents persist

---

## 📥 Using the New Document Download Endpoint

### Endpoint Details

**URL:** `GET /api/vendors/registrations/{registrationId}/documents/{documentId}`

**Authorization:** Required - Bearer token with executive-level roles

**Response:**
- **Success (200):** File download with proper headers
- **Not Found (404):** Document or registration not found
- **Forbidden (403):** Insufficient permissions

### Example Request

```bash
curl -X GET \
  'https://supply-chain-backend-hwh6.onrender.com/api/vendors/registrations/1/documents/5' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -OJ
```

### Frontend Integration

```javascript
// Download document
async function downloadDocument(registrationId, documentId) {
  const response = await fetch(
    `/api/vendors/registrations/${registrationId}/documents/${documentId}`,
    {
      headers: {
        'Authorization': `Bearer ${token}`
      }
    }
  );
  
  if (response.ok) {
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'document.pdf'; // Get from Content-Disposition header
    a.click();
  }
}
```

---

## 🔄 Migration for Existing Documents

If you have existing documents in local storage that need to be migrated to S3:

### Option 1: Manual Migration Script

Create `database/migrations/migrate_documents_to_s3.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;
use App\Models\VendorRegistrationDocument;

// Get all documents
$documents = VendorRegistrationDocument::all();

foreach ($documents as $document) {
    $localPath = $document->file_path;
    
    // Check if file exists locally
    if (Storage::disk('public')->exists($localPath)) {
        // Get file content
        $content = Storage::disk('public')->get($localPath);
        
        // Upload to S3
        Storage::disk('s3')->put($localPath, $content);
        
        echo "Migrated: {$localPath}\n";
    }
}

echo "Migration complete!\n";
```

Run with:
```bash
php artisan tinker < database/migrations/migrate_documents_to_s3.php
```

### Option 2: Keep Existing Documents, New to S3

Existing documents will return 404 until re-uploaded. This is acceptable if:
- You have few existing documents
- Vendors can re-submit documents
- Historical documents aren't critical

---

## 🧪 Testing

### Test 1: Upload Document (Public Endpoint)

```bash
curl -X POST https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -F "companyName=Test Corp" \
  -F "category=IT Services" \
  -F "email=test@testcorp.com" \
  -F "documents[]=@document.pdf"
```

**Expected:**
- Document uploaded to S3
- Record created in database
- S3 path stored: `vendor_documents/{id}/document_123456789.pdf`

### Test 2: View Document in Dashboard

```bash
curl -X GET https://supply-chain-backend-hwh6.onrender.com/api/vendors/registrations/1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "documents": [
      {
        "id": "5",
        "fileName": "document.pdf",
        "fileUrl": "https://s3.amazonaws.com/...",  // Signed URL
        "fileSize": 102400
      }
    ]
  }
}
```

### Test 3: Download Document

```bash
curl -X GET \
  https://supply-chain-backend-hwh6.onrender.com/api/vendors/registrations/1/documents/5 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -o downloaded_document.pdf
```

**Expected:**
- File downloads successfully
- Content-Type matches original
- File size matches

### Test 4: Verify Persistence After Restart

1. Upload a document
2. Restart Render service
3. Attempt to download document
4. **Expected:** Document downloads successfully (no 404)

---

## 💰 AWS S3 Costs

### Estimated Monthly Costs (Based on Usage)

**Assumptions:**
- 100 vendor registrations/month
- 3 documents per registration (300 files)
- Average file size: 500 KB
- Total storage: ~150 MB/month

**Cost Breakdown:**

| Service | Usage | Cost |
|---------|-------|------|
| **S3 Storage** | 150 MB | ~$0.004/month |
| **PUT Requests** | 300 uploads | ~$0.001/month |
| **GET Requests** | 1,000 downloads | ~$0.0004/month |
| **Data Transfer Out** | 500 MB | ~$0.045/month |
| **Total** | | **~$0.05/month** |

**Note:** S3 Free Tier (first 12 months):
- 5 GB storage
- 20,000 GET requests
- 2,000 PUT requests
- 15 GB data transfer out

Your usage will likely stay within the free tier for the first year!

---

## 🔒 Security Best Practices

### 1. **Private Bucket**
- ✅ Block all public access
- ✅ Use signed URLs for access
- ✅ Set expiration times (1 hour)

### 2. **IAM User Permissions**
- ✅ Least privilege principle
- ✅ Only bucket-specific permissions
- ✅ No broad S3 access

### 3. **Encryption**
- ✅ Server-side encryption enabled
- ✅ HTTPS for all transfers
- ✅ Encrypted environment variables

### 4. **Access Control**
- ✅ Role-based authorization in API
- ✅ Document ownership verification
- ✅ Audit logging (optional: CloudTrail)

---

## 🐛 Troubleshooting

### Issue: "Class 'League\Flysystem\AwsS3V3\AwsS3V3Adapter' not found"

**Solution:**
```bash
composer install
# or
composer require league/flysystem-aws-s3-v3
```

### Issue: "The specified bucket does not exist"

**Solution:**
- Verify `AWS_BUCKET` name matches exactly
- Check bucket exists in specified `AWS_DEFAULT_REGION`
- Verify IAM user has ListBucket permission

### Issue: "Access Denied" when uploading

**Solution:**
- Verify IAM user has `s3:PutObject` permission
- Check AWS credentials are correct
- Ensure bucket policy doesn't deny access

### Issue: Signed URLs return 403

**Solution:**
- Check system clock is synchronized (AWS requires accurate time)
- Verify AWS credentials are still valid
- Check bucket CORS settings if accessing from browser

### Issue: Documents still returning 404

**Solution:**
- Verify `DOCUMENTS_DISK=s3` is set in Render environment
- Check `composer install` ran successfully
- Look for errors in Render logs: `php artisan log:tail`
- Verify documents exist in S3 console

---

## 📚 Additional Resources

- [AWS S3 Documentation](https://docs.aws.amazon.com/s3/)
- [Laravel Filesystem Documentation](https://laravel.com/docs/filesystem)
- [Flysystem AWS S3 Adapter](https://flysystem.thephpleague.com/docs/adapter/aws-s3/)
- [AWS IAM Best Practices](https://docs.aws.amazon.com/IAM/latest/UserGuide/best-practices.html)

---

*Implementation Date: January 8, 2026*
*Status: ✅ Ready for Deployment*
