# PO Clearance and System Improvements

## Summary of Changes

This document outlines the improvements made to address PO number conflicts, enhance PO generation workflow, and fix document access issues.

## 1. Clear All PO Numbers

A SQL script has been created to clear all existing PO numbers from the database:

**File:** `CLEAR_ALL_POS.sql`

**To execute:**
1. Connect to your PostgreSQL database
2. Run the SQL script:
   ```bash
   psql -h your-host -U your-user -d your-database -f CLEAR_ALL_POS.sql
   ```

**What it does:**
- Clears all `po_number` values
- Removes PO file URLs (unsigned and signed)
- Resets PO timestamps
- Resets PO version to 1
- Moves MRFs in `supply_chain` or `PO Rejected` status back to `procurement` stage

**Important:** This will allow you to regenerate POs without conflicts.

## 2. Enhanced PO Generation Dialog

**File:** `src/components/POGenerationDialog.tsx`

**Improvements:**
- **Full MRF Details Display**: Now shows all MRF request information including:
  - MRF ID
  - Requester name
  - Title
  - Category
  - Urgency
  - Quantity
  - **Estimated Cost (highlighted in primary color)** - This is now prominently displayed so you know what amount to enter
  - Description (full text)
  - Justification (full text)
  - Department (if available)
  - Request Date

- **Better Layout**: Information is organized in a clear, readable format with proper spacing and labels

## 3. PO Download Functionality

**File:** `src/pages/Procurement.tsx`

**Added:**
- "Download PO" button appears next to MRFs that have generated POs
- Downloads work for both OneDrive URLs and local/S3 storage
- Button is visible in the MRF list for easy access

**Usage:**
- Click "Download PO" button next to any MRF that has a generated PO
- The document will open in a new tab/window for viewing or downloading

## 4. Document Access Fixes

**Files Modified:**
- `app/Http/Controllers/Api/VendorController.php`
- `app/Services/VendorDocumentService.php`

**Improvements:**
- **OneDrive Integration**: Documents stored in OneDrive now properly redirect to share URLs
- **All File Types Supported**: The system now handles all file types (PDF, DOC, DOCX, images, etc.)
- **Proper MIME Types**: Content-Type headers are correctly set based on file extension
- **Fallback Handling**: If OneDrive is unavailable, falls back to local/S3 storage

**How it works:**
1. When a document is uploaded, it's saved to OneDrive (if configured)
2. A sharing link is created for the document
3. The sharing link is stored in the database
4. When downloading:
   - If a OneDrive share URL exists, the system redirects to it
   - Otherwise, it serves the file from local/S3 storage
   - Proper content-type headers ensure files download correctly

## 5. OneDrive Document Storage

**Already Implemented:**
- All vendor registration documents are automatically uploaded to OneDrive
- Documents are organized by year and company name: `VendorDocuments/{year}/{companyName}/`
- Sharing links are created for all documents (view-only access)
- Works for all file types (PDF, DOC, DOCX, images, etc.)

## Next Steps

1. **Run the SQL script** to clear existing PO numbers:
   ```bash
   psql -h your-host -U your-user -d your-database -f CLEAR_ALL_POS.sql
   ```

2. **Test PO Generation:**
   - Generate a new PO and verify the MRF details are fully visible
   - Check that the estimated cost is displayed prominently
   - Verify you can download the generated PO

3. **Test Document Access:**
   - Upload a vendor document (any file type)
   - Verify it's accessible via the "View on OneDrive" button
   - Test downloading non-PDF documents

4. **Verify OneDrive Integration:**
   - Check that documents are appearing in OneDrive
   - Verify sharing links work correctly
   - Ensure all file types are being saved

## Troubleshooting

### PO Number Still Shows as Taken
- Make sure you've run the SQL script to clear existing PO numbers
- Check that the MRF status has been reset to 'procurement'
- Try generating a PO with a different number or let it auto-generate

### Documents Still Return 404
- Check that OneDrive is properly configured in your `.env` file
- Verify the `file_share_url` is stored in the database
- Check backend logs for OneDrive upload errors
- Ensure the OneDrive app registration has proper permissions

### Documents Not Appearing in OneDrive
- Verify OneDrive credentials in `.env`:
  - `ONEDRIVE_CLIENT_ID`
  - `ONEDRIVE_CLIENT_SECRET`
  - `ONEDRIVE_TENANT_ID`
  - `ONEDRIVE_USER_EMAIL`
- Check backend logs for OneDrive upload errors
- Ensure the app registration email matches the OneDrive account
