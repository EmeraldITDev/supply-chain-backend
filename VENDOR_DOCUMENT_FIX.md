# Vendor Registration Document Storage Fix

## Problem Summary

Vendor registration documents were not appearing in the "Uploaded Documents" section for Procurement Managers to review during the approval process. Additionally, documents needed to be moved to vendor-specific permanent folders after approval.

## Root Causes Identified

1. **Document Retrieval Issue**: The code was only reading from the `documents` JSON column in the `vendor_registrations` table, but not from the `vendor_registration_documents` relationship table where documents are also stored.

2. **Missing Relationship Loading**: The `documents()` relationship was not being eager-loaded when fetching registrations, causing documents to not appear.

3. **No Document Migration**: Documents remained in the registration folder structure after approval instead of being moved to a vendor-specific permanent folder.

4. **Disk Mismatch Handling**: The download endpoint didn't handle cases where documents might be stored on a different disk than currently configured.

## Solutions Implemented

### 1. Enhanced Document Retrieval (`VendorController.php`)

**Updated Methods:**
- `registrations()` - Lists all vendor registrations
- `getRegistration()` - Gets a single vendor registration

**Changes:**
- Added `'documents'` to eager loading: `VendorRegistration::with(['vendor', 'approver', 'documents'])`
- Priority-based retrieval:
  1. First checks `documents()` relationship (database table) - more reliable
  2. Falls back to `documents` JSON column if relationship is empty
- Handles both Eloquent models and array/object data structures
- Properly formats dates and handles missing fields

**Code Example:**
```php
// Priority: Use documents relationship (table) first, then fallback to JSON column
$documentRecords = $reg->documents ?? collect([]);

// If no documents in relationship, try JSON column
if ($documentRecords->isEmpty()) {
    $documentMetadata = is_array($registration->documents) ? $registration->documents : [];
    $documentRecords = collect($documentMetadata)->map(function($doc) {
        return (object) $doc;
    });
}
```

### 2. Document Migration After Approval (`VendorDocumentService.php`)

**New Method: `moveDocumentsToVendorFolder()`**

This method:
- Moves documents from registration folder (`vendor_documents/{year}/{companyName}`) to vendor-specific folder (`vendor_documents/{year}/{vendorSlug}`)
- Updates document records with new paths and URLs
- Deletes old files after successful migration
- Updates the registration's JSON column with new paths
- Handles errors gracefully without failing the approval process

**Storage Path Structure:**
- **During Registration (Temporary)**: `vendor_documents/{year}/{companyName}/filename.pdf`
- **After Approval (Permanent)**: `vendor_documents/{year}/{vendorSlug}/filename.pdf`

**Integration:**
- Automatically called by `VendorApprovalService::approveVendor()` after vendor is created
- Runs within the approval transaction but errors don't rollback approval

### 3. Improved Download Endpoint (`VendorDocumentService.php`)

**Updated Method: `getDocumentContent()`**

**Changes:**
- Tries configured disk first
- Falls back to checking both S3 and public disks if file not found
- Handles cases where documents were stored on a different disk than currently configured
- Logs disk mismatches for debugging

**Code Example:**
```php
// Try configured disk first
if (Storage::disk($disk)->exists($document->file_path)) {
    return Storage::disk($disk)->get($document->file_path);
}

// Fallback: Try both S3 and public disks
$disksToTry = ['s3', 'public'];
foreach ($disksToTry as $tryDisk) {
    if (Storage::disk($tryDisk)->exists($document->file_path)) {
        return Storage::disk($tryDisk)->get($document->file_path);
    }
}
```

### 4. Updated Approval Service (`VendorApprovalService.php`)

**Changes:**
- Added call to `moveDocumentsToVendorFolder()` after vendor creation
- Documents are moved to permanent vendor folder automatically upon approval
- Errors in document migration are logged but don't fail the approval process

## Document Storage Flow

### During Registration (Pending Status)

1. **Upload**: Vendor uploads documents during registration
2. **Storage**: Documents saved to `vendor_documents/{year}/{companyName}/`
3. **Database**: 
   - Record created in `vendor_registration_documents` table
   - Metadata added to `documents` JSON column in `vendor_registrations` table
4. **Visibility**: Documents visible to Procurement Managers via:
   - `GET /api/vendors/registrations` (list)
   - `GET /api/vendors/registrations/{id}` (single)

### After Approval (Approved Status)

1. **Vendor Created**: Vendor record created from registration
2. **Document Migration**: 
   - Documents moved from `vendor_documents/{year}/{companyName}/` 
   - To `vendor_documents/{year}/{vendorSlug}/`
   - Database records updated with new paths
3. **Permanent Storage**: Documents remain in vendor-specific folder for future access
4. **Availability**: Documents remain accessible for download via same endpoints

## API Endpoints

### List Registrations with Documents
```
GET /api/vendors/registrations
```
**Response includes:**
```json
{
  "success": true,
  "data": [
    {
      "id": "1",
      "companyName": "ABC Corp",
      "documents": [
        {
          "id": "1",
          "fileName": "certificate.pdf",
          "fileUrl": "https://...",
          "file_url": "https://...",
          "url": "https://...",
          "fileSize": 12345,
          "uploadedAt": "2026-01-27T10:00:00Z"
        }
      ]
    }
  ]
}
```

### Get Single Registration with Documents
```
GET /api/vendors/registrations/{id}
```
**Response includes same document structure as above**

### Download Document
```
GET /api/vendors/registrations/{registrationId}/documents/{documentId}/download
```
- Returns file content with proper headers
- Handles S3 signed URLs and local file serving
- Works with documents stored on any configured disk

## Testing Checklist

- [ ] Register a new vendor with documents
- [ ] Verify documents appear in `GET /api/vendors/registrations`
- [ ] Verify documents appear in `GET /api/vendors/registrations/{id}`
- [ ] Verify documents can be downloaded via download endpoint
- [ ] Approve the vendor registration
- [ ] Verify documents are moved to vendor-specific folder in S3/local storage
- [ ] Verify documents still accessible after approval
- [ ] Check logs for any migration errors

## Logging

The system logs important events:

**Document Storage:**
- `"Storing documents for vendor registration"` - When documents are uploaded
- `"Document stored successfully"` - When a document is saved
- `"Updated registration with document metadata"` - When JSON column is updated

**Document Migration:**
- `"Moving documents to vendor folder"` - When migration starts
- `"Document moved successfully"` - For each moved document
- `"Document migration completed"` - When migration finishes
- `"Failed to move documents to vendor folder"` - If migration fails

**Document Retrieval:**
- `"Failed to generate document URL"` - If URL generation fails
- `"Document found on different disk"` - If file found on unexpected disk
- `"Document file not found on any disk"` - If file is missing

## Troubleshooting

### Documents Not Appearing

1. **Check Database:**
   ```sql
   SELECT * FROM vendor_registration_documents WHERE vendor_registration_id = ?;
   SELECT documents FROM vendor_registrations WHERE id = ?;
   ```

2. **Check Storage:**
   - Verify files exist in storage (S3 bucket or `storage/app/public/vendor_documents/`)
   - Check file paths match database records

3. **Check Logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep -i document
   ```

### Documents Not Moving After Approval

1. **Check Logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep -i "move.*document"
   ```

2. **Verify Vendor Created:**
   - Check `vendor_registrations.vendor_id` is set
   - Check `vendors` table has the new vendor

3. **Manual Migration:**
   ```php
   // In tinker
   $reg = VendorRegistration::find($id);
   $vendor = $reg->vendor;
   $service = app(VendorDocumentService::class);
   $service->moveDocumentsToVendorFolder($reg, $vendor);
   ```

### Download Fails

1. **Check File Exists:**
   ```php
   Storage::disk('s3')->exists($document->file_path);
   // or
   Storage::disk('public')->exists($document->file_path);
   ```

2. **Check Disk Configuration:**
   - Verify `DOCUMENTS_DISK` in `.env`
   - Verify AWS credentials if using S3
   - Check `config/filesystems.php`

## Files Modified

1. `app/Http/Controllers/Api/VendorController.php`
   - Enhanced `registrations()` method
   - Enhanced `getRegistration()` method
   - Added eager loading for documents relationship

2. `app/Services/VendorDocumentService.php`
   - Added `moveDocumentsToVendorFolder()` method
   - Enhanced `getDocumentContent()` method with disk fallback

3. `app/Services/VendorApprovalService.php`
   - Added document migration call in `approveVendor()` method

## Next Steps

1. **Test the fixes** with a new vendor registration
2. **Monitor logs** for any errors during document operations
3. **Verify S3 storage** if using S3 (check bucket contents)
4. **Test document migration** by approving a vendor with documents

## Notes

- Documents are stored in **both** the `vendor_registration_documents` table AND the `documents` JSON column for redundancy
- The system prioritizes the relationship table over the JSON column for reliability
- Document migration happens automatically upon approval - no manual intervention needed
- If migration fails, approval still succeeds (errors are logged)
- Documents remain accessible throughout the entire process
