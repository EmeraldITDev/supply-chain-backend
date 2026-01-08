# Executive Dashboard Authorization Update

## Summary
Extended authorization for procurement manager dashboard and vendor-related endpoints to allow access for executive-level roles.

## Changes Made

### 1. DashboardController - `/api/dashboard/procurement-manager`
**File:** `app/Http/Controllers/Api/DashboardController.php`

**Updated Method:** `procurementManagerDashboard()`

**Previous Authorization:**
- `procurement_manager`
- `admin`

**New Authorization:**
- `procurement_manager`
- `supply_chain_director`
- `supply_chain` (alias)
- `executive`
- `chairman`
- `admin`

**What This Means:**
Executive-level users can now access the procurement manager dashboard and will receive:
- `pending_vendor_registrations` - List of pending vendor registrations
- `pendingMRFs` - Pending Material Request Forms
- `pendingSRFs` - Pending Service Request Forms
- `pendingQuotations` - Pending vendor quotations
- Dashboard statistics including vendor metrics, ratings, and on-time delivery

---

### 2. VendorController - Vendor Registration Endpoints

**File:** `app/Http/Controllers/Api/VendorController.php`

#### 2.1 GET `/api/vendors/registrations/pending` & `/api/vendors/registrations`
**Method:** `registrations()`

**Authorization:** Now includes executive-level roles

#### 2.2 GET `/api/vendors/registrations/{id}`
**Method:** `getRegistration()`

**Authorization:** Now includes executive-level roles

#### 2.3 POST `/api/vendors/registrations/{id}/approve`
**Method:** `approveRegistration()`

**Authorization:** Now includes executive-level roles
- Executives can approve vendor registrations
- System creates vendor account and user credentials
- Sends email with login details

#### 2.4 POST `/api/vendors/registrations/{id}/reject`
**Method:** `rejectRegistration()`

**Authorization:** Now includes executive-level roles
- Executives can reject vendor registrations
- Requires rejection reason

#### 2.5 PUT `/api/vendors/{id}/credentials`
**Method:** `updateVendorCredentials()`

**Authorization:** Now includes executive-level roles
- Executives can reset vendor passwords
- Forces password change on next login

---

### 3. Vendor List Endpoint - GET `/api/vendors`
**No changes needed** - This endpoint is already accessible to all authenticated users with supply chain access.

---

## Role Definitions

### Authorized Executive Roles:
1. **executive** - Executive-level management
2. **chairman** - Board chairman
3. **supply_chain_director** - Supply chain director
4. **supply_chain** - Alias for supply_chain_director (for compatibility)

### Also Authorized (As Before):
- **procurement_manager** - Primary procurement management role
- **admin** - System administrator

---

## Testing Guide

### Prerequisites
1. User must have one of the authorized roles assigned
2. User must be authenticated via Sanctum (valid Bearer token)

### Test Case 1: Procurement Manager Dashboard
```bash
# Request
GET /api/dashboard/procurement-manager
Authorization: Bearer {token}

# Expected Response (200 OK)
{
  "success": true,
  "stats": {
    "pendingRegistrations": 5,
    "pendingMRFs": 12,
    "pendingSRFs": 8,
    "pendingQuotations": 15,
    "totalVendors": 45,
    "pendingKYC": 5,
    "awaitingReview": 5,
    "avgRating": 4.2,
    "onTimeDelivery": 87.5
  },
  "pendingRegistrations": [...],
  "pendingMRFs": [...],
  "pendingSRFs": [...],
  "pendingQuotations": [...]
}
```

### Test Case 2: Vendor Registrations List
```bash
# Request
GET /api/vendors/registrations?status=Pending
Authorization: Bearer {token}

# Expected Response (200 OK)
{
  "success": true,
  "data": [
    {
      "id": "1",
      "companyName": "ABC Corp",
      "category": "IT Services",
      "email": "contact@abccorp.com",
      "status": "Pending",
      ...
    }
  ]
}
```

### Test Case 3: Approve Vendor Registration
```bash
# Request
POST /api/vendors/registrations/{id}/approve
Authorization: Bearer {token}
Content-Type: application/json

{
  "remarks": "Approved - all documents verified"
}

# Expected Response (200 OK)
{
  "success": true,
  "message": "Vendor registration approved. User account created and email sent.",
  "vendor": {...},
  "user": {...},
  "registration": {...}
}
```

### Test Case 4: Unauthorized Access (Wrong Role)
```bash
# Request from user with 'logistics_manager' role
GET /api/dashboard/procurement-manager
Authorization: Bearer {token}

# Expected Response (403 Forbidden)
{
  "success": false,
  "error": "Insufficient permissions",
  "code": "FORBIDDEN"
}
```

---

## Data Consistency

### Dashboard Data Structure
The data returned to executive roles is **identical** to what procurement managers receive. No fields are hidden or modified based on role - all authorized users see the complete dashboard data.

### Fields Included:
- ✅ `pending_vendor_registrations` - Full list with documents
- ✅ All vendor statistics
- ✅ Pending MRFs, SRFs, Quotations
- ✅ Vendor ratings and metrics
- ✅ On-time delivery percentages

---

## Security Notes

1. **Authentication Required:** All endpoints still require valid Sanctum authentication
2. **Role-Based Access:** Authorization is checked at the controller level
3. **No Middleware Changes:** The `auth:sanctum` middleware remains unchanged
4. **Login Access:** Executive roles can already log in (verified in `AuthController::hasSupplyChainAccess`)
5. **Audit Trail:** All approvals/rejections are logged with user ID

---

## Rollback Instructions

If you need to rollback these changes:

1. In `DashboardController.php`, line 24-25:
   ```php
   // Change back to:
   if (!in_array($user->role, ['procurement_manager', 'admin'])) {
   ```

2. In `VendorController.php`, lines 147, 219, 297, 392, 458:
   ```php
   // Change back to:
   if (!in_array($user->role, ['procurement_manager', 'supply_chain_director', 'admin'])) {
   ```

---

## Related Files Modified
1. ✅ `app/Http/Controllers/Api/DashboardController.php`
2. ✅ `app/Http/Controllers/Api/VendorController.php`

## Files NOT Modified (No Changes Needed)
- `routes/api.php` - Route definitions unchanged
- `app/Http/Controllers/Api/AuthController.php` - Already allows executive access
- `app/Models/User.php` - Role handling unchanged

---

## Deployment Notes

### Pre-Deployment Checklist:
- [x] Code changes completed
- [x] No linting errors
- [ ] Backend tests passing (if applicable)
- [ ] Frontend updated to support executive dashboard access
- [ ] Database has executive roles seeded

### Post-Deployment Verification:
1. Test login with executive role
2. Verify dashboard returns full data
3. Test vendor registration approval/rejection
4. Confirm audit logs capture executive actions

---

## Support

If executive users still receive empty responses:
1. ✅ Verify user has correct role assigned in database
2. ✅ Check authentication token is valid
3. ✅ Confirm user passes `hasSupplyChainAccess()` check in AuthController
4. ✅ Review application logs for any error messages

---

*Updated: January 8, 2026*
*Version: 1.0*
