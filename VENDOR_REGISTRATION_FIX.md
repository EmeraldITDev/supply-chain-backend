# Vendor Registration & Document Visibility Fix

## Issues Fixed

### 1. Document Upload Array Handling
**Problem:** When a single file is uploaded, `$request->file('documents')` returns a single `UploadedFile` object, not an array. The `storeDocuments()` method expects an array, causing failures.

**Fix:** Normalized document input to always be an array:
```php
$documents = $request->file('documents');
// Normalize to array - file() can return single file or array
if (!is_array($documents)) {
    $documents = [$documents];
}
// Filter out null values
$documents = array_filter($documents, function($doc) {
    return $doc !== null;
});
```

### 2. Document Relationship Loading
**Problem:** When retrieving documents, the code was accessing `$reg->documents` which could be either:
- The relationship (Collection) if loaded
- The JSON column (array) if relationship not loaded
- `null` if neither exists

This caused confusion and documents not appearing.

**Fix:** Properly check if relationship is loaded before accessing:
```php
// Check if documents relationship is loaded
$documentRecords = null;
if ($reg->relationLoaded('documents')) {
    $documentRecords = $reg->documents;
} else {
    // Try to load the relationship
    $reg->load('documents');
    $documentRecords = $reg->documents;
}

// If no documents in relationship, try JSON column
if (!$documentRecords || $documentRecords->isEmpty()) {
    $jsonDocuments = $reg->getAttribute('documents');
    $documentMetadata = is_array($jsonDocuments) ? $jsonDocuments : [];
    // ... convert to collection
}
```

### 3. Storage Directory Creation
**Problem:** For local/public storage, directories might not exist, causing file storage to fail silently.

**Fix:** Ensure storage directories are created before storing files:
```php
// For local/public storage, ensure directory exists
if ($disk === 'public' || $disk === 'local') {
    $fullPath = storage_path("app/{$disk}/{$basePath}");
    if (!file_exists($fullPath)) {
        @mkdir($fullPath, 0755, true);
    }
}
```

### 4. Enhanced Logging
**Added comprehensive logging to help debug issues:**
- Log when documents are being processed
- Log document count
- Log storage disk being used
- Log when no documents are provided
- Log errors with full trace

### 5. Registration Response Enhancement
**Added document count to registration response:**
```php
$registration->load('documents');
$registration->refresh();

return response()->json([
    'success' => true,
    'message' => 'Vendor registration submitted successfully',
    'registration' => [
        'id' => $registration->id,
        'companyName' => $registration->company_name,
        'status' => $registration->status,
        'documentCount' => $documentCount, // NEW
    ]
], 201);
```

## Testing Checklist

### Test Vendor Registration with Documents

1. **Register a vendor with a single document:**
   ```bash
   POST /api/vendors/register
   Content-Type: multipart/form-data
   
   companyName: Test Company
   category: Transportation
   email: test@example.com
   documents: [single file]
   ```

2. **Register a vendor with multiple documents:**
   ```bash
   POST /api/vendors/register
   Content-Type: multipart/form-data
   
   companyName: Test Company 2
   category: Transportation
   email: test2@example.com
   documents[]: [file1]
   documents[]: [file2]
   ```

3. **Check registration was created:**
   ```bash
   GET /api/vendors/registrations
   ```
   - Should see the new registration
   - Should see documents in the response

4. **Check single registration:**
   ```bash
   GET /api/vendors/registrations/{id}
   ```
   - Should see documents array with proper URLs

5. **Check logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep -i "document\|vendor.*registration"
   ```
   - Should see "Storing documents for vendor registration"
   - Should see "Document stored successfully"
   - Should see "Updated registration with document metadata"

## Common Issues & Solutions

### Issue: Documents not appearing after registration

**Check:**
1. **Logs:** Look for errors in `storage/logs/laravel.log`
2. **Database:** Check if records exist:
   ```sql
   SELECT * FROM vendor_registration_documents WHERE vendor_registration_id = ?;
   SELECT documents FROM vendor_registrations WHERE id = ?;
   ```
3. **Storage:** Check if files exist:
   - Local: `storage/app/public/vendor_documents/`
   - S3: Check your S3 bucket

**Solution:**
- Ensure storage directory has write permissions: `chmod -R 755 storage/app/public`
- Check `DOCUMENTS_DISK` in `.env` is set correctly
- Verify AWS credentials if using S3

### Issue: "No documents provided" in logs

**Possible causes:**
1. Frontend not sending files correctly
2. Form data not using `multipart/form-data`
3. Field name mismatch (should be `documents` or `documents[]`)

**Solution:**
- Check frontend is using `FormData` for file uploads
- Verify `Content-Type: multipart/form-data` header
- Check field name matches exactly: `documents` or `documents[]`

### Issue: Documents stored but not visible

**Check:**
1. Relationship is loaded: `$registration->load('documents')`
2. JSON column has data: `$registration->documents`
3. Documents table has records

**Solution:**
- Clear cache: `php artisan cache:clear`
- Refresh registration: `$registration->refresh()`
- Check both relationship and JSON column

## Files Modified

1. **`app/Http/Controllers/Api/VendorController.php`**
   - Fixed document array normalization
   - Improved document retrieval logic
   - Added document count to response
   - Enhanced logging

2. **`app/Services/VendorDocumentService.php`**
   - Added storage directory creation
   - Improved error handling
   - Enhanced logging

## Next Steps

1. **Test registration** with both single and multiple documents
2. **Check logs** for any errors
3. **Verify documents** appear in procurement manager view
4. **Test document download** functionality

## Debugging Commands

```bash
# Check recent vendor registration logs
tail -n 100 storage/logs/laravel.log | grep -i "vendor\|document"

# Check database for documents
php artisan tinker
>>> $reg = \App\Models\VendorRegistration::find(1);
>>> $reg->documents; // Check relationship
>>> $reg->getAttribute('documents'); // Check JSON column
>>> \App\Models\VendorRegistrationDocument::where('vendor_registration_id', 1)->get();

# Check storage files
ls -la storage/app/public/vendor_documents/
```

## Expected Behavior

### After Registration:
1. Registration created in database
2. Documents stored in storage (S3 or local)
3. Document records created in `vendor_registration_documents` table
4. Document metadata saved to `documents` JSON column
5. Response includes `documentCount`

### When Viewing Registration:
1. Documents loaded from relationship (if available)
2. Fallback to JSON column if relationship empty
3. URLs generated for each document
4. Documents array returned in API response

### When Approving Vendor:
1. Documents moved to vendor-specific folder
2. Database records updated with new paths
3. Documents remain accessible
