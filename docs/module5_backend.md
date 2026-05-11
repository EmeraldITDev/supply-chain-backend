# Module 5 – Logistics: Materials Tracking
## Backend Development Prompt — Cursor

---

### Context
Materials Tracking is for tracking goods in transit — not inventory. This module manages the full journey of materials from pickup to delivery, including a Job Completion Certificate on delivery confirmation.

---

## 5.1 — Enhanced Material Movement Form

### Data Model — Extend or Replace `MaterialMovement`

```
MaterialMovement {
  id                      UUID (PK)
  material_name           string               -- e.g. "HP Branded Laptops"
  quantity                integer
  category                string               -- e.g. "Electronics"
  pickup_location         string               -- origin of goods
  destination             string               -- NEW: not currently in system
  vendor_id               FK → Vendor (nullable) -- transporter; can be Emerald-owned
  vendor_name             string               -- denormalised for display when no vendor_id
  vendor_phone            string               -- direct contact for transporter
  vehicle_plate_number    string
  driver_name             string
  driver_phone            string
  expected_pickup_datetime  datetime
  expected_delivery_datetime datetime
  condition_of_goods      enum: NEW | USED | DAMAGED
  status                  enum: PENDING | IN_TRANSIT | DELIVERED | CANCELLED
  created_by              FK → User
  created_at              datetime
  updated_at              datetime
}
```

### Migration Notes
- `destination` is a new field — add to existing table if one exists.
- `vendor_phone`, `driver_phone`, `vehicle_plate_number`, `driver_name` may already exist in a lighter form — extend rather than duplicate.
- `condition_of_goods` is new.
- `vendor_id` should be nullable to support Emerald-owned vehicles with no external vendor record.

### API Endpoints Required
- `POST /api/materials` — Create a new material movement record.
- `GET /api/materials` — List all material movements (filterable by status, category, date range, destination).
- `GET /api/materials/:id` — Get a single material movement with full details.
- `PATCH /api/materials/:id` — Update a material movement (while status is `PENDING` or `IN_TRANSIT`).
- `DELETE /api/materials/:id` — Soft-delete (set status to `CANCELLED`).
- `POST /api/materials/:id/mark-in-transit` — Advance status to `IN_TRANSIT`; records actual pickup datetime.
- `POST /api/materials/:id/mark-delivered` — Advance status to `DELIVERED`; records actual delivery datetime.

### Validation Rules
- `material_name`, `quantity`, `category`, `pickup_location`, `destination`, `vehicle_plate_number`, `driver_name`, `driver_phone`, `expected_pickup_datetime`, `expected_delivery_datetime`, `condition_of_goods` — all required.
- `vendor_id` or `vendor_name` — at least one must be present (vendor can be Emerald-owned with no system record, in which case `vendor_name` is used as free text).
- `expected_delivery_datetime` must be after `expected_pickup_datetime`.

---

## 5.2 — Job Completion Certificate for Material Movements

### Business Rule
Each material movement should be closeable with a JCC confirming delivery, condition of goods on arrival, and any remarks. This mirrors the trip JCC (Module 3.4) but is scoped to materials rather than vehicle trips.

### Data Model — New Table: `MaterialJCC`

```
MaterialJCC {
  id                    UUID (PK)
  material_movement_id  FK → MaterialMovement (unique — one JCC per movement)
  reference_number      string (auto-generated: JCC/MAT/[YYYYMM]-[seq])
  vendor_id             FK → Vendor (nullable — pulled from material movement)
  vendor_name           string (denormalised)
  po_number             string (nullable — linked PO if one was raised)
  certification_text    text (editable; system provides default)
  condition_on_arrival  enum: GOOD | DAMAGED | PARTIAL
  issued_by             FK → User
  issued_at             datetime
  status                enum: DRAFT | SUBMITTED | APPROVED
}
```

### Data Model — New Table: `MaterialJCCLineItem`

```
MaterialJCCLineItem {
  id              UUID (PK)
  jcc_id          FK → MaterialJCC
  serial_number   integer (auto-incremented per JCC)
  material_name   string
  quantity        integer
  condition       string
  remarks         text
}
```

### Reference Number Generation
```
format: JCC/MAT/[YYYYMM]-[paddedSequence]
example: JCC/MAT/202509-01
```
Sequence scoped per month.

### API Endpoints Required
- `POST /api/materials/:materialId/jcc` — Create a MaterialJCC. Auto-generates reference number; pulls vendor, movement details. Accepts `certification_text` override and array of `line_items`.
- `GET /api/materials/:materialId/jcc` — Retrieve the JCC with all line items.
- `PATCH /api/materials/:materialId/jcc` — Update JCC header and line items while in `DRAFT`.
- `POST /api/materials/:materialId/jcc/submit` — Advance to `SUBMITTED`; locks record.
- `POST /api/materials/:materialId/jcc/approve` — Advance to `APPROVED`; triggers material movement closure (status → `DELIVERED` if not already).
- `GET /api/materials/:materialId/jcc/pdf` — Render and return a formatted PDF of the MaterialJCC.
- `GET /api/materials/:materialId/jcc/prefill` — Returns suggested line items derived from the material movement record, formatted as `MaterialJCCLineItem` objects.

### PDF Layout Requirements
The PDF must render:
1. Company letterhead (name, address, phone, email — from system settings).
2. JCC reference number and date issued.
3. Vendor name and address block.
4. Linked PO number (if present).
5. Certification paragraph.
6. Line-item table: SN, Material Name, Quantity, Condition, Remarks.
7. Signatory block (name, title, digital signature if saved).

### Status Logic
- A material movement can only be marked `DELIVERED` (fully closed) after its JCC reaches `APPROVED`.
- JCC approval triggers final stakeholder notifications.
- Lazy creation: the JCC record is only created when the user explicitly saves — not on drawer open. Reference number is returned in the `POST /jcc` response.
