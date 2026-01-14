# OneDrive Integration - Implementation Summary

## ✅ Implementation Complete

OneDrive integration has been successfully implemented for the Supply Chain Management system. All PO documents (unsigned and signed) are now stored on OneDrive, and users can view them directly via "View on OneDrive" links.

---

## 📦 What Was Implemented

### **Backend (Laravel)**

1. **✅ OneDriveService** (`app/Services/OneDriveService.php`)
   - Microsoft Graph API integration
   - Client credentials authentication (OAuth2)
   - File upload (simple <4MB, chunked >4MB)
   - File download/delete
   - Web URL generation
   - Folder management
   - Token caching (50 minutes)

2. **✅ Filesystem Configuration** (`config/filesystems.php`)
   - Added `onedrive` disk configuration
   - Supports environment variables for credentials

3. **✅ MRFWorkflowController Updates**
   - `generatePO()` - Uploads unsigned POs to OneDrive
   - `uploadSignedPO()` - Uploads signed POs to OneDrive
   - Automatic fallback to local/S3 storage if OneDrive fails
   - Organized folder structure: `PurchaseOrders/YYYY/MM/`

### **Frontend (React/TypeScript)**

1. **✅ OneDriveLink Component** (`src/components/OneDriveLink.tsx`)
   - Reusable component for "View on OneDrive" links
   - Three variants: `button`, `badge`, `link`
   - Click opens OneDrive in new tab
   - Handles missing URLs gracefully

2. **✅ Supply Chain Dashboard** (`src/pages/SupplyChainDashboard.tsx`)
   - Added OneDrive badge next to "Download PO" button
   - Shows "On OneDrive" badge when PO URL is available

---

## 🔧 Configuration Required

### **Environment Variables (Backend)**

Add these to your `.env` file:

```env
# Microsoft OneDrive Configuration
MICROSOFT_CLIENT_ID=your_client_id_from_azure
MICROSOFT_CLIENT_SECRET=your_client_secret_from_azure
MICROSOFT_TENANT_ID=your_tenant_id_from_azure

# OneDrive Settings (Optional - defaults provided)
ONEDRIVE_ROOT_FOLDER=/SupplyChainDocs
```

**For Production (Render):**
- Add these environment variables in Render dashboard
- Clear config cache: `php artisan config:clear`

---

## 📂 Folder Structure on OneDrive

```
SupplyChainDocs/
├── PurchaseOrders/
│   ├── 2026/
│   │   ├── 01/
│   │   │   ├── PO_PO-2026-01-001_MRF-2026-001.pdf
│   │   │   └── PO_PO-2026-01-002_MRF-2026-002.pdf
│   │   └── 02/
│   └── 2025/
└── PurchaseOrders_Signed/
    ├── 2026/
    │   ├── 01/
    │   │   ├── PO_Signed_PO-2026-01-001_MRF-2026-001.pdf
    │   │   └── PO_Signed_PO-2026-01-002_MRF-2026-002.pdf
    │   └── 02/
    └── 2025/
```

---

## 🚀 How It Works

### **PO Generation Flow**

1. **Procurement Manager generates PO:**
   - Uploads PO document via form
   - Backend checks if OneDrive is configured
   - If configured: Uploads to `PurchaseOrders/YYYY/MM/` on OneDrive
   - If not configured: Falls back to local/S3 storage
   - Stores OneDrive web URL in database (`unsigned_po_url`)

2. **Supply Chain Director views PO:**
   - Sees "Download PO" button (for local download)
   - Sees "On OneDrive" badge (click to view on OneDrive)
   - Clicks badge → Opens OneDrive in new tab

3. **Supply Chain Director uploads signed PO:**
   - Uploads signed PO via form
   - Backend uploads to `PurchaseOrders_Signed/YYYY/MM/` on OneDrive
   - Stores OneDrive web URL in database (`signed_po_url`)

---

## 🔐 Authentication Setup (Azure AD)

**Prerequisites:**
1. Microsoft 365 Business Account
2. Azure AD App Registration

**Steps:**
1. Go to [Azure Portal](https://portal.azure.com)
2. Azure Active Directory → App registrations → New registration
3. Configure:
   - **Name**: `Supply Chain Backend`
   - **Supported account types**: `Accounts in this organizational directory only`
   - **Redirect URI**: `https://your-backend-url.com/auth/microsoft/callback` (optional for client credentials)
4. After registration:
   - Copy **Application (client) ID**
   - Copy **Directory (tenant) ID**
5. Go to **Certificates & secrets**:
   - Create new client secret
   - Copy secret value (shown only once!)
6. Go to **API permissions**:
   - Add permission → Microsoft Graph → Application permissions
   - Select:
     - `Files.ReadWrite.All` (Read and write files)
     - `Sites.ReadWrite.All` (if using SharePoint)
   - Click **Grant admin consent**
7. Add credentials to `.env` file

---

## ✨ Features

✅ **Automatic Upload** - Files automatically uploaded to OneDrive  
✅ **Organized Structure** - Folders organized by year/month  
✅ **Fallback Support** - Falls back to local/S3 if OneDrive fails  
✅ **Web URLs** - Direct links to view files on OneDrive  
✅ **Version History** - OneDrive tracks file versions  
✅ **Mobile Access** - Access files via OneDrive mobile app  
✅ **Unlimited Storage** - Uses your Microsoft 365 storage  
✅ **Secure** - Enterprise-grade security from Microsoft  

---

## 🧪 Testing

1. **Test PO Generation:**
   - Generate a new PO
   - Check logs for OneDrive upload confirmation
   - Verify `unsigned_po_url` contains OneDrive URL
   - Click "On OneDrive" badge → Should open OneDrive

2. **Test Signed PO Upload:**
   - Upload signed PO
   - Check logs for OneDrive upload confirmation
   - Verify `signed_po_url` contains OneDrive URL

3. **Test Fallback:**
   - Remove OneDrive credentials from `.env`
   - Generate PO → Should use local/S3 storage
   - Check logs for fallback confirmation

---

## 🐛 Troubleshooting

### **Error: "Failed to authenticate with OneDrive"**
- **Cause**: Invalid credentials or missing permissions
- **Fix**: 
  - Verify `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT_ID`
  - Check Azure AD app permissions (grant admin consent)
  - Verify token URL format

### **Error: "OneDrive upload failed, falling back to local storage"**
- **Cause**: Network issue, permissions, or API error
- **Fix**: 
  - Check logs for detailed error
  - Verify API permissions in Azure AD
  - Check network connectivity
  - System will automatically fall back to local/S3 storage

### **OneDrive badge not showing**
- **Cause**: URL not in database or component not imported
- **Fix**: 
  - Verify `unsigned_po_url` or `signed_po_url` contains OneDrive URL
  - Check component import: `import { OneDriveLink } from "@/components/OneDriveLink";`

### **Token Expired**
- **Cause**: Access token expires after 1 hour
- **Fix**: 
  - Tokens are automatically cached and refreshed
  - Cache duration: 50 minutes (before 1-hour expiry)
  - Clear cache: `php artisan cache:clear`

---

## 📝 Next Steps (Optional Enhancements)

- [ ] Add OneDrive links to Finance Dashboard
- [ ] Add OneDrive links to Procurement Dashboard (PO list)
- [ ] Add OneDrive integration for vendor documents
- [ ] Add folder sharing capabilities
- [ ] Add OneDrive search integration
- [ ] Add file preview in modal (instead of opening new tab)

---

## 📚 Related Files

**Backend:**
- `app/Services/OneDriveService.php`
- `app/Http/Controllers/Api/MRFWorkflowController.php`
- `config/filesystems.php`
- `ONEDRIVE_INTEGRATION_GUIDE.md` (detailed setup guide)

**Frontend:**
- `src/components/OneDriveLink.tsx`
- `src/pages/SupplyChainDashboard.tsx`
- `src/types/index.ts` (MRF interface includes URL fields)

---

## ✅ Implementation Status

| Component | Status |
|-----------|--------|
| OneDriveService | ✅ Complete |
| MRFWorkflowController (generatePO) | ✅ Complete |
| MRFWorkflowController (uploadSignedPO) | ✅ Complete |
| OneDriveLink Component | ✅ Complete |
| Supply Chain Dashboard Integration | ✅ Complete |
| Configuration | ✅ Complete |
| Documentation | ✅ Complete |

**Ready for deployment!** 🚀
