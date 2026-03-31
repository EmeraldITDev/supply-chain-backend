# AWS S3 Setup for Windows (PowerShell) - Complete Guide

**Date**: March 31, 2026  
**Environment**: Windows PowerShell  
**Purpose**: Configure AWS S3 for Laravel backend

---

## 📋 Prerequisites

- ✅ AWS Account with active credentials
- ✅ S3 bucket already created
- ✅ AWS CLI installed (optional, for verification)
- ✅ .env file in project root

---

## 🚀 Step 1: Locate Your AWS Credentials

### Option A: AWS Console (Easiest)

```powershell
# Open AWS Console in browser
Start-Process "https://console.aws.amazon.com/iam/home#/users"

# Navigate: IAM > Users > Your Username > Security Credentials > Access Keys
# You'll see: Access Key ID and Secret Access Key
```

### Option B: AWS CLI (If Installed)

```powershell
# List saved profiles
aws configure list

# Get credentials from profile
aws configure get aws_access_key_id --profile your-profile
aws configure get aws_secret_access_key --profile your-profile
```

---

## 🔐 Step 2: Update .env File

### Locate .env

```powershell
# Navigate to project
cd C:\Users\Asuku\OneDrive\Desktop\supply-chain-backend

# Open .env file in VS Code
code .env

# Or open with Notepad
notepad .env
```

### Add/Update AWS Configuration

Find these lines and update them:

```env
# AWS S3 Configuration
AWS_ACCESS_KEY_ID=your_actual_access_key_here
AWS_SECRET_ACCESS_KEY=your_actual_secret_key_here
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-s3-bucket-name
AWS_URL=https://your-s3-bucket-name.s3.amazonaws.com
AWS_ENDPOINT=

# File storage disk to use
DOCUMENTS_DISK=s3
```

**Example (filled in)**:
```env
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=emerald-supply-chain
AWS_URL=https://emerald-supply-chain.s3.amazonaws.com
DOCUMENTS_DISK=s3
```

**Save File**: `Ctrl+S`

---

## 🔑 Step 3: Verify AWS Credentials Are Correct

```powershell
# Navigate to project
cd "C:\Users\Asuku\OneDrive\Desktop\supply-chain-backend"

# Test connection using Laravel Tinker
php artisan tinker

# In Tinker shell, run these commands:
>>> Storage::disk('s3')->put('test-connection.txt', 'AWS S3 connection successful!')
>>> Storage::disk('s3')->exists('test-connection.txt')
>>> Storage::disk('s3')->get('test-connection.txt')
>>> Storage::disk('s3')->delete('test-connection.txt')
>>> exit
```

**Expected Output**:
```
=> true
=> true
=> "AWS S3 connection successful!"
=> true
```

**If you get errors**:
- Check credentials are copied correctly (no extra spaces)
- Verify bucket name is correct
- Check region is correct for bucket location
- Confirm IAM user has S3 permissions

---

## 📦 Step 4: Set IAM Permissions

This step ensures your AWS user can upload/download files.

### Via AWS Console

1. **Open AWS Console** → IAM → Users → Your Username
2. **Click**: "Permissions" tab → "Add permissions" → "Inline policy"
3. **Policy Name**: `S3-supply-chain-access`
4. **Policy JSON** (paste this):

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject",
                "s3:ListBucket",
                "s3:GetObjectAcl",
                "s3:PutObjectAcl",
                "s3:GetBucketLocation"
            ],
            "Resource": [
                "arn:aws:s3:::emerald-supply-chain",
                "arn:aws:s3:::emerald-supply-chain/*"
            ]
        }
    ]
}
```

**Update**: Replace `emerald-supply-chain` with your actual bucket name

5. **Review** → **Create policy**

---

## 🌐 Step 5: Configure S3 Bucket CORS

This allows frontend to upload files directly to S3 (optional but recommended).

### Via AWS Console

1. **Open AWS Console** → S3 → Your Bucket
2. **Click**: "Permissions" tab → Scroll to "CORS"
3. **Edit CORS Configuration** → Paste this:

```json
[
    {
        "AllowedOrigins": [
            "https://yourdomain.com",
            "https://www.yourdomain.com",
            "http://localhost:5173",
            "http://localhost:3000"
        ],
        "AllowedMethods": [
            "GET",
            "PUT",
            "POST",
            "DELETE",
            "HEAD"
        ],
        "AllowedHeaders": [
            "*"
        ],
        "ExposeHeaders": [
            "ETag",
            "x-amz-version-id"
        ],
        "MaxAgeSeconds": 3000
    }
]
```

**Update** `AllowedOrigins` with your frontend URLs

4. **Save changes**

---

## 🧪 Step 6: Test Everything

### Test 6.1: File Upload

```powershell
# Open Laravel Tinker
php artisan tinker

# Upload test file
>>> $content = file_get_contents(base_path('README.md'));
>>> Storage::disk('s3')->put('uploads/test-readme.md', $content);
>>> Storage::disk('s3')->exists('uploads/test-readme.md')

# Expected: true
>>> exit
```

### Test 6.2: Create MRF with PO (Real Use Case)

```bash
# 1. Login
$response = curl -X POST http://localhost:8000/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{
    "email": "procurement@example.com",
    "password": "password"
  }'

# 2. Create test PDF file
Add-Content -Path "test-po.pdf" -Value "PDF content test"

# 3. Upload MRF with PO
$token = $response | ConvertFrom-Json | Select-Object -ExpandProperty token

curl -X POST http://localhost:8000/api/mrfs/MRF-001/generate-po `
  -H "Authorization: Bearer $token" `
  -F "po_number=PO-2026-001" `
  -F "unsigned_po=@test-po.pdf"

# 4. Verify file in S3
# AWS Console → S3 → Your Bucket → Check for pos/1/po_PO-2026-001.pdf
```

### Test 6.3: Vendor Document Download

```powershell
# Upload vendor registration
curl -X POST http://localhost:8000/api/vendors/register `
  -F "companyName=Test Vendor" `
  -F "email=vendor@test.com" `
  -F "documents[]=@test-doc.pdf"

# Get the vendor registration ID and document ID from response

# Download as procurement manager
$token = "your_procurement_manager_token"

curl -H "Authorization: Bearer $token" `
  http://localhost:8000/api/vendors/registrations/1/documents/1/download `
  -OutFile downloaded-doc.pdf

# File should download successfully
```

---

## 📊 Verification Checklist

Run these PowerShell commands to verify setup:

```powershell
# Change to project directory
cd "C:\Users\Asuku\OneDrive\Desktop\supply-chain-backend"

# 1. Check .env has AWS variables
echo "=== AWS Configuration ===" 
Select-String "AWS_" .env

# 2. Check PHP path
echo "=== PHP Version ===" 
php --version

# 3. List installed packages (check laravel/sanctum)
composer list

# 4. Check database connection
php artisan tinker
# >>> DB::connection()->getPdo()
#>>> exit

# 5. Test S3 connection (from earlier)
php artisan tinker
# >>> Storage::disk('s3')->put('verify.txt', 'ok')
# >>> Storage::disk('s3')->delete('verify.txt')
# >>> exit

echo "=== Verification Complete ===" 
```

---

## 🔧 Troubleshooting

### Issue: "InvalidBucketName" Error

```powershell
# Check bucket name in .env
Get-Content .env | Select-String "AWS_BUCKET"

# Verify bucket exists in AWS
# AWS Console → S3 → Find your bucket name
# Copy exact name to .env
```

### Issue: "Access Denied" Error

```powershell
# Check IAM permissions
# AWS Console → IAM → Users → Your User → Permissions
# Verify S3 policy is attached

# Check credentials aren't cached
# Remove credentials from environment (PowerShell):
$env:AWS_ACCESS_KEY_ID = ""
$env:AWS_SECRET_ACCESS_KEY = ""
$env:AWS_PROFILE = ""

# Clear Laravel config cache
php artisan config:clear
php artisan cache:clear
```

### Issue: "InvalidToken" Error

```powershell
# Credentials may have special characters
# Try wrapping in quotes in .env:
AWS_SECRET_ACCESS_KEY="your_secret_with_special_chars"

# Restart PHP server for changes to take effect
php artisan serve
```

### Issue: "403 Forbidden" on Download

```powershell
# Verify user role
php artisan tinker
# >>> $user = App\Models\User::find(1);
# >>> dd($user->role);
# >>> exit

# Check allowed roles in VendorController.php (line ~1125)
# Ensure your role is in: procurement_manager, supply_chain_director, executive, admin
```

---

## 📝 Environment Variables Reference

| Variable | Example | Required | Notes |
|----------|---------|----------|-------|
| `AWS_ACCESS_KEY_ID` | `AKIAIOSFODNN7EXAMPLE` | ✅ Yes | From AWS IAM |
| `AWS_SECRET_ACCESS_KEY` | `wJalrXUtnFEMI/K7MDENG...` | ✅ Yes | Keep secret! |
| `AWS_DEFAULT_REGION` | `us-east-1` | ✅ Yes | Bucket region |
| `AWS_BUCKET` | `emerald-supply-chain` | ✅ Yes | S3 bucket name |
| `AWS_URL` | `https://bucket.s3.amazonaws.com` | ✅ Yes | For generating URLs |
| `AWS_ENDPOINT` | (empty) | ❌ No | Leave blank for AWS |
| `DOCUMENTS_DISK` | `s3` | ✅ Yes | Which disk to use |

---

## ✅ Final Checklist

Before deploying to production:

- [ ] .env has all AWS credentials
- [ ] Tested `Storage::disk('s3')->put()` successfully
- [ ] Tested `Storage::disk('s3')->get()` successfully
- [ ] IAM policy attached to AWS user
- [ ] S3 bucket CORS configured
- [ ] Vendor document download tested
- [ ] MRF PO upload tested
- [ ] All file types working (PDF, DOC, DOCX)
- [ ] Database migrations ran successfully
- [ ] Role-based access working
- [ ] Frontend configured to use correct API endpoint

---

## 🚀 Ready to Deploy!

After completing all steps above, your backend is ready for:
1. File uploads to S3
2. File downloads from S3
3. Vendor document management
4. MRF/PO operations
5. Full role-based access control

**Next Steps**:
1. Test with frontend team
2. Deploy to staging
3. Final QA testing
4. Deploy to production

---

**Questions?** Check `/memories/session/` for debugging notes or review individual controller files for implementation details.
