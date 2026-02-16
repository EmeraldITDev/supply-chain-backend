# Logistics Module (v1)

## Project Folder Structure (new)
- app/Http/Controllers/Api/V1/Logistics/
- app/Http/Requests/Logistics/
- app/Http/Middleware/EnsureRole.php
- app/Http/Middleware/EnsurePermission.php
- app/Models/Logistics/
- app/Services/Logistics/
- app/Notifications/LogisticsEventNotification.php
- database/migrations/2026_02_03_*_create_logistics_*.php
- database/migrations/2026_02_03_000000_create_audit_logs_table.php
- docs/openapi/logistics-v1.yaml

## Entity Models & Relationships
- Trip has many journeys, materials, reports.
- Journey belongs to trip.
- Material belongs to trip; has many condition histories.
- Vehicle belongs to vendor; has many maintenances; has many documents (polymorphic).
- Document belongs to a documentable entity (vendor, trip, journey, vehicle).
- Vendor compliance belongs to vendor.
- Vendor invite tracks email-only onboarding token.
- Notification event is an idempotent event log for notifications.
- Audit log captures critical operations.

## API Route Structure (versioned)
Base path: /api/v1/logistics

### Auth
- POST /auth/login
- POST /auth/vendor-invite
- POST /auth/vendor-accept
- GET /auth/me

### Vendors
- POST /vendors
- GET /vendors
- GET /vendors/{id}
- PUT /vendors/{id}
- POST /vendors/{id}/invite

### Trips
- POST /trips
- GET /trips
- GET /trips/{id}
- PUT /trips/{id}
- POST /trips/{id}/assign-vendor
- POST /trips/bulk-upload

### Journeys
- POST /journeys
- GET /journeys/{trip_id}
- PUT /journeys/{id}
- POST /journeys/{id}/update-status

### Materials
- POST /materials
- GET /materials
- GET /materials/{id}
- POST /materials/bulk-upload
- GET /trips/{id}/materials

### Fleet
- POST /fleet/vehicles
- GET /fleet/vehicles
- GET /fleet/vehicles/{id}
- PUT /fleet/vehicles/{id}
- POST /fleet/vehicles/{id}/maintenance

### Documents
- POST /documents
- GET /documents/{entity_type}/{entity_id}
- DELETE /documents/{id}

### Notifications
- POST /notifications/send
- GET /notifications

### Reports
- POST /reports
- GET /reports
- GET /reports/pending

### Upload Templates
- GET /uploads/templates

### API Docs
- GET /docs
- GET /openapi.yaml

## Example Requests/Responses
### Create Trip
Request:
POST /api/v1/logistics/trips
{
  "title": "Trip to Port",
  "purpose": "Equipment delivery",
  "origin": "Warehouse A",
  "destination": "Port B",
  "scheduled_departure_at": "2026-02-05T10:00:00Z"
}

Notes: `title` is optional when `purpose` is provided. If both are omitted, the API derives a title from `origin` and `destination`.

Response:
{
  "success": true,
  "data": {
    "trip": {
      "id": 1,
      "trip_code": "TRIP-20260205-AB12CD",
      "status": "draft",
      "origin": "Warehouse A",
      "destination": "Port B"
    }
  }
}

### Update Journey Status
Request:
POST /api/v1/logistics/journeys/12/update-status
{
  "status": "departed",
  "timestamp": "2026-02-05T12:00:00Z"
}

Response:
{
  "success": true,
  "data": {
    "journey": {
      "id": 12,
      "status": "departed"
    }
  }
}

## Audit Logging
All critical operations write to audit_logs using App\Services\Logistics\AuditLogger.

## Idempotency
POST /trips supports Idempotency-Key header. Repeated requests return cached response.

## Notifications (Queue-backed)
Notification events are enqueued via the queue worker using App\Jobs\SendLogisticsNotification.
Events are idempotent by event_key and stored in logistics_notification_events.

## Error Handling
Standard error format:
{
  "success": false,
  "error": "Validation failed",
  "code": "VALIDATION_ERROR",
  "errors": {"field": ["message"]}
}
