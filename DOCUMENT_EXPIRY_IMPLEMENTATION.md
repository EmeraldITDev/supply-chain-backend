# Document Expiry Management Implementation

## Overview
Complete implementation of 4 document expiry management features for vendor registration lifecycle management.

## ✅ Completed Components

### 1. Scheduled Document Expiry Command
**File**: `app/Console/Commands/UpdateExpiredDocuments.php`

**What it does**:
- Runs automatically daily at midnight (00:00)
- Marks vendor documents as 'Expired' when past their expiry_date
- Updates vendor registration status to 'Documents Incomplete' if required docs expired
- Sends email notifications to vendors requesting renewal
- Includes error handling and comprehensive logging

**Command signature**: `php artisan documents:mark-expired`

**Key features**:
- Prevents overlapping executions (10-minute timeout)
- Graceful error handling with detailed logging
- Separate email notifications per vendor

### 2. Console Kernel Scheduler
**File**: `app/Console/Kernel.php`

**What it does**:
- Registers the UpdateExpiredDocuments command
- Schedules it to run daily at 00:00 (midnight)
- Logs success/failure with Slack/email notifications (if configured)

**Scheduling code**:
```php
$schedule->command('documents:mark-expired')
    ->dailyAt('00:00')
    ->withoutOverlapping(10);
```

### 3. Database Migration for Expiry Tracking
**File**: `database/migrations/2026_03_31_000000_add_expiry_columns_to_vendor_documents.php`

**New columns added to `vendor_registration_documents` table**:
- `expiry_date` (DateTime, nullable) - When the document expires
- `is_required` (Boolean, default false) - Whether renewal is required for approval
- `status` (Enum: Pending, Approved, Rejected, Expired) - Document lifecycle status

**Indexes added**:
- Index on `expiry_date` for efficient date filtering
- Index on `status` for quick status lookups
- Compound index on (vendor_registration_id, status) for vendor-specific queries

### 4. Download Endpoint Expiry Validation
**File**: `app/Http/Controllers/Api/VendorController.php` → `downloadDocument()` method

**What it does**:
- Checks if document is past expiry_date before allowing download
- Returns HTTP 410 (Gone) status if expired (standard for unavailable resources)
- Includes expiry_date in error response for frontend display

**HTTP Response (expired document)**:
```json
{
    "error": "Document has expired",
    "expiry_date": "2026-03-15",
    "message": "Please upload a renewed version of this document"
}
```

**HTTP Status**: 410 Gone (indicates resource no longer available)

### 5. Expiring Documents List Endpoint
**File**: `app/Http/Controllers/Api/VendorController.php` → `getExpiringDocuments()` method

**Endpoint**: `GET /api/vendors/documents/expiring`

**Query parameters**:
- `days` (integer, default 30) - Number of days threshold for expiry date

**Response example**:
```json
{
    "success": true,
    "data": {
        "count": 5,
        "documents": [
            {
                "id": 1,
                "vendor_registration_id": 42,
                "file_name": "business_license.pdf",
                "type": "Business License",
                "expiry_date": "2026-04-15",
                "days_until_expiry": 15,
                "is_required": true,
                "status": "Approved",
                "vendor_registration": {
                    "id": 42,
                    "company_name": "ABC Logistics Inc",
                    "status": "Approved",
                    "category": "Transportation"
                }
            }
        ]
    }
}
```

**Use cases**:
- Displays upcoming expirations in procurement dashboard
- Enables proactive vendor document management
- Helps identify compliance risks before documents expire

### 6. Route Registration
**File**: `routes/api.php`

**New route added**:
```php
Route::get('/vendors/documents/expiring', [VendorController::class, 'getExpiringDocuments'])
```

**Location**: Between vendor registrations routes (line ~252)

**Requires**: Bearer token authentication via Sanctum middleware

### 7. Vendor Registration Array Validation
**File**: `app/Http/Controllers/Api/VendorController.php` → `register()` method

**Guard 1: Batch submission rejection**
- Detects if request body is array vs object
- Returns 422 Unprocessable Entity with clear error
- Error message: "Batch registration not supported. Send one registration at a time."

**Guard 2: Document object validation**
- Converts array documents to objects (prevents "read property on array" errors)
- Skips documents missing required `id` property
- Adds null-safe access for optional fields
- Logs warnings for array conversion scenarios

**Response format (invalid request)**:
```json
{
    "error": "Batch registration not supported. Send one registration at a time.",
    "code": "INVALID_REQUEST_FORMAT",
    "expected": "Single object with companyName, email, category, etc.",
    "received": "Array of objects"
}
```

### 8. Email Notification Template
**File**: `resources/views/emails/expired-documents.blade.php`

**What it contains**:
- List of expired documents with expiry dates
- Clear action items (upload renewed documents within 7 days)
- Link to vendor dashboard for document upload
- Contact information for vendor support
- Professional formatted using Mail components

**Template variables** (passed from command):
- `vendorName` - Vendor company name
- `expiredDocuments` - Array of expired documents with dates
- `registrationStatus` - Current vendor registration status
- `dashboardUrl` - Frontend URL for document upload

---

## 📋 Deployment Checklist

### Step 1: Commit Code Changes
```bash
git add .
git commit -m "Implement document expiry management feature

- Add UpdateExpiredDocuments scheduled command
- Add expiry_date, is_required, status columns to vendor_registration_documents
- Add downloadDocument endpoint expiry validation (410 Gone response)
- Add getExpiringDocuments endpoint for proactive document management
- Add array validation guards to vendor registration
- Add email notification template for expired documents
- Setup daily scheduled job at midnight for expiry processing"
```

### Step 2: Push to Render
```bash
git push origin main
```
Render will auto-deploy and restart application.

### Step 3: Run Database Migration
Once deployed to Render, execute:
```bash
php artisan migrate
```

**Via Render Dashboard**:
1. Go to your Render service dashboard
2. Open "Shell" terminal
3. Run: `php artisan migrate`
4. Verify: Table `vendor_registration_documents` now has `expiry_date`, `is_required`, `status` columns

### Step 4: Verify Scheduler Setup on Render
**Important**: Render doesn't run Laravel's scheduler automatically. You must:

**Option A: Using Render Cron Job** (Recommended)
1. Go to Render dashboard → Service Settings
2. Add Environment Variable: `LARAVEL_SCHEDULER_ENABLED=true` if using a cron endpoint
3. Or use external cron service:

```bash
curl https://your-render-app.onrender.com/api/schedule-run
```

**Option B: Manual Test**
SSH into Render and run manually:
```bash
php artisan documents:mark-expired
```

### Step 5: Test Features
#### Test 5a: Array Validation
```bash
# Send array (should fail with 422)
POST /api/vendors/registrations
Body: [{ "companyName": "...", "documents": [...] }, { ... }]
Expected: 422 error "Batch registration not supported"

# Send object (should succeed)
POST /api/vendors/registrations
Body: { "companyName": "...", "documents": [...] }
Expected: 200 success
```

#### Test 5b: Document Expiry Download
```bash
# Set document expiry_date to past date
UPDATE vendor_registration_documents SET expiry_date='2026-03-01' WHERE id=123;

# Try to download
GET /api/vendors/documents/123/download
Expected: 410 Gone status with expiry_date in response
```

#### Test 5c: Expiring Documents List
```bash
GET /api/vendors/documents/expiring?days=30
Expected: 200 with list of documents expiring within 30 days
```

#### Test 5d: Scheduled Command
```bash
# SSH into Render
ssh your-render-service

# Run manually
php artisan documents:mark-expired

# Check logs
tail -f storage/logs/laravel.log
```

---

## 🔍 Troubleshooting

### Issue: Email not being sent
**Check**:
1. `config/mail.php` has correct SMTP settings
2. `MAIL_FROM_ADDRESS` is set in `.env`
3. Run: `php artisan tinker` → `Mail::raw('test', fn($m) => $m->to('test@email.com'))`

### Issue: Migration not found
**Solution**:
- Verify migration file exists: `database/migrations/2026_03_31_000000_add_expiry_columns_to_vendor_documents.php`
- Run: `php artisan migrate:status` to see migration queue
- If stuck: `php artisan migrate:refresh --step=1`

### Issue: Scheduler not running on Render
**Solutions**:
1. Add cron job via external service (EasyCron, Laravel Scheduler Endpoint, etc.)
2. Use a Render background worker instead of web service
3. Manually trigger: `php artisan documents:mark-expired` (create custom endpoint if needed)

### Issue: "documents:mark-expired" command not found
**Solution**:
- Verify file: `app/Console/Commands/UpdateExpiredDocuments.php` exists
- Run: `php artisan list` to see registered commands
- Clear cache: `php artisan cache:clear`

---

## 📊 Database Changes Summary

### New Table Columns
```sql
ALTER TABLE vendor_registration_documents ADD COLUMN expiry_date DATETIME NULL;
ALTER TABLE vendor_registration_documents ADD COLUMN is_required BOOLEAN DEFAULT FALSE;
ALTER TABLE vendor_registration_documents ADD COLUMN status ENUM('Pending', 'Approved', 'Rejected', 'Expired') DEFAULT 'Pending';

CREATE INDEX idx_expiry_date ON vendor_registration_documents(expiry_date);
CREATE INDEX idx_status ON vendor_registration_documents(status);
CREATE INDEX idx_registration_status ON vendor_registration_documents(vendor_registration_id, status);
```

### Migration Rollback
If needed, run:
```bash
php artisan migrate:rollback --step=1
```

---

## 🚀 API Reference

### Get Expiring Documents
```bash
GET /api/vendors/documents/expiring?days=30

Authorization: Bearer {token}

Response (200):
{
    "success": true,
    "data": {
        "count": 3,
        "documents": [...]
    }
}
```

### Download Document
```bash
GET /api/vendors/documents/{id}/download

Response (200): File stream
Response (410): { "error": "Document has expired", "expiry_date": "2026-03-15" }
```

### Register Vendor
```bash
POST /api/vendors/registrations

Body:
{
    "companyName": "ABC Corp",
    "email": "contact@abc.com",
    "category": "Transportation",
    "documents": [
        {
            "id": 1,
            "type": "License",
            "file_path": "licenses/xyz.pdf"
        }
    ]
}

Response (200): { "success": true, "registration": {...} }
Response (422): { "error": "Batch registration not supported..." }
```

---

## 📝 Files Modified/Created

| File | Status | Action |
|------|--------|--------|
| `app/Console/Commands/UpdateExpiredDocuments.php` | NEW | Scheduled expiry command |
| `app/Console/Kernel.php` | NEW | Scheduler registration |
| `database/migrations/2026_03_31_000000_add_expiry_columns_to_vendor_documents.php` | NEW | Schema changes |
| `app/Http/Controllers/Api/VendorController.php` | MODIFIED | 3 methods updated |
| `routes/api.php` | MODIFIED | 1 route added |
| `resources/views/emails/expired-documents.blade.php` | NEW | Email template |

---

## ✨ Feature Highlights

✅ **Automated Expiry Management** - Documents marked Expired automatically at midnight
✅ **Proactive Notifications** - Vendors notified via email when documents expire
✅ **Compliance Tracking** - Procurement team can view upcoming expirations before they occur
✅ **Graceful Degradation** - Expired documents return 410 Gone (not 404), indicating resource availability issue
✅ **Batch Error Prevention** - Register endpoint validates request format and prevents array submissions
✅ **Comprehensive Logging** - All operations logged for audit trail

---

## 📞 Support
For issues or questions about this implementation:
1. Check troubleshooting section above
2. Review logs: `storage/logs/laravel.log`
3. Verify all files were created: See "Files Modified/Created" table
4. Test manually: `php artisan documents:mark-expired`

---

**Last Updated**: March 31, 2026  
**Status**: Implementation Complete - Awaiting Deployment
