# S3 Storage Deployment Guide - Quick Start

## 🎯 Problem Solved
Documents uploaded to vendor registrations were returning 404 errors because Render's filesystem is ephemeral. Now using AWS S3 for persistent storage.

---

## ⚡ Quick Deployment Steps

### 1. Create AWS S3 Bucket (5 minutes)

```bash
# Bucket Settings
Name: supply-chain-vendor-documents
Region: us-east-1 (or closest to users)
Block Public Access: ✅ YES (keep private)
Versioning: Optional
Encryption: ✅ Enable AES-256
```

### 2. Create IAM User (3 minutes)

```bash
User Name: supply-chain-app-s3
Permissions: Custom Policy (see below)
```

**IAM Policy (paste this):**
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

**Create Access Keys and save them!**

### 3. Add Environment Variables to Render (2 minutes)

Go to: **Render Dashboard → Your Service → Environment**

Add these variables:

| Variable | Value |
|----------|-------|
| `DOCUMENTS_DISK` | `s3` |
| `AWS_ACCESS_KEY_ID` | `YOUR_ACCESS_KEY` |
| `AWS_SECRET_ACCESS_KEY` | `YOUR_SECRET_KEY` |
| `AWS_DEFAULT_REGION` | `us-east-1` |
| `AWS_BUCKET` | `supply-chain-vendor-documents` |

**Click Save Changes**

### 4. Deploy Code (1 minute)

```bash
git add .
git commit -m "feat: implement persistent S3 storage for vendor documents"
git push
```

Render will auto-deploy.

### 5. Install Dependencies

The deployment automatically runs:
```bash
composer install
```

This installs the required `league/flysystem-aws-s3-v3` package.

---

## ✅ Verification Checklist

After deployment, test these:

- [ ] Upload a document via vendor registration
- [ ] Verify document appears in S3 bucket (AWS Console)
- [ ] Download document via dashboard
- [ ] Restart Render service
- [ ] Verify document is still accessible (no 404!)

---

## 🔧 Files Changed

- ✅ `composer.json` - Added AWS S3 package
- ✅ `app/Services/VendorDocumentService.php` - S3 support
- ✅ `app/Http/Controllers/Api/VendorController.php` - Download endpoint
- ✅ `config/filesystems.php` - S3 configuration
- ✅ `routes/api.php` - Document download route

---

## 🆘 Quick Troubleshooting

### Documents still returning 404?

1. **Check Environment Variables:**
   ```bash
   # In Render dashboard, verify:
   DOCUMENTS_DISK=s3  # Must be 's3', not 'S3' or 'public'
   AWS_BUCKET=supply-chain-vendor-documents  # Exact bucket name
   ```

2. **Check Deployment Logs:**
   - Look for "composer install" success
   - Check for AWS-related errors

3. **Verify S3 Access:**
   ```bash
   # Test AWS credentials work:
   aws s3 ls s3://supply-chain-vendor-documents \
     --region us-east-1 \
     --profile supply-chain
   ```

4. **Check Existing Documents:**
   - Old documents (before deployment) need migration
   - New documents should work immediately

### "Access Denied" errors?

- Verify IAM policy is attached to user
- Check AWS credentials are correct in Render
- Ensure bucket name matches exactly

### Slow upload/download?

- Choose AWS region closest to your users
- Check network connectivity
- Consider enabling S3 Transfer Acceleration (extra cost)

---

## 💡 Pro Tips

### Development Setup

For local development, use local storage:

```bash
# .env (local)
DOCUMENTS_DISK=public
```

This way you don't incur S3 costs during development.

### Test Both Environments

```bash
# Test upload locally
php artisan tinker
>>> Storage::disk('public')->put('test.txt', 'content');

# Test S3 (after configuring AWS credentials)
>>> Storage::disk('s3')->put('test.txt', 'content');
```

### Monitor S3 Usage

- Set up AWS CloudWatch alerts for unusual activity
- Enable S3 request metrics
- Review costs monthly (should be < $1/month)

### Backup Strategy

S3 automatically provides 99.999999999% durability, but consider:
- Enable versioning for document recovery
- Set up lifecycle policies to archive old documents
- Use S3 Cross-Region Replication for critical data

---

## 📊 What Happens Now

### Before (Local Storage)
```
Upload → Render Filesystem → 💀 Gone on restart → 404 Error
```

### After (S3 Storage)
```
Upload → AWS S3 → ✅ Persists forever → Always accessible
```

### Document URLs

**Before:**
```
https://supply-chain-backend-hwh6.onrender.com/storage/vendor_documents/1/doc.pdf
→ 404 Not Found
```

**After:**
```
GET /api/vendors/registrations/1/documents/5
→ Temporary signed S3 URL (valid 1 hour)
→ https://supply-chain-vendor-documents.s3.amazonaws.com/...?signature=...
```

---

## 📈 Expected Results

- ✅ **No more 404 errors** on vendor documents
- ✅ **Documents persist** across deployments and restarts
- ✅ **Secure access** via temporary signed URLs
- ✅ **Role-based permissions** for document downloads
- ✅ **Scalable storage** that grows with your needs
- ✅ **Minimal cost** (~$0.05/month for typical usage)

---

## 🚀 Next Steps After Deployment

1. **Test thoroughly** with real vendor registrations
2. **Monitor S3 usage** in AWS Console
3. **Update frontend** to use new download endpoint
4. **Document for team** how to access vendor documents
5. **Set up alerts** for failed uploads (optional)

---

## 📞 Support

If issues persist after following this guide:

1. Check `S3_STORAGE_SETUP.md` for detailed troubleshooting
2. Review Render logs for errors
3. Verify AWS credentials in IAM console
4. Test S3 access directly via AWS CLI

---

## 🎉 Success Criteria

You'll know it's working when:
- ✅ Upload vendor registration with document → Success
- ✅ View document URL in dashboard → Shows S3 URL
- ✅ Click download → File downloads correctly
- ✅ Restart Render service → Document still accessible
- ✅ Check S3 bucket → See uploaded files

---

*Deployment Time: ~10 minutes*
*Cost: ~$0.05/month (likely free tier)*
*Status: ✅ Ready to Deploy*
