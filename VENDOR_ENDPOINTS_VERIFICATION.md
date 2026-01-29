# Vendor Endpoints Verification

## Summary

Vendors can use **both** vendor-specific endpoints and main endpoints. Both approaches are supported and authenticated via Sanctum tokens.

## Authentication

- **Login Endpoint**: `POST /api/vendors/auth/login`
- **Token Type**: Sanctum Bearer Token (same as regular users)
- **Token Expiration**: 30 days
- **Token Format**: `Authorization: Bearer {token}`

Vendors authenticate as `User` accounts with `role='vendor'`. The token is created using Sanctum's `createToken()` method, so it works with the `auth:sanctum` middleware on all protected routes.

## Endpoint Options

### Option 1: Vendor-Specific Endpoints (RECOMMENDED)

These endpoints are purpose-built for vendors with vendor-specific features:

#### RFQs
- **Endpoint**: `GET /api/vendors/rfqs`
- **Controller**: `RFQWorkflowController::getVendorRFQs()`
- **Features**:
  - Only returns RFQs assigned to the vendor
  - Includes engagement tracking (sent_at, viewed_at, responded, responded_at)
  - Includes `has_submitted_quote` flag
  - Includes RFQ items
  - Vendor-specific error handling

#### Quotations
- **Endpoint**: `GET /api/vendors/quotations`
- **Controller**: `VendorController::getVendorQuotations()`
- **Features**:
  - Only returns vendor's own quotations
  - Includes RFQ and MRF relationships
  - Vendor-specific formatting
  - Supports filtering by status and RFQ ID

### Option 2: Main Endpoints (ALSO SUPPORTED)

These endpoints have been updated to support vendors:

#### RFQs
- **Endpoint**: `GET /api/rfqs`
- **Controller**: `RFQController::index()`
- **Behavior**: 
  - If user is a vendor, automatically filters to only RFQs assigned to them
  - If user is not a vendor, returns all RFQs (with optional filters)

#### Quotations
- **Endpoint**: `GET /api/quotations`
- **Controller**: `QuotationController::index()`
- **Behavior**:
  - If user is a vendor, automatically filters to only their quotations
  - If user is not a vendor, returns all quotations (with optional filters)

#### MRFs
- **Endpoint**: `GET /api/mrfs`
- **Controller**: `MRFController::index()`
- **Behavior**: 
  - If user is a vendor, returns empty array (vendors access MRFs through RFQs)
  - Employees see only their own MRFs

#### SRFs
- **Endpoint**: `GET /api/srfs`
- **Controller**: `SRFController::index()`
- **Behavior**:
  - If user is a vendor, returns empty array (vendors don't typically need SRFs)
  - Employees see only their own SRFs

## Recommendation

**Use vendor-specific endpoints** (`/api/vendors/rfqs` and `/api/vendors/quotations`) because:

1. ✅ Purpose-built for vendors with vendor-specific features
2. ✅ Explicit vendor role checking
3. ✅ Better error messages for vendor-specific issues
4. ✅ Includes engagement tracking and vendor-specific metadata
5. ✅ More maintainable and clear intent

## Troubleshooting 401 Errors

If vendors are getting 401 Unauthorized errors:

### 1. Verify Token is Being Sent
Check browser Network tab - request headers should include:
```
Authorization: Bearer {token}
```

### 2. Verify Login Endpoint
Vendors must use: `POST /api/vendors/auth/login` (NOT `/api/auth/login`)

### 3. Verify Token Format
The token should be the exact string returned from login response:
```json
{
  "success": true,
  "data": {
    "token": "1|xxxxxxxxxxxxx...",
    ...
  }
}
```

### 4. Verify CORS Configuration
Ensure CORS allows the `Authorization` header. Check `config/cors.php`:
- `allowed_headers` should include `Authorization`
- `allowed_origins` should include your frontend domain

### 5. Verify Vendor Status
Vendor must have status `'approved'` or `'active'` to login.

### 6. Verify User Account Exists
After vendor approval, a User account with `role='vendor'` and `vendor_id` must exist.

## Testing

To test vendor authentication:

1. **Login as vendor**:
   ```bash
   POST /api/vendors/auth/login
   {
     "email": "vendor@example.com",
     "password": "password"
   }
   ```

2. **Use token in requests**:
   ```bash
   GET /api/vendors/rfqs
   Authorization: Bearer {token}
   ```

3. **Verify response**:
   - Should return 200 OK with vendor's RFQs
   - Should NOT return 401 Unauthorized

## Code Verification

All endpoints are protected by `auth:sanctum` middleware in `routes/api.php`:

```php
Route::middleware('auth:sanctum')->group(function () {
    // All protected routes including:
    Route::get('/vendors/rfqs', ...);
    Route::get('/vendors/quotations', ...);
    Route::get('/rfqs', ...);
    Route::get('/quotations', ...);
    Route::get('/mrfs', ...);
    Route::get('/srfs', ...);
});
```

Vendor tokens are created using the same Sanctum method as regular users:
```php
$token = $user->createToken('vendor-auth-token', ['*'], $expiresAt)->plainTextToken;
```

Therefore, vendor tokens work with all `auth:sanctum` protected routes.
