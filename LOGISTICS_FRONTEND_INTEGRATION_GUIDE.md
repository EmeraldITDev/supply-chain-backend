# Logistics Module - Quick Start Guide for Frontend

**Backend Status:** ✅ Production Ready  
**Last Updated:** February 4, 2026

---

## Quick Links

- **API Documentation:** `GET /api/v1/logistics/docs`
- **OpenAPI Spec:** `GET /api/v1/logistics/openapi.yaml`
- **Health Check:** `GET /api/`
- **Endpoints Status:** See LOGISTICS_ENDPOINTS_IMPLEMENTATION_STATUS.md

---

## API Base Paths (Choose One)

### Primary (Recommended)
```
https://api.supplychainapp.com/api/v1/logistics/
```

### Legacy (For backward compatibility)
```
https://api.supplychainapp.com/api/logistics/
```

Both are fully functional and synchronized.

---

## Authentication

### Login
```bash
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}

Response:
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": { "id": 1, "name": "User Name", "role": "procurement_manager" },
  "expires_in": 300
}
```

### Use Token in Requests
```bash
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### Refresh Token (before expiry)
```bash
POST /api/refresh
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

---

## ⚠️ CRITICAL: Response Structure & Data Extraction

### Backend Response Format
All endpoints return data wrapped in this structure:
```json
{
  "success": true,
  "data": {
    "trips": { ...pagination object... }
    // or "trip": { ...single object... }
  }
}
```

### Pagination Object (for list endpoints)
```json
{
  "current_page": 1,
  "data": [ /* ACTUAL ARRAY HERE */ ],
  "per_page": 20,
  "total": 100
}
```

### ❌ COMMON MISTAKE - This Will Fail
```javascript
const trips = response.data.data.trips;
trips.map(...) // ❌ ERROR: trips is an object, not an array
```

### ✅ CORRECT - Extract the Array
```javascript
// For LIST endpoints (GET /trips, /vehicles, /materials)
const trips = response.data.data.trips.data; // ✅ Array

// For SINGLE entity endpoints (GET /trips/{id})
const trip = response.data.data.trip; // ✅ Object

// For CREATE endpoints (POST /trips)
const newTrip = response.data.data.trip; // ✅ Created object
```

### Recommended Helper Function
```javascript
// Extract paginated list
const extractList = (response, key) => {
  const paginationObj = response.data.data[key];
  return {
    data: paginationObj.data || [],
    pagination: {
      currentPage: paginationObj.current_page,
      total: paginationObj.total,
      perPage: paginationObj.per_page
    }
  };
};

// Usage
const result = extractList(response, 'trips');
const trips = result.data; // Now you have the array
```

💡 **See BACKEND_RESPONSE_STRUCTURE.md for complete examples and helper utilities**

---

## Core Endpoints by Feature

### 1️⃣ TRIP MANAGEMENT

#### Create Trip
```bash
POST /api/v1/logistics/trips
{
  "title": "Delivery to Warehouse A",
  "purpose": "Board meeting at Warehouse A",
  "origin": "Supplier Factory, City A",
  "destination": "Warehouse A, City B",
  "scheduled_departure_at": "2026-02-10 08:00:00",
  "scheduled_arrival_at": "2026-02-10 16:00:00"
}
```

Notes: `title` is optional when `purpose` is provided. If both are omitted, the API derives a title from `origin` and `destination`.

#### List Trips
```bash
GET /api/v1/logistics/trips?status=SCHEDULED&vendor_id=5&page=1
```

#### Get Trip Details
```bash
GET /api/v1/logistics/trips/123
# Returns: trip with vendor, journeys, materials
```

#### Update Trip
```bash
PUT /api/v1/logistics/trips/123
{
  "status": "IN_TRANSIT",
  "notes": "Additional delivery instructions"
}
```

#### Assign Vendor (Triggers Notification ✉️)
```bash
POST /api/v1/logistics/trips/123/assign-vendor
{
  "vendor_id": 5
}
# Vendor receives email notification immediately
```

#### Bulk Upload Trips
```bash
POST /api/v1/logistics/trips/bulk-upload
Form-Data:
- file: trips.xlsx
- rows: [
    { "title": "Trip 1", "origin": "City A", "destination": "City B" },
    { "title": "Trip 2", "origin": "City C", "destination": "City D" }
  ]
```

---

### 2️⃣ JOURNEY MANAGEMENT

#### Create Journey
```bash
POST /api/v1/logistics/journeys
{
  "trip_id": 123,
  "driver_id": 7,
  "vehicle_id": 5,
  "estimated_arrival_at": "2026-02-10 16:00:00"
}
```

#### Get Journey by Trip
```bash
GET /api/v1/logistics/journeys/123  # trip_id
```

#### Update Journey Status (Triggers Notification ✉️)
```bash
POST /api/v1/logistics/journeys/456/update-status
{
  "status": "DEPARTED",
  "timestamp": "2026-02-10 08:15:00",
  "location": "City A, Highway 1"
}

# Status Flow:
# DRAFT → SCHEDULED → DEPARTED → EN_ROUTE → ARRIVED → COMPLETED
```

#### Update Other Journey Info
```bash
PUT /api/v1/logistics/journeys/456
{
  "current_location": "City Center",
  "estimated_arrival_at": "2026-02-10 15:45:00"
}
```

---

### 3️⃣ FLEET MANAGEMENT

#### List Vehicles
```bash
GET /api/v1/logistics/fleet/vehicles?status=ACTIVE&page=1
```

#### Create Vehicle
```bash
POST /api/v1/logistics/fleet/vehicles
{
  "model": "Hilux",
  "year": 2024,
  "cargo_capacity": 1200,
  "fuel_type": "Diesel",
  "plate_number": "ABC-1234",
  "vendor_id": 5,
  "status": "ACTIVE"
}
```

Notes: 
- `vehicle_code` is optional and will be auto-generated (e.g., VEH-20260216-ABC123) if not provided.
- `plate_number` is optional and will be auto-generated as a temporary placeholder if not provided.
- Frontend fields `model`, `year`, `cargo_capacity`, `fuel_type` are automatically mapped to backend fields.

#### Get Vehicle with Maintenance
```bash
GET /api/v1/logistics/fleet/vehicles/5
# Returns: vehicle with all maintenance records
```

#### Add Maintenance Record
```bash
POST /api/v1/logistics/fleet/vehicles/5/maintenance
{
  "maintenance_type": "OIL_CHANGE",
  "description": "Oil change and filter replacement",
  "next_due_at": "2026-03-10T00:00:00Z",
  "cost": 250.00
}
```

#### 🆕 Get Fleet Alerts (NEW FEATURE)
```bash
GET /api/v1/logistics/fleet/alerts?days_threshold=30

Response:
{
  "alerts": {
    "expiring_documents": [
      {
        "vehicle_id": 5,
        "vehicle_code": "VH-001",
        "plate_number": "ABC-1234",
        "document_type": "insurance",
        "expires_at": "2026-02-15",
        "days_until_expiry": 11,
        "severity": "warning"  // critical, warning, info
      }
    ],
    "overdue_maintenance": [
      {
        "vehicle_id": 5,
        "maintenance_type": "TIRE_ROTATION",
        "next_due_at": "2025-12-01",
        "days_overdue": 65,
        "severity": "critical"
      }
    ],
    "status_changes": [...],
    "summary": {
      "total_alerts": 5,
      "critical": 2,
      "warning": 2,
      "info": 1
    }
  }
}
```

#### Upload Vehicle Document
```bash
POST /api/v1/logistics/documents
Form-Data:
- documentable_type: "vehicle"
- documentable_id: 5
- document_type: "insurance"
- file: insurance_policy.pdf
- expires_at: "2026-12-31"
```

---

### 4️⃣ MATERIALS TRACKING

#### Create Material
```bash
POST /api/v1/logistics/materials
{
  "name": "Industrial Parts Set",
  "quantity": 50,
  "unit": "boxes",
  "trip_id": 123,
  "status": "AVAILABLE"
}
```

Notes: `material_code` is optional and will be auto-generated (e.g., MAT-20260216-XYZ789) if not provided.

#### List Materials
```bash
GET /api/v1/logistics/materials?status=IN_TRANSIT&page=1
```

#### Get Materials for Specific Trip
```bash
GET /api/v1/logistics/trips/123/materials
```

#### Bulk Upload Materials
```bash
POST /api/v1/logistics/materials/bulk-upload
{
  "rows": [
    { "material_code": "MAT-001", "name": "Parts", "quantity": 50, "trip_id": 123 },
    { "material_code": "MAT-002", "name": "Supplies", "quantity": 100, "trip_id": 123 }
  ]
}
```

---

### 5️⃣ REPORTS & COMPLIANCE

#### Submit Report
```bash
POST /api/v1/logistics/reports
{
  "report_type": "DELIVERY_CONFIRMATION",
  "trip_id": 123,
  "title": "Delivery Confirmed",
  "description": "All items delivered successfully and in good condition",
  "due_at": "2026-02-11T00:00:00Z"
}

# Report Types:
# - INCIDENT: Accident, damage, delay
# - DELIVERY_CONFIRMATION: Proof of delivery
# - VEHICLE_INSPECTION: Pre/post-trip inspection
# - COMPLIANCE_CHECK: Regulatory compliance
```

#### List Reports
```bash
GET /api/v1/logistics/reports?status=SUBMITTED&page=1
```

#### Get Pending/Overdue Reports
```bash
GET /api/v1/logistics/reports/pending
# Returns: all reports with status DRAFT or past due_at date
```

---

### 6️⃣ TEMPLATES & BULK UPLOADS

#### Get Excel Templates
```bash
GET /api/v1/logistics/uploads/templates

Response:
{
  "templates": {
    "trips": ["title", "origin", "destination", "scheduled_departure_at", "scheduled_arrival_at"],
    "materials": ["material_code", "name", "quantity", "trip_id", "unit"]
  }
}
```

#### Download Sample Excel File
Create Excel with headers from template response and upload via:
- POST `/api/v1/logistics/trips/bulk-upload`
- POST `/api/v1/logistics/materials/bulk-upload`

---

## Status/Enum Values

### Trip Status
```
DRAFT                    - Initial state
SCHEDULED                - Confirmed and scheduled
VENDOR_ASSIGNED          - Vendor assigned
IN_TRANSIT               - Trip active
COMPLETED                - Trip finished
CANCELLED                - Trip cancelled
```

### Journey Status
```
DRAFT                    - Initial state
SCHEDULED                - Confirmed
DEPARTED                 - Left origin
EN_ROUTE                 - In transit
ARRIVED                  - Reached destination
COMPLETED                - Journey finished
CANCELLED                - Journey cancelled
```

### Vehicle Status
```
ACTIVE                   - Available for trips
MAINTENANCE              - Under maintenance
OUT_OF_SERVICE           - Temporarily unavailable
RETIRED                  - No longer in use
```

### Material Status
```
AVAILABLE                - Ready for shipment
IN_TRANSIT               - Currently being transported
DELIVERED                - Successfully delivered
DAMAGED                  - Arrived damaged
LOST                     - Not received
```

### Report Status
```
DRAFT                    - In progress
SUBMITTED                - Awaiting review
APPROVED                 - Verified and accepted
REJECTED                 - Requires revision
```

---

## Error Handling

### Standard Error Response
```json
{
  "success": false,
  "message": "Trip not found",
  "error_code": "NOT_FOUND",
  "status_code": 404,
  "data": {}
}
```

### Common Status Codes
```
200  - Success
201  - Created
204  - No Content (deleted)
207  - Partial Success (some bulk items failed)
400  - Bad Request (validation error)
401  - Unauthorized (missing/invalid token)
403  - Forbidden (insufficient permissions)
404  - Not Found
422  - Unprocessable (business logic error)
500  - Server Error
```

### Validation Error Example
```json
{
  "success": false,
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "status_code": 422,
  "errors": {
    "origin": ["The origin field is required"],
    "destination": ["The destination must be a string"]
  }
}
```

Note: If `title` is sent as an empty string, validation fails. Otherwise the API derives a title when missing.

---

## Notifications (Auto-triggered)

### Events That Send Emails ✉️

1. **Vendor Assigned to Trip**
   - Triggered: `POST /api/v1/logistics/trips/{id}/assign-vendor`
   - Recipient: Vendor email
   - Content: Trip code, route, scheduled times

2. **Journey Status Changed**
   - Triggered: `POST /api/v1/logistics/journeys/{id}/update-status`
   - Recipient: Assigned vendor
   - Content: Status change, location, ETA

3. **Fleet Document Expiring** (Scheduled)
   - Content: Document type, expiry date, days remaining
   - Recipient: Fleet manager

4. **Vehicle Maintenance Overdue** (Scheduled)
   - Content: Maintenance type, overdue days
   - Recipient: Fleet manager

### Check Notifications in System
```bash
GET /api/v1/logistics/notifications
GET /api/notifications  # General system notifications
```

---

## Response Examples

### Success Response (List with Pagination)
**Actual backend response:**
```json
{
  "success": true,
  "data": {
    "trips": {
      "current_page": 1,
      "data": [
        {
          "id": 123,
          "trip_code": "TRIP-20260204-ABC123",
          "title": "Delivery to Warehouse A",
          "origin": "Supplier Factory",
          "destination": "Warehouse A",
          "status": "SCHEDULED",
          "vendor_id": 5,
          "scheduled_departure_at": "2026-02-10 08:00:00",
          "scheduled_arrival_at": "2026-02-10 16:00:00",
          "created_at": "2026-02-04 10:30:00",
          "updated_at": "2026-02-04 10:30:00"
        }
      ],
      "first_page_url": "https://api.../trips?page=1",
      "from": 1,
      "last_page": 3,
      "last_page_url": "https://api.../trips?page=3",
      "next_page_url": "https://api.../trips?page=2",
      "path": "https://api.../trips",
      "per_page": 20,
      "prev_page_url": null,
      "to": 20,
      "total": 45
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.get('/api/v1/logistics/trips');

// Extract the array of trips
const trips = response.data.data.trips.data;

// Extract pagination info
const pagination = {
  currentPage: response.data.data.trips.current_page,
  lastPage: response.data.data.trips.last_page,
  perPage: response.data.data.trips.per_page,
  total: response.data.data.trips.total
};

// Now you can use trips array
trips.forEach(trip => console.log(trip.title));
```

### Success Response (Single Entity)
**Actual backend response:**
```json
{
  "success": true,
  "data": {
    "trip": {
      "id": 123,
      "trip_code": "TRIP-20260204-ABC123",
      "title": "Delivery to Warehouse A",
      "origin": "Supplier Factory",
      "destination": "Warehouse A",
      "status": "SCHEDULED",
      "vendor": {
        "id": 5,
        "name": "FastShip Logistics",
        "email": "contact@fastship.com"
      },
      "journeys": [
        {
          "id": 456,
          "status": "SCHEDULED",
          "driver_id": 7,
          "vehicle_id": 5
        }
      ],
      "materials": [
        {
          "id": 789,
          "material_code": "MAT-001",
          "name": "Industrial Parts",
          "quantity": 50
        }
      ]
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.get('/api/v1/logistics/trips/123');

// Extract the single trip object
const trip = response.data.data.trip;

// Access properties directly
console.log(trip.title); // "Delivery to Warehouse A"
console.log(trip.vendor.name); // "FastShip Logistics"
console.log(trip.journeys.length); // 1
```

---

## Pagination

All list endpoints support pagination:

```bash
GET /api/v1/logistics/trips?page=1&per_page=20

# Default: page=1, per_page=20
# Max recommended: per_page=100
```

Response includes:
```json
{
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8,
    "from": 1,
    "to": 20
  }
}
```

---

## Filtering Examples

### Filter Trips by Status
```bash
GET /api/v1/logistics/trips?status=IN_TRANSIT
```

### Filter Vehicles by Status
```bash
GET /api/v1/logistics/fleet/vehicles?status=ACTIVE
```

### Filter Materials by Trip
```bash
GET /api/v1/logistics/trips/123/materials
```

### Filter Reports by Status
```bash
GET /api/v1/logistics/reports?status=PENDING
```

---

## Testing Checklist

Use this to verify integration:

- [ ] Create trip (POST)
- [ ] List trips (GET)
- [ ] View trip details (GET {id})
- [ ] Update trip (PUT)
- [ ] Assign vendor (POST assign-vendor) - check for email
- [ ] Create vehicle (POST)
- [ ] Add maintenance (POST maintenance)
- [ ] Get fleet alerts (GET alerts)
- [ ] Create material (POST)
- [ ] Create journey (POST)
- [ ] Update journey status (POST update-status) - check for email
- [ ] Submit report (POST)
- [ ] List pending reports (GET pending)
- [ ] Test error cases (invalid requests)

---

## Rate Limiting

No rate limiting currently implemented. Safe for development and testing.
Production deployment may add rate limits:
- Suggested: 100 requests/minute per user
- Bulk uploads: 10 uploads/minute

---

## Common Issues & Solutions

**Issue:** "Unauthorized" (401)
- Solution: Check token is included in Authorization header
- Verify: Token hasn't expired, use refresh if needed

**Issue:** "Forbidden" (403)
- Solution: User doesn't have required role
- Check: User role is procurement_manager, logistics_manager, etc.

**Issue:** "Validation failed" (422)
- Solution: Check required fields in request body
- Check: Date format is ISO 8601 (YYYY-MM-DD HH:MM:SS)

**Issue:** Email notifications not received
- Solution: Check vendor/user has valid email address
- Check: Mail service is configured in backend
- Verify: Queue worker is running

**Issue:** Fleet alerts showing no data
- Solution: Vehicles must have documents with expires_at field
- Check: Database has logistics_documents with records

---

## 📋 Quick Reference: Data Extraction Patterns

### List Endpoints (Paginated)
```javascript
// Pattern: response.data.data[KEY].data
const response = await axios.get(endpoint);
const items = response.data.data[KEY].data; // ✅ Array

// Trips
GET /api/v1/logistics/trips
→ const trips = response.data.data.trips.data;

// Vehicles
GET /api/v1/logistics/fleet/vehicles
→ const vehicles = response.data.data.vehicles.data;

// Materials
GET /api/v1/logistics/materials
→ const materials = response.data.data.materials.data;

// Reports
GET /api/v1/logistics/reports
→ const reports = response.data.data.reports.data;

// Journeys (all)
GET /api/v1/logistics/journeys
→ const journeys = response.data.data.journeys.data;
```

### Single Entity Endpoints
```javascript
// Pattern: response.data.data[KEY]
const response = await axios.get(endpoint);
const item = response.data.data[KEY]; // ✅ Object

// Single Trip
GET /api/v1/logistics/trips/{id}
→ const trip = response.data.data.trip;

// Single Vehicle
GET /api/v1/logistics/fleet/vehicles/{id}
→ const vehicle = response.data.data.vehicle;

// Single Material
GET /api/v1/logistics/materials/{id}
→ const material = response.data.data.material;

// Journey by Trip ID
GET /api/v1/logistics/journeys/{trip_id}
→ const journey = response.data.data.journey;
```

### Create Endpoints (POST)
```javascript
// Pattern: response.data.data[KEY]
const response = await axios.post(endpoint, data);
const created = response.data.data[KEY]; // ✅ Created object

// Create Trip
POST /api/v1/logistics/trips
→ const newTrip = response.data.data.trip;

// Create Vehicle
POST /api/v1/logistics/fleet/vehicles
→ const newVehicle = response.data.data.vehicle;

// Create Material
POST /api/v1/logistics/materials
→ const newMaterial = response.data.data.material;
```

### Special Endpoints
```javascript
// Fleet Alerts (custom structure)
GET /api/v1/logistics/fleet/alerts
→ const alerts = response.data.data.alerts;
→ const expiringDocs = alerts.expiring_documents; // Array
→ const overdueMaintenance = alerts.overdue_maintenance; // Array

// Trip Materials (paginated)
GET /api/v1/logistics/trips/{id}/materials
→ const materials = response.data.data.materials.data;
```

### Pagination Data Extraction
```javascript
const response = await axios.get('/api/v1/logistics/trips');
const paginationObj = response.data.data.trips;

// Extract all pagination info
const pagination = {
  data: paginationObj.data,              // Array of items
  currentPage: paginationObj.current_page,
  lastPage: paginationObj.last_page,
  perPage: paginationObj.per_page,
  total: paginationObj.total,
  from: paginationObj.from,
  to: paginationObj.to,
  nextPageUrl: paginationObj.next_page_url,
  prevPageUrl: paginationObj.prev_page_url
};
```

### Error Handling
```javascript
try {
  const response = await axios.get('/api/v1/logistics/trips');
  const trips = response.data.data.trips.data;
} catch (error) {
  if (error.response) {
    // Backend returned error response
    const errorData = error.response.data;
    console.log(errorData.error);      // Error message
    console.log(errorData.code);       // Error code
    console.log(errorData.errors);     // Validation errors (if any)
  }
}
```

---

## Support

For questions or issues:
1. Check this guide
2. Review API documentation at `/api/v1/logistics/docs`
3. Check error code and message returned by API
4. Review LOGISTICS_ENDPOINTS_IMPLEMENTATION_STATUS.md for detailed specs

---

**Status:** ✅ All endpoints operational and ready  
**Last Updated:** February 4, 2026  
**Backend Version:** 1.0.1
