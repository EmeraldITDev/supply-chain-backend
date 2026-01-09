# API Quick Reference - Supply Chain Management

## Vendor Onboarding & Communication Endpoints

### Send Vendor Invitation
```http
POST /api/vendors/invite
Authorization: Bearer {admin_token}
Content-Type: application/json

{
  "email": "vendor@example.com",
  "company_name": "ABC Supplies Ltd"
}

Response: 200 OK
```

### Get Vendor Profile (Vendor)
```http
GET /api/vendors/auth/profile
Authorization: Bearer {vendor_token}

Response: 200 OK
{
  "success": true,
  "data": {
    "vendor": {...},
    "user": {...}
  }
}
```

### Update Vendor Profile (Vendor)
```http
PUT /api/vendors/auth/profile
Authorization: Bearer {vendor_token}
Content-Type: application/json

{
  "contact_person": "Jane Smith",
  "phone": "+1234567890",
  "address": "123 Business St",
  "email": "newemail@example.com"
}

Response: 200 OK
```

### Change Password (Vendor)
```http
POST /api/vendors/auth/change-password
Authorization: Bearer {vendor_token}
Content-Type: application/json

{
  "current_password": "OldPass123!",
  "new_password": "NewPass123!",
  "new_password_confirmation": "NewPass123!"
}

Response: 200 OK
```

### Request Password Reset (Public)
```http
POST /api/vendors/auth/password-reset
Content-Type: application/json

{
  "email": "vendor@example.com"
}

Response: 200 OK
```

---

## Vendor Rating & Comments Endpoints

### Add Rating/Comment
```http
POST /api/vendors/{id}/rating
Authorization: Bearer {token}
Content-Type: application/json

{
  "rating": 5,
  "comment": "Excellent service and quality"
}

Response: 201 Created
```

### Get Vendor Comments
```http
GET /api/vendors/{id}/comments
Authorization: Bearer {token}

Response: 200 OK
{
  "success": true,
  "data": [
    {
      "id": 1,
      "comment": "Great vendor",
      "rating": 5,
      "createdAt": "2026-01-09T10:00:00Z",
      "createdBy": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
      }
    }
  ],
  "vendorRating": 4.5,
  "totalComments": 10
}
```

---

## Vendor Management Endpoints

### Get All Vendors
```http
GET /api/vendors
Authorization: Bearer {token}

Query Parameters:
- status: Active|Inactive
- category: string

Response: 200 OK
```

### Get Vendor by ID
```http
GET /api/vendors/{id}
Authorization: Bearer {token}

Response: 200 OK
```

### Delete Vendor
```http
DELETE /api/vendors/{id}
Authorization: Bearer {token}

Response: 200 OK
```

---

## Vendor Registration Endpoints

### Register Vendor (Public)
```http
POST /api/vendors/register
Content-Type: multipart/form-data

{
  "company_name": "ABC Supplies",
  "email": "vendor@example.com",
  "phone": "+1234567890",
  "address": "123 Business St",
  "tax_id": "TAX123456",
  "contact_person": "John Doe",
  "category": "Electronics",
  "business_license": file,
  "tax_certificate": file
}

Response: 201 Created
```

### Get All Registrations
```http
GET /api/vendors/registrations
Authorization: Bearer {token}

Query Parameters:
- status: Pending|Approved|Rejected

Response: 200 OK
```

### Get Registration by ID
```http
GET /api/vendors/registrations/{id}
Authorization: Bearer {token}

Response: 200 OK
```

### Approve Registration
```http
POST /api/vendors/registrations/{id}/approve
Authorization: Bearer {token}
Content-Type: application/json

{
  "notes": "All documents verified"
}

Response: 200 OK
```

### Reject Registration
```http
POST /api/vendors/registrations/{id}/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Incomplete documentation"
}

Response: 200 OK
```

### Download Registration Document
```http
GET /api/vendors/registrations/{registrationId}/documents/{documentId}/download
Authorization: Bearer {token}

Response: File download
```

---

## Authentication Endpoints

### Login (Internal Users)
```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}

Response: 200 OK
{
  "user": {...},
  "token": "...",
  "token_type": "Bearer"
}
```

### Login (Vendors)
```http
POST /api/vendors/auth/login
Content-Type: application/json

{
  "email": "vendor@example.com",
  "password": "password123"
}

Response: 200 OK
```

### Logout
```http
POST /api/auth/logout
Authorization: Bearer {token}

Response: 200 OK
```

### Get Current User
```http
GET /api/auth/me
Authorization: Bearer {token}

Response: 200 OK
```

---

## MRF (Material Requisition Form) Endpoints

### Get All MRFs
```http
GET /api/mrfs
Authorization: Bearer {token}

Query Parameters:
- status: Draft|Pending|Approved|Rejected
- department: string

Response: 200 OK
```

### Get MRF by ID
```http
GET /api/mrfs/{id}
Authorization: Bearer {token}

Response: 200 OK
```

### Create MRF
```http
POST /api/mrfs
Authorization: Bearer {token}
Content-Type: application/json

{
  "department": "IT",
  "requested_by": "John Doe",
  "items": [
    {
      "description": "Laptop",
      "quantity": 5,
      "unit": "pieces",
      "purpose": "New employees"
    }
  ]
}

Response: 201 Created
```

### Update MRF
```http
PUT /api/mrfs/{id}
Authorization: Bearer {token}
Content-Type: application/json

Response: 200 OK
```

### Approve MRF
```http
POST /api/mrfs/{id}/approve
Authorization: Bearer {token}

Response: 200 OK
```

### Reject MRF
```http
POST /api/mrfs/{id}/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Budget constraints"
}

Response: 200 OK
```

---

## SRF (Supplier Requisition Form) Endpoints

### Get All SRFs
```http
GET /api/srfs
Authorization: Bearer {token}

Response: 200 OK
```

### Create SRF
```http
POST /api/srfs
Authorization: Bearer {token}
Content-Type: application/json

Response: 201 Created
```

### Update SRF
```http
PUT /api/srfs/{id}
Authorization: Bearer {token}

Response: 200 OK
```

---

## RFQ (Request for Quotation) Endpoints

### Get All RFQs
```http
GET /api/rfqs
Authorization: Bearer {token}

Response: 200 OK
```

### Create RFQ
```http
POST /api/rfqs
Authorization: Bearer {token}
Content-Type: application/json

Response: 201 Created
```

### Update RFQ
```http
PUT /api/rfqs/{id}
Authorization: Bearer {token}

Response: 200 OK
```

---

## Quotation Endpoints

### Get All Quotations
```http
GET /api/quotations
Authorization: Bearer {token}

Response: 200 OK
```

### Submit Quotation
```http
POST /api/quotations
Authorization: Bearer {token}
Content-Type: application/json

Response: 201 Created
```

### Approve Quotation
```http
POST /api/quotations/{id}/approve
Authorization: Bearer {token}

Response: 200 OK
```

### Reject Quotation
```http
POST /api/quotations/{id}/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Price too high"
}

Response: 200 OK
```

---

## Dashboard Endpoints

### Procurement Manager Dashboard
```http
GET /api/dashboard/procurement-manager
Authorization: Bearer {token}

Response: 200 OK
```

### Supply Chain Director Dashboard
```http
GET /api/dashboard/supply-chain-director
Authorization: Bearer {token}

Response: 200 OK
```

### Vendor Dashboard
```http
GET /api/dashboard/vendor
Authorization: Bearer {vendor_token}

Response: 200 OK
```

---

## User Roles & Permissions

### Admin Roles
- `admin` - Full system access
- `chairman` - Executive access
- `executive` - High-level access
- `supply_chain_director` / `supply_chain` - Supply chain management
- `procurement_manager` - Procurement operations

### Employee Roles
- `employee` - Basic employee access
- `general_employee` - General access

### Vendor Role
- `vendor` - Vendor portal access

---

## Response Format

### Success Response
```json
{
  "success": true,
  "message": "Operation successful",
  "data": {...}
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message",
  "code": "ERROR_CODE",
  "errors": {
    "field": ["Validation error"]
  }
}
```

### Common Error Codes
- `FORBIDDEN` (403) - Insufficient permissions
- `NOT_FOUND` (404) - Resource not found
- `VALIDATION_ERROR` (422) - Invalid input
- `UNAUTHORIZED` (401) - Not authenticated
- `ALREADY_RATED` (409) - Duplicate rating
- `VENDOR_EXISTS` (409) - Vendor already exists
- `EMAIL_FAILED` (500) - Email delivery failed

---

## Authentication

All protected endpoints require Bearer token:

```
Authorization: Bearer {your_token_here}
```

Get token from login endpoints:
- Internal users: `POST /api/auth/login`
- Vendors: `POST /api/vendors/auth/login`

---

## Base URL

Development: `http://localhost:8000/api`  
Production: `https://your-domain.com/api`

---

## Rate Limiting

- Default: 60 requests per minute per IP
- Authenticated: 100 requests per minute per user

---

## File Uploads

Use `multipart/form-data` for file uploads:
- Max file size: 10MB
- Allowed types: PDF, JPG, PNG, JPEG
- Vendor registration requires: business_license, tax_certificate

---

## Pagination

List endpoints support pagination:

```http
GET /api/vendors?page=1&per_page=20
```

Response includes:
```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 100
  }
}
```

---

## Testing

### Using cURL

```bash
# Login
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Get vendors
curl -X GET http://localhost:8000/api/vendors \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Using Postman/Insomnia

1. Import the API collection
2. Set environment variables:
   - `base_url`: http://localhost:8000/api
   - `token`: (auto-set from login)
3. Use `{{base_url}}` and `{{token}}` in requests

---

**Last Updated:** January 9, 2026  
**Version:** 1.0

For detailed documentation, see:
- `VENDOR_ONBOARDING_API.md` - Vendor onboarding & email features
- `VENDOR_MANAGEMENT_API.md` - Vendor management features
- `VENDOR_RATINGS_API.md` - Rating & comments features
- `EMAIL_SETUP_GUIDE.md` - Email configuration guide
