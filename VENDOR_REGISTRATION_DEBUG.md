# Vendor Registration & Document Debugging Guide

## Common Issues and Solutions

### Issue 1: Documents Not Appearing After Registration

**Symptoms:**
- Registration succeeds but documents don't show up
- `documentCount` is 0 even though files were uploaded

**Possible Causes:**

1. **Frontend Not Sending Files Correctly**
   - Check browser network tab - verify `FormData` includes files
   - Ensure `Content-Type: multipart/form-data` header
   - Verify field name is `documents` (not `document` or `files`)

2. **Storage Disk Not Configured**
   - Check `.env` file for `DOCUMENTS_DISK`
   - Default is `public` (local storage)
   - If using S3, ensure AWS credentials are set

3. **File Size Exceeds Limit**
   - Default max is 10MB per file (`max:10240` = 10MB)
   - Check Laravel logs for validation errors

4. **Database Migration Not Run**
   - Run: `php artisan migrate`
   - Verify `vendor_registration_documents` table exists
   - Check for `file_url` and `file_share_url` columns

### Issue 2: Registration Fails Entirely

**Check Laravel Logs:**
```bash
tail -f storage/logs/laravel.log | grep -i "vendor\|document\|registration"
```

**Common Errors:**

1. **"Class not found"**
   - Run: `composer dump-autoload`
   - Clear cache: `php artisan config:clear`

2. **"Column not found"**
   - Run migrations: `php artisan migrate`
   - Check migration files exist

3. **"Storage disk not found"**
   - Check `config/filesystems.php`
   - Verify disk is configured (s3 or public)

### Issue 3: Documents Stored But Not Visible

**Check Database:**
```sql
-- Check if documents exist in database
SELECT * FROM vendor_registration_documents 
WHERE vendor_registration_id = ?;

-- Check JSON column
SELECT id, company_name, documents 
FROM vendor_registrations 
WHERE id = ?;
```

**Check Storage:**
```bash
# For local storage
ls -la storage/app/public/vendor_documents/

# For S3 (if configured)
# Check AWS S3 console for bucket contents
```

## Testing Steps

### 1. Test Registration Endpoint

```bash
curl -X POST https://your-backend-url/api/vendors/register \
  -F "companyName=Test Company" \
  -F "category=Transportation" \
  -F "email=test@example.com" \
  -F "phone=1234567890" \
  -F "documents[]=@/path/to/file1.pdf" \
  -F "documents[]=@/path/to/file2.pdf"
```

### 2. Check Registration Response

Response should include:
```json
{
  "success": true,
  "message": "Vendor registration submitted successfully",
  "registration": {
    "id": 1,
    "companyName": "Test Company",
    "status": "Pending",
    "documentCount": 2,
    "documents": [
      {
        "id": "1",
        "fileName": "file1.pdf",
        "fileUrl": "https://...",
        "fileSize": 12345
      }
    ]
  }
}
```

### 3. Verify Documents Are Visible

```bash
# Get registration details
curl -X GET https://your-backend-url/api/vendors/registrations/1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Debugging Commands

### Check Recent Registrations
```php
// In tinker: php artisan tinker
$reg = \App\Models\VendorRegistration::latest()->first();
$reg->documents; // Should show documents relationship
$reg->getAttribute('documents'); // Should show JSON column
```

### Check Document Storage
```php
// In tinker
$doc = \App\Models\VendorRegistrationDocument::latest()->first();
$doc->file_path;
$doc->file_url;
\Storage::disk('public')->exists($doc->file_path); // Should return true
```

### Test Document Service
```php
// In tinker
$service = app(\App\Services\VendorDocumentService::class);
$disk = $service->getStorageDisk(); // Should return 'public' or 's3'
```

## Frontend Checklist

1. **Form Data Setup:**
   ```javascript
   const formData = new FormData();
   formData.append('companyName', companyName);
   formData.append('category', category);
   formData.append('email', email);
   
   // Files - IMPORTANT: Use 'documents[]' for multiple files
   files.forEach((file) => {
     formData.append('documents[]', file);
   });
   ```

2. **Request Headers:**
   ```javascript
   // DON'T set Content-Type header - browser will set it with boundary
   // axios/fetch will handle this automatically for FormData
   ```

3. **Check Response:**
   ```javascript
   const response = await fetch('/api/vendors/register', {
     method: 'POST',
     body: formData
   });
   
   const data = await response.json();
   console.log('Documents:', data.registration.documents);
   ```

## Environment Variables

Required in `.env`:
```env
# Storage (required)
DOCUMENTS_DISK=public  # or 's3' for S3 storage

# If using S3 (optional)
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

## Common Fixes

### Fix 1: Clear All Caches
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
composer dump-autoload
```

### Fix 2: Run Migrations
```bash
php artisan migrate
php artisan migrate:status  # Check migration status
```

### Fix 3: Check File Permissions
```bash
# For local storage
chmod -R 755 storage/app/public
chmod -R 755 storage/app/public/vendor_documents
```

### Fix 4: Verify Storage Link
```bash
php artisan storage:link
# Should create: public/storage -> storage/app/public
```

## Log Analysis

Look for these log entries:

**Successful Registration:**
```
[INFO] Vendor registration attempt
[INFO] Validation passed, creating registration
[INFO] Registration created successfully
[INFO] Processing document uploads
[INFO] Storing documents for vendor registration
[INFO] Document stored successfully
[INFO] Updated registration with document metadata
[INFO] Documents stored successfully
```

**Failed Registration:**
```
[ERROR] Error storing vendor documents
[WARNING] No documents were stored successfully
[ERROR] Failed to store document
```

## Still Having Issues?

1. **Check Laravel logs:** `storage/logs/laravel.log`
2. **Check browser console** for frontend errors
3. **Check network tab** to see actual request/response
4. **Verify database** has correct schema
5. **Test with Postman/curl** to isolate frontend vs backend issues
