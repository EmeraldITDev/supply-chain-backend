# Module 4 – Logistics: Fleet Management
## Backend Development Prompt — Cursor

---

### Context
This document covers all backend logic, data models, API endpoints, scheduled jobs, and business rules required for the Fleet Management submodule of the Logistics Module in the SCM system.

---

## 4.1 — Vehicle Document Upload & Expiry Notifications

### Data Model — New Table: `VehicleDocument`

```
VehicleDocument {
  id              UUID (PK)
  vehicle_id      FK → Vehicle
  document_type   enum: INSURANCE_CERTIFICATE | VEHICLE_LICENCE | TRANSPORT_PERMIT | ROAD_WORTHINESS_CERTIFICATE | LOCAL_GOVERNMENT_PAPERS
  file            FileAttachment
  expiry_date     date
  uploaded_by     FK → User
  uploaded_at     datetime
  is_active       boolean (latest version of this doc type for this vehicle)
}
```

### API Endpoints Required
- `POST /api/fleet/vehicles/:vehicleId/documents` — Upload a document for a vehicle (multipart). Accepts `document_type` and `expiry_date`.
- `GET /api/fleet/vehicles/:vehicleId/documents` — List all documents for a vehicle, grouped by type, showing the latest active document per type.
- `DELETE /api/fleet/vehicles/:vehicleId/documents/:documentId` — Soft-delete/deactivate a document.

### Scheduled Job: Document Expiry Checker
Run **daily** (e.g. via cron at midnight):

```
FOR EACH active VehicleDocument:
  days_remaining = expiry_date - today

  IF days_remaining <= 0:
    → Set vehicle.status = INACTIVE
    → Create notification: "Document expired: [doc type] for [vehicle plate]. Vehicle set to Inactive."

  ELSE IF days_remaining <= 42 (6 weeks):
    → Determine colour tier:
        29–42 days → AMBER (first alert)
        8–28 days  → AMBER (weekly reminder)
        1–7 days   → RED (weekly reminder)
        Green is the default state outside 6-week window

    → Send notification to logistics officer with tier + days remaining
    → Log notification to prevent duplicate sends within same week
```

### Notification Payload Fields
```
{
  vehicle_id,
  vehicle_plate,
  document_type,
  expiry_date,
  days_remaining,
  alert_colour: "GREEN" | "AMBER" | "RED",
  recipient_user_id
}
```

---

## 4.2 — Scheduled Maintenance Records & Notifications

### Data Model — New Table: `MaintenanceSchedule`

```
MaintenanceSchedule {
  id                    UUID (PK)
  vehicle_id            FK → Vehicle
  maintenance_type      string (e.g. "Oil Change", "Full Service")
  interval_months       integer (e.g. 4)
  last_maintenance_date date
  next_maintenance_date date (derived: last_maintenance_date + interval_months)
  status                enum: SCHEDULED | COMPLETED | OVERDUE
  notes                 text (optional)
  created_by            FK → User
  updated_at            datetime
}
```

### API Endpoints Required
- `POST /api/fleet/vehicles/:vehicleId/maintenance` — Create a maintenance schedule entry.
- `GET /api/fleet/vehicles/:vehicleId/maintenance` — List all maintenance records for a vehicle.
- `PATCH /api/fleet/vehicles/:vehicleId/maintenance/:scheduleId` — Update a maintenance record (e.g. mark as completed, update last maintenance date → auto-recalculates next date).
- `GET /api/fleet/maintenance/upcoming` — Global list of all vehicles with maintenance due within the next X days (for dashboard widget).

### Scheduled Job: Maintenance Overdue Checker
Run **daily**:

```
FOR EACH MaintenanceSchedule WHERE status = SCHEDULED:
  IF next_maintenance_date < today:
    → Set status = OVERDUE
    → Set vehicle.status = INACTIVE
    → Notify logistics officer: "Maintenance overdue for [vehicle plate]. Vehicle set to Inactive."

  ELSE IF next_maintenance_date is within 14 days:
    → Notify logistics officer: "Upcoming maintenance for [vehicle plate] due on [date]."
```

### Trip Assignment Guard
When a vehicle is being assigned to a trip, the backend must validate:
1. `vehicle.status !== INACTIVE`
2. No `MaintenanceSchedule` record for this vehicle has `next_maintenance_date` within the next 7 days (configurable threshold).

If either condition is true, return a **warning response** (not a hard block unless status is `INACTIVE`):
```json
{
  "warning": true,
  "message": "This vehicle has maintenance scheduled within 7 days. Proceed with assignment?",
  "allow_override": true
}
```

---

## 4.3 — Automatic Inactive Status on Document Expiry / Maintenance Overdue

This logic is embedded in the scheduled jobs above (4.1 and 4.2), but the following additional rules apply:

- `vehicle.status` should be a managed enum: `ACTIVE | INACTIVE | UNDER_MAINTENANCE`.
- When auto-set to `INACTIVE`, log the reason: `DOCUMENT_EXPIRED` or `MAINTENANCE_OVERDUE`.
- **Manual override:** A logistics officer can set a vehicle back to `ACTIVE` via a dedicated endpoint, bypassing the auto-rule (requires a confirmation/reason field).

### API Endpoint Required
- `PATCH /api/fleet/vehicles/:vehicleId/status` — Manually override vehicle status. Body: `{ status, reason, override_by }`.

---

## 4.4 — Driver Profile: Make Email Optional, Add Phone Number

### Data Model Changes on `Driver`
- Change `email` field constraint from `NOT NULL` to `NULLABLE`.
- Add `phone_number` field: `string, NOT NULL`.

### Migration
```sql
ALTER TABLE drivers
  ALTER COLUMN email DROP NOT NULL,
  ADD COLUMN phone_number VARCHAR(20) NOT NULL DEFAULT '';
```
> Remove the `DEFAULT ''` after backfilling existing records.

### API Changes
- `POST /api/fleet/drivers` — Update validation: `email` is optional, `phone_number` is required.
- `PATCH /api/fleet/drivers/:driverId` — Same validation update.
- `GET /api/fleet/drivers` — Ensure `phone_number` is included in list and detail responses.
