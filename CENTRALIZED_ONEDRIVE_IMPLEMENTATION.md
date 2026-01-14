# Centralized OneDrive Integration - Implementation Guide

## Overview

All procurement documents are now stored in the centralized procurement account: **procurement@emeraldcfze.com**

This implementation uses **application permissions** (client credentials flow) to access the procurement account's OneDrive without storing passwords.

---

## Key Changes

### 1. OneDriveService Updates

**Changed endpoints:**
- From: `/me/drive/root:...` (user-specific)
- To: `/users/procurement@emeraldcfze.com/drive/root:...` (specific user account)

**Authentication:**
- Uses client credentials flow (application permissions)
- No password storage required
- Requires `Files.ReadWrite.All` application permission

**Configuration:**
- `ONEDRIVE_USER_EMAIL=procurement@emeraldcfze.com` (environment variable)

### 2. Folder Structure

```
SupplyChainDocs/
├── PurchaseOrders/
│   └── {year}/{month}/...
├── PurchaseOrders_Signed/
│   └── {year}/{month}/...
└── VendorDocuments/
    └── {year}/{company-name}/...
```

### 3. Secure Sharing Links

- Create view-only sharing links for PO documents
- Share with organization members only
- Links stored in database (`unsigned_po_share_url`, `signed_po_share_url`)

---

## Azure AD Configuration

### Required Permissions

1. Go to Azure Portal → Azure Active Directory → App registrations
2. Select your app registration
3. Go to **API permissions**
4. Add **Microsoft Graph** → **Application permissions**
5. Select:
   - `Files.ReadWrite.All` - Read and write files in all site collections
6. Click **Grant admin consent**

### Environment Variables

```env
MICROSOFT_CLIENT_ID=your_client_id
MICROSOFT_CLIENT_SECRET=your_client_secret
MICROSOFT_TENANT_ID=your_tenant_id
ONEDRIVE_USER_EMAIL=procurement@emeraldcfze.com
ONEDRIVE_ROOT_FOLDER=/SupplyChainDocs
```

---

## Benefits

✅ **No Password Storage** - Uses application permissions  
✅ **Secure** - Microsoft recommended approach  
✅ **Works with MFA** - Not affected by MFA policies  
✅ **Centralized** - All documents in one account  
✅ **Controlled Access** - Single point of management  
✅ **Organized** - Clear folder structure  

---

## Migration Notes

**Existing Implementation:**
- Previously used `/me/drive` (logged-in user's drive)
- This won't work with client credentials flow

**New Implementation:**
- Uses `/users/{email}/drive` (specific user's drive)
- Works with application permissions
- No user login required

---

## Vendor Document Handling

**Automatic Upload to OneDrive:**
- Vendor registration documents uploaded to: `VendorDocuments/{year}/{company-name}/`
- Accessible via procurement@emeraldcfze.com account
- Procurement team can view directly in OneDrive

---

## Testing

1. **Test PO Upload:**
   - Generate PO → Verify upload to OneDrive
   - Check folder structure: `PurchaseOrders/2026/01/`
   - Verify sharing link is created

2. **Test Vendor Document Upload:**
   - Register vendor → Upload documents
   - Verify upload to: `VendorDocuments/2026/{company-name}/`
   - Check procurement account can access files

3. **Test Sharing Links:**
   - Generate PO → Check `unsigned_po_share_url` in database
   - Click link → Verify view-only access works
   - Test link expiration (if implemented)

---

## Troubleshooting

### Error: "Access denied"
- **Cause**: Application permissions not granted
- **Fix**: Grant admin consent in Azure AD

### Error: "User not found"
- **Cause**: Email address incorrect
- **Fix**: Verify `ONEDRIVE_USER_EMAIL` matches exact email

### Error: "Insufficient privileges"
- **Cause**: Missing `Files.ReadWrite.All` permission
- **Fix**: Add permission and grant admin consent

---

## Next Steps

- [ ] Update OneDriveService to use `/users/{email}/drive`
- [ ] Update VendorDocumentService to use OneDrive
- [ ] Add sharing link functionality
- [ ] Update frontend to show sharing links
- [ ] Add User Profile page enhancements
- [ ] Test all workflows
