# Centralized OneDrive Integration Plan

## Overview

All procurement-related documents will be stored in a centralized OneDrive account: **procurement@emeraldcfze.com**

This ensures:
- ✅ Continuity (not dependent on individual user accounts)
- ✅ Proper ownership (shared organizational account)
- ✅ Controlled access (single point of management)
- ✅ Organized structure (all documents in one place)

---

## Authentication Approach

### Option 1: Resource Owner Password Credentials (ROPC) Flow ⚠️
**Pros:**
- Simple to implement
- Direct access to user's OneDrive

**Cons:**
- Requires storing password in environment variables (security risk)
- Not recommended by Microsoft for production
- May be disabled for tenants with MFA

**Implementation:**
- Store procurement@emeraldcfze.com credentials in `.env`
- Use ROPC flow to get access tokens
- Access `/users/{user-id}/drive` endpoint

### Option 2: App-Only Token with SharePoint (Recommended for Production) ✅
**Pros:**
- No password storage required
- More secure
- Works with MFA-enabled tenants

**Cons:**
- Requires SharePoint site (or admin consent for all sites)
- More complex setup

**Implementation:**
- Use application permissions (client credentials)
- Access SharePoint document library
- Requires `Sites.ReadWrite.All` permission

### Option 3: Refresh Token Storage (Best Long-term) ✅✅
**Pros:**
- Most secure
- Works with MFA
- No password storage

**Cons:**
- Requires initial interactive authentication
- Token refresh logic needed

**Implementation:**
- Initial setup: Interactive OAuth flow to get refresh token
- Store refresh token securely
- Use refresh token to get access tokens

---

## Recommended Implementation (ROPC for MVP, Migrate to Option 2/3)

For now, we'll implement **Option 1 (ROPC)** for quick deployment, with documentation on migrating to Option 2/3 later.

---

## Folder Structure on Procurement OneDrive

```
SupplyChainDocs/
├── PurchaseOrders/
│   ├── 2026/
│   │   ├── 01/
│   │   │   ├── PO_PO-2026-01-001_MRF-2026-001.pdf
│   │   │   └── PO_PO-2026-01-002_MRF-2026-002.pdf
│   │   └── 02/
│   └── 2025/
├── PurchaseOrders_Signed/
│   ├── 2026/
│   │   ├── 01/
│   │   │   ├── PO_Signed_PO-2026-01-001_MRF-2026-001.pdf
│   │   │   └── PO_Signed_PO-2026-01-002_MRF-2026-002.pdf
│   │   └── 02/
│   └── 2025/
└── VendorDocuments/
    ├── 2026/
    │   ├── VENDOR-ABC-Company/
    │   │   ├── CAC_Certificate.pdf
    │   │   ├── TIN_Certificate.pdf
    │   │   ├── Bank_Statement.pdf
    │   │   └── HSE_Certificate.pdf
    │   └── VENDOR-XYZ-Company/
    └── 2025/
```

---

## Secure Sharing Links

For PO documents and internal documents:
- Create view-only sharing links
- Share with organization members only
- Links expire after set period (optional)
- Store sharing link in database

---

## Implementation Steps

1. ✅ Update OneDriveService to use ROPC flow
2. ✅ Update MRFWorkflowController to use centralized account
3. ✅ Update VendorDocumentService to use OneDrive
4. ✅ Add sharing link functionality
5. ✅ Update frontend to show sharing links
6. ✅ Add User Profile page enhancements
7. ✅ Add API endpoints for profile updates
