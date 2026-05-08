# Module 3 – Logistics: Trip Scheduling
## Backend Development Prompt — Cursor

---

### Context
This document covers all backend logic, data models, API endpoints, and business rules required for the Trip Scheduling submodule of the Logistics Module in the SCM system.

---

## 3.1 — Vendor Portal: Driver & Vehicle Details on Trip Assignment

### Business Rule
A trip assigned to a vendor must remain in **`Draft`** status until the vendor submits all required trip details through the Vendor Portal. Only after submission and approval should the status advance.

### Data Model Changes
Extend the `Trip` model (or create a linked `TripVendorSubmission` record) to include the following fields:

```
TripVendorSubmission {
  trip_id            FK → Trip
  vendor_id          FK → Vendor
  vehicle_make       string
  vehicle_model      string
  plate_number       string
  driver_name        string
  driver_phone       string
  driver_license_no  string
  security_info      text (optional)
  documents          [FileAttachment] (insurance cert, road-worthiness cert, etc.)
  submitted_at       datetime
  status             enum: PENDING | SUBMITTED | APPROVED | REJECTED
}
```

### API Endpoints Required
- `GET /api/vendor-portal/trips` — Returns trips assigned to the authenticated vendor.
- `POST /api/vendor-portal/trips/:tripId/submission` — Vendor submits driver, vehicle, and security details.
- `POST /api/vendor-portal/trips/:tripId/documents` — Multipart upload for supporting documents (insurance, road-worthiness cert, etc.).
- `GET /api/trips/:tripId/submission` — Internal endpoint for approvers to view submitted details.

### Status Logic
- On trip assignment to a vendor → set trip status to `Draft`.
- On vendor submission → set status to `Pending Approval`.
- On approver approval → advance status per the standard trip workflow.
- Block trip status advancement if `TripVendorSubmission` record does not exist or is in `PENDING` state.

---

## 3.2 — Multi-Vendor Trip Request & Cost Comparison

### Business Rule
A single trip request can be sent to multiple vendors simultaneously. Each vendor submits their own pricing and vehicle/driver details. An approver reviews all responses, selects a preferred vendor, and approves. Upon approval, the trip is routed to Procurement for PO generation. The winning vendor is then notified to submit their invoice.

### Data Model Changes
- Extend `Trip` to support a `multi_vendor` flag and a one-to-many relationship with `TripVendorSubmission` (one per vendor invited).
- Add a `selected_vendor_id` field on `Trip`, populated at approver selection.
- Add `quoted_price` and `currency` fields to `TripVendorSubmission`.

```
Trip {
  ...existing fields...
  multi_vendor         boolean (default: false)
  selected_vendor_id   FK → Vendor (nullable until approved)
  approval_status      enum: DRAFT | PENDING_REVIEW | APPROVED | REJECTED
}

TripVendorSubmission {
  ...fields from 3.1 above...
  quoted_price   decimal
  currency       string (default: NGN)
}
```

### API Endpoints Required
- `POST /api/trips/:tripId/invite-vendors` — Accepts an array of `vendor_ids`; creates a `TripVendorSubmission` record (status: `PENDING`) for each and dispatches invite notifications.
- `GET /api/trips/:tripId/vendor-responses` — Returns all vendor submissions for a trip, formatted for comparison (vendor name, quoted price, vehicle details, documents).
- `POST /api/trips/:tripId/select-vendor` — Approver selects a vendor; sets `selected_vendor_id` and advances trip to `APPROVED`.
- `POST /api/trips/:tripId/route-to-procurement` — Triggered post-approval; creates a linked PO requisition in the Procurement module.
- `POST /api/trips/:tripId/notify-invoice` — Sends an invoice submission prompt/notification to the approved vendor.

### Notifications
- On invite: notify each vendor via email/portal alert.
- On approval: notify the selected vendor; notify rejected vendors (optional).
- After PO is raised: notify the approved vendor to submit their invoice.

---

## 3.3 — Accommodation Module (Under Logistics)

### Business Rule
Accommodation bookings must be tracked within the Logistics module and linked to a corresponding trip schedule. Hotel names are free-text (no pre-loaded list).

### Data Model — New Table: `AccommodationBooking`

```
AccommodationBooking {
  id                  UUID (PK)
  trip_id             FK → Trip (nullable — can pre-exist a trip assignment)
  passenger_names     string[] (array of staff/passenger names)
  destination_state   string
  destination_city    string
  number_of_nights    integer
  hotel_name          string (free text)
  check_in_date       date
  check_out_date      date (derived: check_in + number_of_nights)
  created_by          FK → User
  created_at          datetime
  updated_at          datetime
}
```

### API Endpoints Required
- `POST /api/logistics/accommodations` — Create a new accommodation booking.
- `GET /api/logistics/accommodations` — List all bookings (filterable by trip, destination, date range).
- `GET /api/logistics/accommodations/:id` — Get a single booking.
- `PATCH /api/logistics/accommodations/:id` — Update booking details.
- `DELETE /api/logistics/accommodations/:id` — Soft-delete a booking.
- `GET /api/trips/:tripId/accommodations` — Fetch all accommodation bookings linked to a specific trip.

---

## 3.4 — Job Completion Certificate (JCC) for Trips

### Business Rule
Each completed trip should be closeable with a Job Completion Certificate. The JCC template will be provided separately by Joseph Akinyanmi — backend should be built to support a dynamic field structure pending that template.

### Data Model — New Table: `JobCompletionCertificate`

```
JobCompletionCertificate {
  id              UUID (PK)
  trip_id         FK → Trip (unique — one JCC per trip)
  issued_by       FK → User
  issued_at       datetime
  remarks         text
  delivery_confirmed boolean
  condition_of_goods text (if materials are involved)
  attachments     [FileAttachment]
  status          enum: DRAFT | SUBMITTED | APPROVED
}
```

### API Endpoints Required
- `POST /api/trips/:tripId/jcc` — Create/submit a JCC for a trip.
- `GET /api/trips/:tripId/jcc` — Retrieve the JCC for a trip.
- `PATCH /api/trips/:tripId/jcc` — Update JCC (while in DRAFT).
- `POST /api/trips/:tripId/jcc/approve` — Approve and finalise the JCC; triggers trip closure.

### Status Logic
- A trip can only be marked **Closed/Completed** after its JCC is in `APPROVED` status.
- JCC approval should trigger any final notifications to relevant stakeholders.
