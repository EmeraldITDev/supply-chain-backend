# Implementation Status - User Profile & Centralized OneDrive

## Summary

This is a comprehensive implementation request that includes:

1. **User Profile Feature** - Account settings management
2. **Centralized OneDrive Integration** - Use procurement@emeraldcfze.com account
3. **Vendor Document Handling** - Save vendor docs to procurement OneDrive
4. **Secure Sharing Links** - View-only links for PO documents

---

## Implementation Plan

### Phase 1: OneDrive Service Updates (Critical) ✅

**Current State:**
- OneDriveService uses `/me/drive` endpoint (requires user context)
- Uses client credentials flow (application permissions)
- Works with individual user accounts

**Required Changes:**
- Change endpoints from `/me/drive` to `/users/{email}/drive`
- Use `procurement@emeraldcfze.com` as the target user
- Add `ONEDRIVE_USER_EMAIL` environment variable
- Update all upload/download/delete methods

**Files to Update:**
- `app/Services/OneDriveService.php`
- `config/filesystems.php` (add ONEDRIVE_USER_EMAIL)
- `.env.example` (document new variable)

**Status:** ⏳ In Progress

---

### Phase 2: Sharing Links (Important) ⏳

**Requirements:**
- Create view-only sharing links for PO documents
- Share with organization members only
- Store sharing links in database
- Return sharing links in API responses

**Files to Update:**
- `app/Services/OneDriveService.php` (add `createSharingLink()` method)
- `app/Http/Controllers/Api/MRFWorkflowController.php` (use sharing links)
- Database migration (add `unsigned_po_share_url`, `signed_po_share_url` columns)

**Status:** ⏳ Pending

---

### Phase 3: Vendor Document Service (Important) ⏳

**Requirements:**
- Update VendorDocumentService to use OneDriveService
- Save vendor documents to: `VendorDocuments/{year}/{company-name}/`
- Update vendor registration flow

**Files to Update:**
- `app/Services/VendorDocumentService.php`
- `app/Http/Controllers/Api/VendorController.php` (or vendor registration controller)

**Status:** ⏳ Pending

---

### Phase 4: User Profile Enhancements (Nice to Have) ⏳

**Requirements:**
- Update personal information
- Change password (already exists, need to wire up)
- Manage connected services
- Better UI/UX

**Files to Update:**
- `src/pages/Settings.tsx` (enhance existing page)
- `src/services/api.ts` (add profile update endpoints)
- Backend: Add `PUT /auth/profile` endpoint

**Status:** ⏳ Pending

---

## Next Steps

1. ✅ Update OneDriveService to use `/users/{email}/drive`
2. ⏳ Add sharing link functionality
3. ⏳ Update vendor document service
4. ⏳ Enhance user profile page
5. ⏳ Test all workflows
6. ⏳ Update documentation

---

## Important Notes

### Azure AD Configuration

For centralized OneDrive access, you need:

1. **Application Permissions:**
   - `Files.ReadWrite.All` (Application permission)
   - Grant admin consent

2. **User Account:**
   - `procurement@emeraldcfze.com` must exist in your tenant
   - Account must have OneDrive enabled
   - Account should be a shared mailbox or service account

3. **Security:**
   - No password storage required (uses application permissions)
   - Works with MFA-enabled tenants
   - More secure than ROPC flow

### Endpoint Changes

**Old (User-specific):**
```
GET /me/drive/root:/path
PUT /me/drive/root:/path:/content
```

**New (Centralized Account):**
```
GET /users/procurement@emeraldcfze.com/drive/root:/path
PUT /users/procurement@emeraldcfze.com/drive/root:/path:/content
```

---

## Testing Checklist

- [ ] PO upload to procurement OneDrive
- [ ] Vendor document upload to procurement OneDrive
- [ ] Sharing links work (view-only)
- [ ] User profile updates work
- [ ] Password change works
- [ ] All documents accessible from procurement@emeraldcfze.com

---

## Estimated Implementation Time

- Phase 1 (OneDrive Updates): 2-3 hours
- Phase 2 (Sharing Links): 1-2 hours
- Phase 3 (Vendor Docs): 1-2 hours
- Phase 4 (User Profile): 1-2 hours
- Testing: 1-2 hours

**Total:** ~6-11 hours of development time
