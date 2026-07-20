# Frontend Changes — SCM Platform Feature Enhancements

**Backend repo:** `supply-chain-backend`  
**Auth:** Bearer token (`Authorization: Bearer {token}`) on all routes below except public health checks.  
**Base URL:** `/api`

This document is the source of truth for the React/Lovable frontend. **Before wiring any action, check whether a button/page already exists; if not, add UI for every endpoint listed.**

---

## 0. HRIS / SCM Role Separation (BREAKING — update all role UI)

### Problem
HRIS and Supply Chain previously shared a single `users.role` column. Updating a user's HRIS role (e.g. `corporate_hr`) overwrote their SCM role (e.g. `procurement_manager`), breaking SCM permissions.

### Model change (`users` table)

| Column | Owner | Purpose |
|--------|-------|---------|
| `hris_role` | **HRIS only** | HR system permissions. SCM backend **never writes** this field. |
| `supply_chain_role` | **SCM only** | Supply Chain permissions. HRIS **never writes** this field. |
| `role` | **Deprecated** | Legacy column; backfilled into `supply_chain_role` on migration. Do not use in new frontend code. |

Credentials (`email`, `password`) remain shared on the same user record.

### SCM permission source of truth
All SCM gates (middleware `role:`, `PermissionService`, dashboards, workflow actions) now read **`supply_chain_role` exclusively** via `User::scmRole()`.

The legacy `role` field is only used as a read fallback for rows not yet backfilled.

### Auth responses (MODIFIED)

#### `POST /api/auth/login`
#### `GET /api/auth/me`
#### `GET /api/auth/session-status`

**User object now includes:**

```json
{
  "id": 1,
  "email": "user@example.com",
  "name": "Jane Doe",
  "supply_chain_role": "procurement_manager",
  "hris_role": "corporate_hr",
  "role": "procurement_manager",
  "department": "Procurement",
  "employeeId": 42
}
```

| Field | Frontend usage |
|-------|----------------|
| `supply_chain_role` | **Use this** for all SCM route guards, nav visibility, and action buttons. |
| `hris_role` | Display-only in SCM (if shown at all). Do not use for SCM permissions. |
| `role` | **Deprecated alias** of `supply_chain_role` for backward compatibility. Migrate UI to `supply_chain_role`. |

**Frontend:** Replace every SCM check like `user.role === 'procurement_manager'` with `user.supply_chain_role === 'procurement_manager'`. Do not send or edit `hris_role` from the SCM app.

### User management (SCM admin — MODIFIED)

SCM Settings / User Management must **only** expose and edit `supply_chain_role`. Never send `hris_role` in create/update payloads.

| Endpoint | Change |
|----------|--------|
| `GET /api/users` | Each user includes `supply_chain_role`, `hris_role` (read-only), and deprecated `role` alias. Filter: `?supply_chain_role=` (preferred) or legacy `?role=`. |
| `POST /api/users` | Accept `supply_chain_role` (preferred) or legacy `role` in body. Writes `supply_chain_role` only. |
| `PUT /api/users/{id}` | Same as create. Writes `supply_chain_role` only. |
| `GET /api/users/eligible-passengers` | Returns `supply_chain_role` per user; `role` is an alias in the response. |

**Create/update request (preferred):**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "password": "SecurePass123",
  "supply_chain_role": "procurement_manager",
  "department": "Procurement"
}
```

**Allowed SCM roles** (unchanged): `admin`, `employee`, `executive`, `procurement_manager`, `supply_chain_director`, `finance`, `chairman`, `logistics_manager`, `logistics_officer`, `vendor`, plus legacy aliases `logistics`, `procurement`, `supply_chain`.

### Endpoints gated by SCM role (unchanged role values, new field source)

All existing `role:` middleware routes still use the same role **values**; authorization now checks `supply_chain_role`:

- Logistics module routes (`procurement_manager`, `logistics_manager`, `logistics_officer`, `supply_chain_director`, `admin`, `executive`, `chairman`, `finance`)
- `role:admin` — `/api/admin/department-codes`, `/api/admin/category-codes`
- `role:procurement_manager,supply_chain_director` — vendor updates
- MRF/SRF/RFQ workflow actions via `PermissionService` and `GET /api/mrfs/{id}/available-actions`
- Dashboard role-specific stats (`DashboardController`)
- Finance AP reports (`finance`, `admin`)
- Procurement reports and price comparisons
- Vendor portal (`vendor` role on `supply_chain_role`)

### Password / profile (unchanged)

These endpoints continue to sync credentials across systems and **do not** modify either role field:

- `POST /api/auth/change-password`
- `PUT /api/auth/profile` (name, department, phone only)
- `POST /api/vendors/auth/*` password flows

### Migration / deployment

Run: `php artisan migrate`

Migration `2026_06_15_120000_separate_hris_and_supply_chain_roles` adds columns and copies existing `role` → `supply_chain_role`.

Repair command (optional): `php artisan scm:repair-user-access` — relocates HRIS-only roles (e.g. `corporate_hr`) from `supply_chain_role` → `hris_role`, recovers SCM role from Spatie/legacy/profile, and syncs Spatie.

If a user still shows as blocked after repair, set their SCM role explicitly via User Management (`supply_chain_role`).

---

## 1. MRF Contract Type — Free Text + Routing

### GET `/api/mrfs/contract-types` (NEW)
**Roles:** Any authenticated user creating MRFs.

**Response:**
```json
{
  "success": true,
  "standardTypes": [
    { "value": "emerald", "label": "Emerald" },
    { "value": "oando", "label": "Oando" },
    { "value": "dangote", "label": "Dangote" },
    { "value": "heritage", "label": "Heritage" }
  ],
  "allowFreeText": true,
  "routingNote": "Non-standard contract types are routed directly to the Supply Chain Director."
}
```

**Frontend:** On the MRF form, show a combobox: dropdown of `standardTypes` plus “Other / custom” that reveals a text input. Submit `contractType` as either a standard `value` or custom string.

### POST `/api/mrfs` (MODIFIED)
**Request body (additions):**
```json
{
  "contractType": "custom vendor msA",
  "items": [
    {
      "itemName": "Office chairs",
      "quantity": 10,
      "unit": "pcs",
      "budgetAmount": 500000
    }
  ]
}
```

**Response (additions):** `routedReason` — `custom_contract_type` | `standard_contract_type` | `logistics_exception`.

**Frontend:** If `routedReason === 'custom_contract_type'`, show banner: “Routed to Supply Chain Director (non-standard contract).” Standard Emerald routing unchanged.

---

## 2. Budget vs Actuals — MRF/SRF Line Item P&L

### Line items on create/update (POST & PUT)

**Tables:** `mrf_line_items`, `srf_line_items` (`mrf_id`/`srf_id`, `item_name`, `quantity`, `unit`, `budget_amount`, `quoted_amount`).

**Request:** Send line items as **`items` or `line_items`** (both accepted). For **multipart/form-data**, JSON-stringify the array (do not rely on native array serialization).

**Per-line fields (dual-case):** `item_name` / `itemName`, `budget_amount` / `budgetAmount`, `quantity`, `unit`.

**Example (multipart field `items`):**
```json
[{"itemName":"Office chairs","quantity":10,"unit":"pcs","budgetAmount":500000}]
```

**Endpoints:**
- `POST /api/mrfs` — persist line items on create
- `PUT /api/mrfs/{id}` — replace line items when array provided
- `POST /api/srfs` / `PUT /api/srfs/{id}` — same for SRF

**Quoted amounts:** Populated automatically when procurement selects a vendor quotation (`POST /mrfs/{id}/send-vendor-for-approval` or `POST /rfqs/{id}/select-vendor`), matched by **item name** to quotation line items.

### POST `/api/mrfs` & POST `/api/srfs` (MODIFIED)
Include `items[]` or `line_items[]` with **`budgetAmount`** / **`budget_amount`** per line (Feature 9 baseline).

### GET `/api/mrfs/{id}` & GET `/api/srfs/{id}` (MODIFIED)
**Response additions:**
```json
{
  "items": [
    {
      "id": 1,
      "itemName": "Office chairs",
      "quantity": 10,
      "unit": "pcs",
      "budgetAmount": 500000,
      "quotedTotal": 480000
    }
  ],
  "profitAndLoss": {
    "items": [
      {
        "id": 1,
        "itemName": "Office chairs",
        "budgetAmount": 500000,
        "quotedAmount": 480000,
        "variance": 20000,
        "varianceType": "saving"
      }
    ],
    "summary": {
      "totalBudget": 500000,
      "totalQuoted": 480000,
      "netVariance": 20000,
      "totalSavings": 20000,
      "totalLoss": 0,
      "lineCount": 1
    }
  }
}
```

`varianceType`: `saving` (budget > quoted), `loss` (budget < quoted), `neutral`.

### GET `/api/mrfs/{id}/line-item-pnl` (NEW)
### GET `/api/srfs/{id}/line-item-pnl` (NEW)

**Response (LineItemPnLSection):**
```json
{
  "success": true,
  "mrfId": "MRF-2026-001",
  "items": [
    {
      "id": 1,
      "itemName": "Office chairs",
      "item_name": "Office chairs",
      "budgetAmount": 500000,
      "budget_amount": 500000,
      "quotedAmount": 480000,
      "quoted_amount": 480000,
      "variance": 20000,
      "varianceType": "saving",
      "variance_type": "saving"
    }
  ],
  "summary": {
    "totalBudget": 500000,
    "totalQuoted": 480000,
    "netVariance": 20000,
    "totalSavings": 20000,
    "totalLoss": 0,
    "lineCount": 1
  },
  "data": { "items": [], "summary": {} }
}
```

Use top-level `items` + `summary` (or `data.items` / `data.summary`).

**Frontend:** On MRF/SRF **detail view**, render a per-line table: Budget | Quoted | Variance (green saving / red loss). Refresh after quotations are approved.

---

## 3. Procurement Reporting Dashboard

### GET `/api/reports/procurement` (NEW)
**Query:** `from`, `to` (ISO dates, optional).

**Roles:** `procurement_manager`, `procurement`, `supply_chain_director`, `supply_chain`, `admin`, `finance`, `finance_officer`.

**Response:**
```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-01-01", "to": "2026-05-20" },
    "totals": {
      "totalSavings": 120000,
      "totalLoss": 45000,
      "netVariance": 75000,
      "lineItemsWithBudget": 42,
      "posGenerated": 18,
      "mrfsApproved": 25,
      "srfsApproved": 9,
      "priceComparisonMrfs": 12
    },
    "priceComparisonSummaries": [
      {
        "mrfId": 5,
        "comparisonCount": 3,
        "lowestUnitPrice": 100,
        "highestUnitPrice": 150
      }
    ]
  }
}
```

### GET `/api/reports/procurement/export` (NEW)
Same query params. Returns **CSV download** (`metric`, `value` rows).

**Frontend:** New **Procurement Reports** page with date-range filter, summary cards, price-comparison table, and **Export CSV** button. Create the page if missing.

---

## 4. Dashboard KPI Counters

### GET `/api/dashboard/kpis` (NEW)
**Roles:** Any authenticated user.

**Response:**
```json
{
  "success": true,
  "kpis": {
    "totalPosGenerated": 18,
    "totalMrfsApproved": 25,
    "totalSrfsApproved": 9,
    "priceComparisonCount": 12
  }
}
```

**Frontend:** On the **main dashboard**, add four KPI tiles bound to these fields. Add tiles if they do not exist.

---

## 5. Logistics — Trip Request Workflow

### POST `/api/trip-requests` (NEW)
**Roles:** All staff **except** `vendor`, `admin`, `executive`, `chairman`.

**Request:**
```json
{
  "destination": "Lagos Airport",
  "purpose": "Client meeting",
  "origin": "HQ",
  "scheduled_departure_at": "2026-06-15T08:00:00Z",
  "scheduled_arrival_at": "2026-06-15T18:00:00Z",
  "passenger_user_ids": [2, 5, 8],
  "bookingScope": "outside_state"
}
```

**Response:** `{ "success": true, "data": { "trip": { ... } } }` with `workflow_stage: "trip_request"`.

**Frontend:** **Trip Request** form: destination, purpose, **mandatory trip type** (`within_state` / `outside_state`), date/time with lead-time validation (`GET /api/trip-requests/booking-rules`), passenger multi-select. **No driver field** — logistics assigns on convert.

**Staff dashboard:** `GET /api/trip-requests`, `GET /api/trip-requests/{id}/progress-tracker` — see `docs/frontend_changes.md` § SCM Platform — Trip Request & SRF updates.

### POST `/api/trips/{id}/convert-to-logistics-request` (NEW)
**Roles:** `logistics_manager`, `logistics_officer`, `admin`.

**Request:**
```json
{
  "vendor_id": 3,
  "vehicle_id": 7,
  "passenger_user_ids": [2, 5],
  "driver_user_id": 12
}
```

Sets `workflow_stage` → `procurement_review`. Notify procurement.

### Existing trip vendor flow (unchanged paths)
- `POST /api/trips/{tripId}/invite-vendors`
- `GET /api/trips/{tripId}/vendor-responses`
- `POST /api/trips/{tripId}/select-vendor`

### POST `/api/trips/{id}/procurement-approve-quote` (NEW)
**Roles:** Procurement. Requires `selected_vendor_id` on trip.

### POST `/api/trips/{id}/scd-approve` (NEW)
**Roles:** Supply Chain Director.

### POST `/api/trips/{id}/generate-trip-po` (NEW)
**Roles:** Procurement.

**Request:**
```json
{
  "po_number": "PO-TRIP-2026-001",
  "unsigned_po_url": "https://..."
}
```

### POST `/api/trips/{id}/upload-signed-trip-po` (NEW)
**Roles:** SCD.

**Request:** `{ "signed_po_url": "https://..." }`

**Frontend workflow UI (create any missing steps):**

| Stage | Actor | Action / Endpoint |
|--------|--------|-------------------|
| Trip Request | Staff | `POST /trip-requests` |
| Logistics Review | Logistics Manager | `POST /trips/{id}/convert-to-logistics-request` |
| Procurement | Procurement | Invite vendors → select vendor → `procurement-approve-quote` |
| SCD Approval | SCD | `scd-approve` |
| PO Generate | Procurement | `generate-trip-po` |
| PO Sign | SCD | `upload-signed-trip-po` |

Stage transitions send **in-app notifications** (`LogisticsEventNotification`).

---

## 6. Trip Scheduling — Passenger Selection Fix

### GET `/api/users/eligible-passengers` (NEW)
**Query:** `q` (search), paginated.

**Response:**
```json
{
  "success": true,
  "users": [
    { "id": 2, "name": "Jane Doe", "email": "jane@...", "phone": "...", "department": "Finance", "role": "employee" }
  ],
  "pagination": { "currentPage": 1, "lastPage": 1, "perPage": 50, "total": 10 }
}
```

Excludes **vendors** and **power users** (`admin`, `executive`, `chairman`).

### POST `/api/trips` & PUT `/api/trips/{id}` (MODIFIED)
**Request fields:** `passenger_user_ids[]`, `driver_user_id` (optional, separate from passengers).

**Frontend:** “Select Passengers” modal must call `eligible-passengers`, not all users. Add optional **Driver** dropdown (same list or dedicated driver field).

---

## 7. Vehicle Management — Edit

### PUT `/api/fleet/vehicles/{id}` (EXISTING)
Also under `/api/v1/logistics/fleet/vehicles/{id}` and `/api/vehicles/{id}`.

**Frontend:** Ensure **Edit** on vehicle list/detail opens form posting full vehicle payload. Restrict to `logistics_manager` / `admin`.

---

## 8. Driver Management

### DELETE `/api/fleet/drivers/{id}` (NEW)
**Roles:** `logistics_manager`, `admin`.

**Frontend:** Add **Delete** on driver row/detail with confirm dialog.

### POST `/api/fleet/drivers/{id}/assign` (NEW)
**Request:** `{ "vehicle_id": 7 }` (optional).

Sends email to driver (`DriverAssignedMail`) and logistics managers (`DriverAssignmentManagerMail`).

**Frontend:** After designating/assigning a driver to a vehicle, call this endpoint (add button if assignment UI exists without notify).

### POST `/api/fleet/drivers` (EXISTING — validation updated)
**Request:**
```json
{
  "name": "John Driver",
  "phone_number": "+2348012345678",
  "email": "optional@example.com",
  "license_number": "ABC123"
}
```

`phone_number` **required**; `email` **optional**.

**Frontend:** Update **Designate Staff Driver** form: compulsory phone, optional email.

---

## 9. Vendor Budget on MRF/SRF Line Items

Same as §2: each line item in `items[]` must include `budgetAmount` on create.

**Frontend:** On MRF and SRF forms, per line row: Item name, Qty, Unit, **Budget (₦)**. Map to `items[].budgetAmount`.

---

## 10. Organization-Wide Trip Visibility (NEW — all staff, read-only)

Every authenticated staff member, regardless of department or role, can browse **all** trip requests across the organization (pending and approved) and open any trip's full detail read-only. This is a **separate** view from the Logistics Manager's actionable pending-approval inbox.

### GET `/api/trip-requests/all` (NEW)
**Roles:** Any authenticated user.

**Query params (optional):** `status`, `q` (search destination/origin/purpose/code), `limit`/`per_page`.

**Response:**
```json
{
  "success": true,
  "data": {
    "trips": [
      {
        "id": 42,
        "tripCode": "TRQ-20260617-AB12CD",
        "requesterName": "Frank Procurement",
        "requesterDepartment": "Procurement",
        "destination": "Lagos Airport",
        "scheduledDepartureAt": "2026-06-20T08:00:00Z",
        "scheduledArrivalAt": "2026-06-20T18:00:00Z",
        "status": "submitted",
        "displayStatus": "approved",
        "displayStatusLabel": "Approved",
        "logisticsTripId": 87,
        "linkedTripStatus": "scheduled",
        "viewer": { "isInvolved": false, "canManage": false, "readOnly": true }
      }
    ],
    "pagination": { "total": 25, "per_page": 50, "current_page": 1, "last_page": 1 }
  }
}
```

**`displayStatus`** (use this for the list status column, not raw `status`): resolves the request's own state combined with the linked logistics trip's operational progress. Values: `draft`, `pending` (submitted, awaiting LM), `approved` (confirmed, trip scheduled), `in_progress`, `completed`, `rejected`. `displayStatusLabel` is the title-cased version. Raw `status`/`workflow_stage` and `linkedTripStatus` are still included for advanced use.

**Distinct from** `GET /api/trip-requests` — that endpoint still returns the Logistics Manager's **actionable** pending queue and only own/passenger requests for regular staff. Use `/trip-requests/all` for the general browsing list.

**LM pending inbox semantics:** `GET /api/trip-requests` (or `?status=submitted`) for a logistics role now returns only requests **still awaiting first action** (`status=submitted` AND `workflow_stage=trip_request`). Once a request is confirmed (advances to `logistics_review`) or rejected (`cancelled`), it automatically drops out of the pending panel — so approved/rejected requests no longer linger in the LM's queue. Pass an explicit non-pending `status` (e.g. `?status=cancelled`) to query those subsets.

### Detail endpoints relaxed to read-only org-wide
These now allow **any authenticated user** to read (mutating actions remain gated separately):
- `GET /api/trip-requests/{id}` — full request detail
- `GET /api/trip-requests/{id}/progress-tracker`
- `GET /api/trip-requests/{id}/comments` — returns `canComment` flag
- `GET /api/trips/{id}` — full logistics trip detail (passengers, vehicle, driver, journeys)
- `GET /api/trips/{id}/comments` — returns `canComment` flag

Each detail payload includes a `viewer` block and top-level `canManage` / `readOnly` flags:
```json
"viewer": { "isInvolved": false, "canManage": false, "readOnly": true }
```

- `canManage` = `true` only for logistics/internal roles (LM, officer, admin, procurement, SCD).
- `isInvolved` = requester, assigned passenger, or assigned driver.
- **Comments are read-only for non-involved staff** (confirmed product decision): `POST /trip-requests/{id}/comments` and `POST /trips/{id}/comments` still return 403 unless the user is involved or logistics. Use the `canComment` flag to show/hide the comment composer.

**Frontend:** Add a dedicated **All Trips** page/section (e.g. `/trips` browse or a dashboard tab) visible to every staff member, listing requester name + department, destination, trip dates, and status. Clicking a row opens the existing trip detail view in read-only mode when `readOnly === true` — hide approve/reject/assign/edit controls and the comment composer (`canComment === false`). Keep this clearly separate from the LM's **Pending Trip Requests** approval queue.

---

## 11. Procurement Overview — Logistics Manager (read-only)

**Role:** `logistics_manager` (alias `logistics`)  
**Frontend route:** `/procurement` — **Procurement Overview** (view-only; hide **Create PO** when `isProcurementOverviewOnly()`).

### List + dashboard (must return **200**)

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/mrfs` | Full org MRF list (no requester filter for LM) |
| `GET` | `/api/srfs` | Full org SRF list by default; optional `?scope=logistics` or `?logistics_only=1` for fleet-scoped list |
| `GET` | `/api/dashboard/procurement-manager` | Same payload as procurement manager; includes `readOnly`, `isProcurementOverviewOnly`, `canManageProcurement` flags |

### Detail reads allowed

- `GET /api/mrfs/{id}`, `full-details`, `progress-tracker`, `available-actions`
- `GET /api/mrfs/{id}/price-comparisons`
- `GET /api/srfs/{id}`, `progress-tracker`, line items
- `GET` procurement documents, finance sync, delivery confirmation (existing read endpoints)

`GET /api/mrfs/{id}/available-actions` returns `readOnly: true` and strips all mutation flags for LM.

### GRN / JCC delivery documents (logistics + procurement)

| Method | Path | Roles |
|--------|------|-------|
| `GET` | `/api/mrfs/{id}/grn/prefill` | `ProcurementOverviewAccess::DELIVERY_DOCUMENT_ROLES` |
| `GET`/`POST` | `/api/mrfs/{id}/grn/preview` | same |
| `POST` | `/api/mrfs/{id}/grn/generate` | same |

Includes `logistics_manager`, `logistics_officer`, `supply_chain_director`, and procurement roles. Workflow stage must satisfy `canGenerateGRN` (PO signed onward). LM procurement overview remains read-only for approve/PO/payment, **not** for GRN/JCC delivery docs.

`GET /api/mrfs/{id}/grn/prefill` returns `vendor`, `supplier`, `lineItems`, `po`, `grnNumber`, `mrfRef`, `category` — sourced from price comparisons (same as PO), MRF line items, linked SRF items, or MRF header fallback.

### Blocked for LM on procurement overview (403)

All workflow mutations remain blocked: approve/reject MRF, generate/sign PO, payment, price comparison `PUT`/`POST`, etc. **GRN preview/generate and JCC/waybill uploads** are allowed for LM when the MRF is at the correct workflow stage. LM may still **create MRFs** from logistics flows via `POST /api/mrfs`.

### Backend helper

`App\Support\ProcurementOverviewAccess` mirrors frontend `src/utils/procurementAccess.ts`.

---

## 12. Manual PO vendor creation — dedupe & onboarding

Full contract: **[`docs/manual-po-vendor-spec.md`](docs/manual-po-vendor-spec.md)**

Quick reference:

| Step | Endpoint | Backend |
|------|----------|---------|
| Duplicate lookup | `GET /api/vendors/lookup?email=&name=` | Authoritative match; email wins over name |
| Save price sheet | `PUT /api/mrfs/{id}/price-comparisons` | Find-or-create vendor; email + phone required (422) |
| Finalise PO | `POST /api/mrfs/{id}/generate-po` | Portal user + onboarding email; `resolvedVendors` in response |
| Complete profile | `PUT /api/vendors/auth/profile` | Extended fields; sets `profile_completed` |
| Bulk delete directory rows | `POST /api/vendors/bulk-delete` `{ "ids": ["V023", ...] }` | Deletes vendors; skips rows with active quotations |

- `GET /api/vendors` excludes `Inactive` by default.
- Vendor Directory UI: row checkboxes + **Delete selected** (replaces inactive-merged audit toggle).
- Legacy duplicate cleanup: `php artisan vendors:merge-duplicates --list` then `--purge-merged --force`.

### Vendor directory export (role-gated)

**Roles:** `procurement_manager`, `supply_chain_director`, `executive`, `logistics_manager` only. All other roles receive **403** and must not see the Export button.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/vendors/export` | `GET` | Stream **PDF** or **Excel (XLSX)** download |
| `/api/vendors/export/rows` | `GET` | JSON row data for **client-side CSV** assembly |
| `/api/vendors/export/columns` | `GET` | Column catalogue (`key`, `label`) |

**Query parameters (export + rows):**

| Param | Required | Notes |
|-------|----------|-------|
| `format` | PDF/XLSX only | `pdf` or `xlsx` |
| `columns` | Yes | Comma-separated keys (see below). At least one required. |
| `search` | No | Same as `GET /api/vendors` |
| `status` | No | Same as directory filter |
| `category` | No | Same as directory filter |
| `limit` | No | Positive integer (max 10,000) or `all` |
| `include_inactive` | No | `1` to include inactive rows |

**Selectable columns:** `vendor_id`, `company_name`, `category`, `email`, `phone`, `address`, `tax_id`, `contact_person`, `bank_name`, `account_number`, `account_name`, `currency`, `registration_status`, `registration_date`, `document_status`.

**Filename:** `Vendor_Directory_{YYYY-MM-DD}.pdf` / `.xlsx` / `.csv`

**Filters:** Export applies the same directory query as `GET /api/vendors`. When any filter is active, UI shows: *"Exporting filtered results only. Clear filters to export all vendors."*

**PDF:** Server-generated with Emerald logo + company header (`PurchaseOrderPdfService::logoHtml()`), landscape A4 table.

**CSV:** Browser assembles the file from `GET /api/vendors/export/rows` (UTF-8 with BOM). PDF and XLSX are server-streamed.

**Row limit:** `limit` query param — positive integer (max 10,000) or `all` (exports up to 10,000 matching rows). UI: **Number of records** field in export modal.

**File integrity:** XLSX uses PhpSpreadsheet (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`). CSV uses UTF-8 BOM (`text/csv; charset=UTF-8`).

**UI:** Vendors page → **Export** button → modal with format radio + column checklist (Select all / Deselect all). Selection persisted in `sessionStorage` (`vendor_directory_export_columns`). No download until user confirms.

### Universal table export (frontend)

Reusable **`TableExportMenu`** + **`useTableExport`** (`src/components/export/TableExportMenu.tsx`, `src/hooks/useTableExport.ts`):

- Formats: **CSV** (UTF-8 BOM, client-assembled) and **XLSX** (SheetJS, client-assembled).
- **Number of records** input (number or `All`, max 10,000).
- Inherits table **search, status, date, and sort** via each page’s `fetchPage` callback.
- Wired on: Procurement **MRF**, **Purchase Orders**, **Service Requests**; **RFQ Management**; Logistics **All Trips**.

Column presets: `src/config/tableExportPresets.ts`.

---

## 13. PO numbering — `PO-DDMMYY-SupplierToken-NNNN`

Full contract: **[`docs/po-numbering-spec.md`](docs/po-numbering-spec.md)**

The backend now generates the authoritative PO number in the canonical format,
matching the frontend formatter `src/utils/poNumber.ts`.

| Segment | Rule | Example |
|---------|------|---------|
| `DDMMYY` | PO creation date | `220626` |
| `SupplierToken` | Supplier name, non-alphanumerics removed, casing preserved, ≤30 chars | `MochenzComputers` |
| `NNNN` | 4-digit serial, resets per supplier per day | `0001` |

- `POST /api/mrfs/{id}/generate-po` finalise assigns + persists `po_number`; returned on `data.mrf.po_number` and on `GET /api/mrfs` rows.
- Drafts (`save_as_draft: true`) do **not** burn a serial.
- Regeneration keeps the existing `po_number`.
- A caller-supplied `po_number` is still honoured (frontend preview path).
- `POST /api/trips/{id}/generate-trip-po`: `po_number` is now optional; backend auto-generates from the carrier/vendor name when omitted.
- Existing PO numbers are untouched.

---

## 14. Finance AP vendor sync (Pattern A)

SCM is the **vendor master**. Finance AP receives read-only vendor snapshots via push (automatic on create/update + package/delta).

**Contract:** [`docs/FINANCE_AP_VENDOR_SYNC_PATTERN_A.md`](docs/FINANCE_AP_VENDOR_SYNC_PATTERN_A.md)

| Side | Status |
|------|--------|
| SCM push (`FinanceApVendorSyncService`, `finance-ap:sync-vendors`) | Done |
| FA ingest (`POST /api/v1/integrations/scm/vendors`) | Done |
| FA list UI (`GET /api/v1/vendors`) | Verify after `php artisan finance-ap:sync-vendors --force` on SCM |

---

```bash
php artisan migrate
```

Migrations:
- `2026_06_22_120000_create_po_number_sequences_table.php` (PO serial counters)
- `2026_05_20_160000_scm_platform_feature_enhancements.php`
- `2026_05_21_120000_rename_line_item_tables.php` (renames `mrf_items` → `mrf_line_items`, adds `quoted_amount`)
- `2026_05_19_000001_contract_type_free_text.php` (if not applied)
- `2026_06_17_100000_create_logistics_trip_comments_table.php` (trip comments)

---

## UI Checklist (must be reachable)

- [ ] MRF contract type combobox + custom text
- [ ] MRF/SRF line items with budget column
- [ ] MRF/SRF detail P&L table
- [ ] Procurement reports page + export
- [ ] Dashboard KPI tiles (4 counters)

---

## Reporting Module Overhaul (Jul 2026)

### GET `/api/reports/dashboard`
**Query:** `from`, `to` (optional ISO dates; defaults to last 30 days).

**Roles:** procurement_manager, procurement, supply_chain_director, supply_chain, admin, finance, finance_officer, logistics_manager, logistics_officer.

**Response `data`:**
- `period` — `{ from, to }`
- `kpis[]` — `{ name, value, rawValue, unit, change, trend }` for Procurement Cycle Time, Inventory Turnover, On-Time Delivery, Cost Savings (all computed server-side)
- `recentReports[]` — rows from `scm_generated_reports`
- `scheduledReports[]` — active rows from `scm_scheduled_reports`

**Frontend:** `/reports` dashboard — KPI cards, recent/scheduled lists, date filter.

### GET `/api/config/finance-routing`
**Auth:** required.

**Response `data`:** `{ cutoverDate, routingConfigured, description }` — sourced from server `FINANCE_AP_CUTOVER_DATE` (source of truth).

**Frontend:** Fetched on login via `financeRoutingConfig.ts`; replaces reliance on empty `VITE_FINANCE_AP_CUTOVER_DATE`. Finance AP reports section always loads API data; shows server cutover warning when `routingConfigured` is false.

### GET `/api/reports/procurement/records`
**Query:** `from`, `to`, `department`, `vendor_id`, `status`, `search`, `page`, `per_page`, `sort_by`, `sort_direction`.

**Response `data`:** `{ period, items[], pagination }` — each item includes `detailPath` for drill-down.

**Frontend:** Procurement Reporting Engine table — filters, pagination, row click → MRF detail.

### GET `/api/reports/procurement/records/{id}`
**Response `data.record`:** MRF summary + line items for drill-down panel.

### GET `/api/reports/procurement/records/export`
**Query:** same filters as records + `format` = `csv` | `xlsx` | `pdf`.

**Response:** File stream generated server-side (CSV, SpreadsheetML `.xls`, or dompdf PDF).

**Frontend:** Export buttons on Procurement Reporting Engine — no client-side jsPDF/SheetJS for this flow.

### Finance AP summary field mapping (fix)
Backend `totals` keys: `packagePushed`, `financeHandoffPending`, `inReviewOrPaying`, `closedOrComplete`. Frontend `financeApReportsApi.normalizeSummary` updated to map these correctly.

### Procurement report performance (fix)
`ProcurementReportService::aggregateSavingsLoss` — SQL aggregation for line items with `quoted_amount`; quotation lookup only for MRFs/SRFs missing quoted data. Fixes infinite loading on large datasets.

### Report performance — Section 7 (Jul 2026)

**Backend (5-minute server cache via `ReportCache`, TTL 300s):**
- `GET /api/reports/dashboard` — full dashboard payload cached per date range; cycle time uses SQL `AVG` instead of PHP `chunkById`; cost savings KPI uses lightweight `totalSavingsForPeriod()` (no full procurement report rebuild).
- `GET /api/reports/procurement` — `buildReport()` cached per date range; savings aggregation uses period subqueries (no `pluck('id')` into PHP memory).
- `GET /api/reports/finance-ap/*` — summary, outstanding milestones, advance delivery risk, and cycle times cached per filters.
- `GET /api/reports/procurement/records` — defaults to last 30 days when `from`/`to` omitted; search uses indexed `mrf_id` / `formatted_id` only (no `title LIKE`).

**Migration:** `2026_07_02_160000_add_report_query_indexes.php` — indexes on `po_signed_at`, `(created_at, po_signed_at)`, `quotations.updated_at`, `price_comparisons (purchase_order_id, created_at)`, line-item budget columns, `materials (status, updated_at)`.

**Frontend (React Query `REPORT_QUERY_OPTIONS` — 5 min stale time):**
- `FinanceApReportsSection` — parallel `useQueries` for summary, outstanding, risk, cycle times; `TableSkeleton` on first load; Apply button updates query keys (no mount-time refetch storm).
- `ProcurementReportingEngine` — 300ms debounced search; `TableSkeleton` while loading.
- `MRFProgressTracker` — `useQuery` with `WORKFLOW_QUERY_OPTIONS` (30s stale); skeleton instead of spinner.

**Budget analysis:** Procurement Reports totals cards (savings / loss / net variance / line items with budget) are server-aggregated — no client-side totals over full datasets.

### Procurement report — direct record navigation (Section 8, Jul 2026)

**Root cause:** Row click used `navigate(detailPath)` → `/procurement?mrf=…`, remounting the full Procurement page. The deep-link handler only searched the **current paginated MRF list** (25 rows), so records outside that page never opened.

**Frontend:**
- `ProcurementReportingEngine` — row click opens `ProcurementRecordDetailSheet` (side drawer) via `GET /api/reports/procurement/records/{id}`; no full page transition.
- Optional **Open full MRF in Procurement** button navigates to `/procurement?mrf={displayId}` only when the user explicitly wants the full module.
- `Procurement.tsx` deep link — fetches `GET /api/mrfs/{id}` directly when the MRF is not in the cached list page; opens the existing MRF details dialog without forcing a tab switch or waiting for the full list.

**Backend:** Each procurement record row includes `mrfLinkId` (same as `displayId`) for stable deep links alongside `detailPath`.

### Executive role — Reports & Documents access (Section 9, Jul 2026)

**Root cause:** Report API controllers omitted `executive` from allowed roles; `ProcurementReports.tsx` redirected non-listed roles to `/dashboard` (403 on load).

**Backend:** Shared `App\Support\ScmReportViewerRoles` — includes `executive` on:
- `GET /api/reports/dashboard`
- `GET /api/reports/procurement` (+ export)
- `GET /api/reports/procurement/records` (+ export, detail)
- `GET /api/reports/finance-ap/*`

Supply Chain Director permissions unchanged.

**Frontend:**
- `src/utils/reportAccess.ts` — `canViewScmReports()` / `SCM_REPORT_VIEW_ROLES`
- `ProcurementReports.tsx` — uses shared helper (no redirect for executive)
- `FinanceApReportsSection` — executive can view Finance AP cohort on procurement reports page
- `ProcurementDocumentsPanel` — `readOnly` prop; shown in MRF details dialog for executive (view/download registry, no upload)

### Logout — instant client sign-out (Section 10, Jul 2026)

**Root cause:** `AuthContext.logout()` awaited `POST /api/auth/logout` before clearing `user` state and storage, so slow or hung requests blocked redirect and left `isAuthenticated` true until the API returned.

**Frontend:**
- `src/lib/authSession.ts` — synchronous `clearStaffAuthStorage()` / `clearVendorAuthStorage()`; cancels and clears React Query cache.
- `AuthContext.logout()` — clears local session immediately, then `authApi.revokeTokenInBackground(token)` (4s abort, `keepalive`).
- `VendorPortal` logout — same pattern via `vendorAuthApi.revokeTokenInBackground`.
- `POST /api/auth/logout` remains available for explicit await flows; normal UI logout does not block on it.

**Backend:** `POST /api/auth/logout` — null-safe token delete; clears `user_activity_{id}` cache entry.

---

## SCM Platform — Logistics Trip Request Workflow (Section 2, Jul 2026)

### Trip booking scopes (updated)
`GET /api/trip-requests/booking-rules` now returns:
- `within_state` — **Within State**, 2-day minimum lead
- `out_of_state_local` — **Out of State (Local)**, 7-day minimum lead
- `international` — **International (Out of Nigeria)**, 14-day minimum lead

Legacy value `outside_state` maps to `out_of_state_local` on read.

### Workflow stages (trip requests, `TRQ-*`)
| Stage | Meaning |
|-------|---------|
| `trip_request` | Submitted — awaiting LM review |
| `changes_requested` | LM returned to employee for edits |
| `director_review` | Forwarded — awaiting Supervising Director |
| `director_approved` | Director approved — LM may convert |
| `logistics_review` | Converted to logistics trip |

### POST `/api/trip-requests/{id}/forward` (NEW)
**Auth:** logistics role. **Body:** `{ notes?: string }`. Forwards to Supervising Director (`workflow_stage=director_review`). Notifies LM, SCD, procurement.

### POST `/api/trip-requests/{id}/request-changes` (NEW)
**Auth:** logistics role. **Body:** `{ reason: string }` (required). Sets `workflow_stage=changes_requested`.

### POST `/api/trip-requests/{id}/director-approve` (NEW)
**Auth:** `supply_chain_director` or `supply_chain`. Sets `workflow_stage=director_approved`.

### POST `/api/trip-requests/{id}/director-reject` (NEW)
**Auth:** supervising director. **Body:** `{ reason?: string }`. Cancels request.

### POST `/api/trip-requests/{id}/director-return` (NEW)
**Auth:** supervising director. **Body:** `{ reason: string }` (required). Returns to employee (`changes_requested`).

### POST `/api/trip-requests/{id}/convert` (NEW)
**Auth:** logistics role. **Body:**
```json
{
  "fulfillment_type": "internal_vehicle" | "external_vendor",
  "vehicle_id": "…",
  "driver_user_id": 123,
  "vendor_id": "…",
  "vehicle_type": "…",
  "driver_name": "…",
  "estimated_vendor_cost": 0
}
```
- `internal_vehicle` — requires `vehicle_id` + `driver_user_id`
- `external_vendor` — requires `vendor_id`, `vehicle_type`, `driver_name`, `estimated_vendor_cost`

Auto-migrates passengers to the linked logistics trip (`TRIP-*`). Sets `workflow_stage=logistics_review`.

### POST `/api/trip-requests/{id}/confirm` (DEPRECATED)
Returns **422** `WORKFLOW_DEPRECATED`. Use forward → director-approve → convert instead.

### GET `/api/trip-requests/{id}` — `availableActions`
Response trip payload includes `availableActions` array for the current viewer:
- LM on `trip_request` / `changes_requested`: `forward`, `reject`, `request_changes`
- LM on `director_approved`: `convert`
- Supervising Director on `director_review`: `director_approve`, `director_reject`, `director_return`

### LM pending inbox (`GET /api/trip-requests`)
For logistics roles with default/`submitted` filter: returns `status=submitted` AND `workflow_stage` in (`trip_request`, `changes_requested`).

### Progress tracker (`GET /api/trip-requests/{id}/progress-tracker`)
Four steps: Submitted → Logistics Manager Review → Supervising Director Approval → Logistics Request.

### Vendor search for conversion (`GET /api/vendors`)
Supports `search` and `per_page` query params for async vendor dropdown during external-vendor conversion.

**Frontend:**
- `TripRequestWorkflowActions` — LM/SCD action buttons driven by `availableActions`
- `TripRequestConversionDialog` — internal vehicle vs external vendor conversion
- `TripRequestDetailPage` / `PendingTripRequestsPanel` — legacy Approve & assign removed
- Trip type form options: `within_state`, `out_of_state_local`, `international`

---

## SCM Platform — Database Performance & Global Pagination (Section 3, Jul 2026)

### Standard paginated list response
```json
{
  "success": true,
  "data": [],
  "pagination": { "page": 1, "per_page": 25, "total": 312, "total_pages": 13, "from": 1, "to": 25 }
}
```

Logistics lists use `data.trips` / `data.vehicles` plus `data.pagination`.

**Common query params:** `page`, `per_page` (default 25), `sort_by`, `sort_direction`, `search`.

### GET `/api/vendors` (updated)
Paginated directory; explicit column select. **Frontend:** `vendorApi.list()`, Suppliers page, async vendor search in PO/RFQ dialogs.

### GET `/api/mrfs` (updated)
`per_page` default 25; filters `po_list`, `has_po`, `date_from`, `date_to`, `workflow_state`. List omits `priceComparisons` (detail only).

### GET `/api/trips` / GET `/api/fleet/vehicles` (updated)
Search, sort, pagination (25/page).

### Migration `2026_07_01_140000_add_list_query_indexes`
Indexes on frequently filtered columns for MRFs, vendors, trips, vehicles.

**Frontend components:** `ServerPaginationBar`, `AsyncVendorSearchSelect`, `paginatedListApi` helpers.

---

## SCM Platform — Platform Speed & Hot Reload (Section 4, Jul 2026)

### Frontend route code-splitting
All dashboard, procurement, logistics, reports, and detail pages load via `React.lazy()` (`src/routes/lazyPages.ts`). Initial bundle excludes heavy modules until navigated.

### Vite build chunks
`manualChunks` splits: `charts` (recharts), `pdf` (jspdf/html2canvas), `xlsx`, `radix-ui`, `react-query`, `router`.

### React Query defaults (`src/lib/queryClient.ts`)
- `staleTime`: 60s, `gcTime`: 5m, `refetchOnWindowFocus`: false
- `STABLE_QUERY_OPTIONS` export for 10m stale config endpoints

### Deploy update banner (`AppUpdateBanner`)
- Build emits `dist/version.json` with `{ buildId, builtAt }`
- App polls `/version.json` every 5 minutes (first check after 30s)
- Non-blocking bottom banner: **"A new update is available. Click to refresh."** — user-initiated full reload only

**Dev:** `public/version.json` uses `buildId: dev-local` (no false positives).

### API response compression (backend)
`CompressJsonResponse` middleware gzip-encodes JSON/text API responses ≥1KB when `Accept-Encoding: gzip`.

**Frontend:** No change required — browsers send `Accept-Encoding: gzip` automatically.

---

## SCM Platform — Polish, Finance Handoff & Critical Fixes (Section 5, Jul 2026)

### Priority 0 — Critical bugs (fixed)

| Issue | Root cause | Fix |
|-------|------------|-----|
| Vendors page crash: `getScmRole is not defined` | Missing import after Section 3 pagination edit | Restored `import { getScmRole } from "@/utils/scmRole"` in `Vendors.tsx` |
| RFQ Management crash: lexical declaration before init | `useEffect` referenced `createDialogOpen` / `selectionMethod` before `useState` | Reordered state declarations above effects in `RFQManagement.tsx` |
| Logistics journey crash: `F.status is undefined` | `linkedTrip.status.replace()` when trip has no status | `formatJourneyStatus()` + safe status key helper in `JourneyManagement.tsx` |
| Pagination / list loads take minutes | `MRFController@index` called `generateFreshPOUrls()` per row (multiple S3 `exists()` round-trips × 25 rows) | List endpoint returns stored PO URLs only; fresh signed URLs remain on `GET /mrfs/{id}` |

### Priority 0 — Performance (fixed)

- **Backend:** `GET /api/mrfs` list no longer regenerates PO URLs per row.
- **Frontend (`Procurement.tsx`):** Debounced search (400ms), stale-request guards on paginated fetches, filter changes reset page without duplicate in-flight requests.

### Remaining Section 5 scope — completed (Jul 2026)

#### RFQ auto-refresh
- `useRfqDataRefresh` hook — 30s poll while visible + `app:refresh` listener
- `RFQManagement` uses hook; initial load shows `TableSkeleton`
- `AppContext` `app:refresh` now refreshes RFQs + quotations
- `Procurement` uses AppContext `rfqs` / `quotations` (removed duplicate local state)
- `rfqApi.list()` + paginated `GET /api/rfqs` response (`success`, `data`, `pagination`)
- `fetchAllListPages()` helper; `mrfApi.getAll`, `vendorApi.getAll`, `rfqApi.getAll` walk all pages (capped)

#### Finance Handoff Pending
- `normalizeFinanceDashboard()` maps API keys (`financeHandoffPending` → `handoff`, etc.)
- Finance Dashboard: **Handoff pending** stat card (click to filter) + list tab `handoff_pending`

#### Finance AP Sync dashboard
- `GET /api/reports/finance-ap/sync-events` — recent `finance_sync_events` with summary counts
- `FinanceApSyncDashboard` on Finance Dashboard (failed / pending / vendor sync failures)

#### Skeleton loaders & notifications
- `DashboardSkeleton` / `TableSkeleton` on Finance Dashboard and RFQ list
- `NotificationCenter` skeleton on first fetch; backend `priority` respected
- Notification poll only when tab is visible

### Deploy checklist
- Run `php artisan migrate` (includes `2026_07_01_140000_add_list_query_indexes`)

### Reporting SQL fixes (post–Section 5)
- **Reports dashboard on-time delivery:** join `r_f_q_s` (not `rfqs`) — `ReportsDashboardService`, `DashboardController`
- **Procurement records engine:** MRF vendor column is `selected_vendor_id` / relation `selectedVendor` (not `vendor_id`) — `ReportingEngineService`
- **Procurement report infinite load:** `ProcurementReportService` defaults to last 30 days when dates omitted; header-level savings/loss via SQL instead of per-MRF `mrfProfitAndLoss()` loops with quotation N+1

### API note — MRF list PO URLs

`GET /api/mrfs` (paginated list) returns **stored** `unsigned_po_url` / `signed_po_url` from the database. Call `GET /api/mrfs/{id}` when the UI needs freshly signed download URLs.

---

## SCM Platform — Performance Regression Fix (Section 1, Jul 2026)

### Root cause
Server-side pagination was added to list endpoints (`GET /api/mrfs`, `/vendors`, `/rfqs`) but many frontend views still called `getAll()` via `fetchAllListPages()`, which walks up to 5 sequential API pages per resource. Combined with GlobalSearch prefetching 3× `getAll()` on every search, AppContext bootstrapping 40 quotation fetches, and 30s polling on Dashboard/RFQ views, this made the app slower than before the “optimization”.

### Backend changes

#### `GET /api/rfqs` (list — performance)
- Default `per_page` is now **25** (was 50).
- List response omits `paymentSchedule` / `supportingDocuments` (detail-only; use `GET /api/rfqs/{id}`).
- Eager loads trimmed to `mrf` (id/title/cost only), `creator`, `vendors` — no payment-schedule milestones on list.

### Frontend changes (emerald-supply-chain)
- **GlobalSearch:** server-side `/search` only; removed local full-dataset prefetch.
- **Dashboards:** Executive, Chairman, Supply Chain, Department use `mrfApi.list({ page: 1, per_page: 100 })` instead of `getAll()`.
- **Procurement Dashboard:** recent activities from `GET /dashboard/recent-activities`; removed 30s MRF poll.
- **Vendors page:** vendor counts from dashboard stats; no `getAll()` on load.
- **AppContext:** bootstrap loads first page only (25 rows); quotations deferred to RFQ views.
- **RFQ refresh hook:** removed 30s polling; refresh on mount + `app:refresh` only.
- **`fetchAllListPages`:** max pages capped at **2** (was 5) for remaining `getAll()` callers (exports/dropdowns).

---

## SCM Platform — Smart Client-Side Caching (Section 2, Jul 2026)

React Query is now the primary cache for list, report, and reference data. Stale-time presets in `src/lib/queryOptions.ts`:

| Preset | `staleTime` | Use for |
|--------|-------------|---------|
| `STABLE_QUERY_OPTIONS` | 10 min | User directory, vendor directory |
| `LIST_QUERY_OPTIONS` | 2 min | Paginated MRF / PO / vendor lists |
| `REPORT_QUERY_OPTIONS` | 5 min | Reports dashboard, procurement reports, records engine |
| `WORKFLOW_QUERY_OPTIONS` | 30 sec | Executive / Chairman / SCD approval dashboards |

### Infrastructure
- `src/lib/queryKeys.ts` — hierarchical keys for targeted invalidation
- `src/lib/invalidateScmCache.ts` — `invalidateMrfLists`, `invalidateVendorLists`, `invalidateUserLists`, `invalidateScmListCaches` (header refresh)
- `src/hooks/usePaginatedListQuery.ts` — cached paginated lists with `keepPreviousData` for instant page transitions

### Header refresh button
`DashboardLayout` now calls `invalidateScmListCaches()` instead of `queryClient.invalidateQueries()` (which wiped the entire cache). Active queries refetch automatically; stale cached pages reopen instantly until invalidated.

### Pages migrated to React Query
- **Procurement** — MRF + PO tabs (`queryKeys.mrfs.list`, `queryKeys.pos.list`)
- **Vendors** — directory list (`queryKeys.vendors.list`)
- **User Management** — user list (`queryKeys.users.list`)
- **Reports** — analytics dashboard (`queryKeys.reports.dashboard`)
- **Procurement Reports** — summary + records engine
- **Executive / Chairman / Supply Chain dashboards** — workflow MRF lists (30s stale)

### Mutation invalidation (targeted)
- MRF delete → `invalidateMrfLists`
- Vendor delete / bulk delete / rating → `invalidateVendorLists`
- User create / update / delete → `invalidateUserLists`

No backend API changes in this section.

---

## SCM Platform — MRF Module Performance & Delete (Section 3, Jul 2026)

### `GET /api/mrfs` (list — performance)
- Selects only `MRF::LIST_API_SELECT` columns (omits `description`, `justification`, `approval_history` JSON, `custom_terms`, etc.).
- List rows use `MRF::toListApiArray()` — no per-row `RequesterEditWindowService` calls; `approvalHistory` and `priceComparisons` are empty on list (detail endpoint only).
- Search (`?search=`) matches **indexed identifier columns only**: `mrf_id`, `formatted_id`, `po_number` (title/description LIKE removed).
- Default `per_page` remains **25**; max 100.

### `DELETE /api/mrfs/{id}` (performance)
- Single `DB::transaction` + `$mrf->delete()` — relies on FK cascades instead of slow explicit `rfqs()->delete()` loops.
- Fixed undefined `$hasPO` in 403 response (`hasUnsignedPO || hasSignedPO`).

### Migration `2026_07_02_120000_add_mrf_list_search_indexes`
- Indexes on `m_r_f_s.created_at` and `m_r_f_s.formatted_id` (in addition to existing `status+created_at`, `requester_id` from Jul 1 migration).

### Frontend (emerald-supply-chain)
- MRF Official + All MRFs share one cached React Query list (`enabled` when either tab is active).
- **All MRFs** tab now includes `ServerPaginationBar` (was missing — only showed first page).
- MRF delete: **optimistic removal** via `optimisticallyRemoveMrfFromCache`, then `invalidateMrfLists` on success/failure.
- MRF search debounce reduced to **300ms**.

---

## SCM Platform — Refresh Button Fix (Platform Plan §4, Jul 2026)

No backend API changes.

### Root cause
- Header refresh called `refreshQuotations()` **without an RFQ list**, clearing all quotation data in AppContext.
- Many pages still used local `useState` loaders and did not listen for `app:refresh`.
- Procurement **PullToRefresh** was a no-op (1s timeout only).

### Frontend (emerald-supply-chain)
- `src/lib/refreshScmData.ts` — `refreshScmApplicationData()`: invalidate active React Query caches, then dispatch `app:refresh`.
- `src/hooks/useScmAppRefreshListener.ts` — pages register local-state refetch handlers.
- `src/lib/invalidateScmCache.ts` — `refetchType: 'active'`; includes `departments` keys.
- `DashboardLayout` — centralized refresh; in-progress + success toasts; removed broken AppContext quotation wipe.
- Wired `app:refresh` listeners: Procurement, Dashboard, Executive, Chairman, Supply Chain Director, Vendors.
- Procurement PullToRefresh now runs the same real refresh as the header button.

---

## SCM Platform — PO Draft Visibility (Platform Plan §5, Jul 2026)

### `GET /api/mrfs?po_list=1` (list — PO tab)
- **Includes PO drafts**: rows with `po_draft_saved_at` set and no `unsigned_po_url` (matches `MRF::isPoDraft()`), not only rows with `po_number`.
- **`status` filter on PO list** uses PO lifecycle buckets (`draft`, `pending`, `signed`, `rejected`, `completed`) instead of the raw MRF `status` column.
- Default sort for `po_list=1` is **`updated_at` desc** so recently saved drafts surface on page 1.
- List rows now include `is_po_draft`, `po_draft_saved_at`, and PO origin fields via `toListApiArray()`.

### `POST /api/mrfs/{id}/generate-po` with `save_as_draft: true`
- Auto-assigns a canonical **PO number** on first draft save when the client omits one (same generator as finalisation).

### Frontend (emerald-supply-chain)
- After draft save: refreshes PO list cache, switches to **Purchase Orders** tab.
- Draft badge uses `is_po_draft` from API (not “missing po_number”).

---

## SCM Platform — Search Performance (Platform Plan §6, Jul 2026)

### Migration `2026_07_02_140000_add_module_search_indexes`
Indexes on `m_r_f_s.po_number`, `m_r_f_s.requester_name`, `r_f_q_s.formatted_id`, `r_f_q_s.rfq_id`, `s_r_f_s.formatted_id`, `s_r_f_s.srf_id`, `s_r_f_s.requester_name`.

### `GET /api/mrfs` (search)
- `?search=` matches indexed columns: `mrf_id`, `formatted_id`, `po_number`, `requester_name` (no title/description scans).

### `GET /api/rfqs` (search — new)
- `?search=` matches `rfq_id`, `formatted_id`, linked MRF `mrf_id` / `formatted_id`.
- Supports `date_from`, `date_to`, sort params; list includes `quotations_count` and `vendors_count`.

### `GET /api/srfs` (search — aligned with MRF pattern)
- `?search=` matches `srf_id`, `formatted_id`, `requester_name` (replaces title/description LIKE).
- Default `per_page` **25**; `include_line_items` defaults **false** for list performance.
- Supports `date_from`, `date_to`, sort params.

### Frontend (emerald-supply-chain)
- **MRF / PO tabs**: 300ms debounced server-side search; placeholders match indexed fields.
- **SRF tab**: migrated to `srfApi.list` + React Query pagination (was client filter on 25 AppContext rows).
- **RFQ Management**: paginated `rfqApi.list` with 300ms debounced search, `ListControls`, pagination; removed mount-time quotation bootstrap storm; quote counts from API `quotations_count`.

---

## SCM Platform — MRF Parallel First Approval (Platform Plan §11, Jul 2026)

### Migration `2026_07_02_180000_add_parallel_first_approval_mrf_workflow`
- Adds `first_approval_by_role` (`executive` | `supply_chain_director`) on `m_r_f_s`.
- Adds workflow state `parallel_first_approval` (+ `lazarus_director_approval` in Postgres check constraint).

### MRF submit / resubmit routing
- New MRFs enter `workflow_state = parallel_first_approval` (all contract types).
- `routed_reason`: `parallel_first_approval` or `parallel_first_approval_custom`.

### Approval endpoints (race-safe)
| Method | Path | Behavior |
|--------|------|----------|
| `POST` | `/api/mrfs/{id}/executive-approve` | Accepts `parallel_first_approval` or legacy `executive_review`. `DB::transaction` + `lockForUpdate`. Second approver gets **422** `This MRF has already been approved.` |
| `POST` | `/api/mrfs/{id}/supply-chain-director-approve` | Accepts `parallel_first_approval` or legacy `supply_chain_director_review`. Same lock + duplicate message. |

### API fields (list + detail)
- `firstApprovalByRole` / `first_approval_by_role`
- `firstApprovalStatusLabel` / `first_approval_status_label` — `"Approved by Executive"` or `"Approved by Supply Chain Director"`

### Notifications
- `notifyMRFSubmitted` notifies **both** Executive and Supply Chain Director roles (deduped by user id).

### Permissions
- `PermissionService::canApproveMRF` — Executive **or** SCD may approve when `workflow_state === parallel_first_approval`.

### Frontend (emerald-supply-chain)
- **Executive Dashboard** — pending filter includes `parallel_first_approval`; approval actions no longer Emerald-only.
- **Supply Chain Dashboard** — `pendingFirstApprovals` includes parallel state (all contract types).
- `workflowStageLabels.ts` — label for `parallel_first_approval`.

**Note:** In-flight MRFs already in `executive_review` or `supply_chain_director_review` keep legacy sequential behavior until resubmitted.

---

## SCM Platform — Executive & SCD Dashboard Reorganisation (Platform Plan §12, Jul 2026)

No backend API changes.

### Shared utilities
- `src/utils/mrfDashboardBuckets.ts` — classifies MRFs into `pending` | `approved` | `rejected` | `completed` for Executive and Supply Chain Director views; pending sorted oldest-first.
- `src/components/dashboard/DashboardSummaryStats.tsx` — four summary stat cards at dashboard top.
- `src/components/dashboard/DashboardMrfHistoryList.tsx` — compact list for approved / rejected / completed tabs (approval date, rejection reason).

### Executive Dashboard
- Summary row: Pending, Approved, Rejected, Recently Completed (+ vendor registration count in pending).
- Tabs: **Pending** | **Approved** | **Rejected** | **Completed** (replaces undifferentiated “All Requests” list).
- Pending tab: actionable MRF cards + vendor registrations, oldest submissions first.

### Supply Chain Director Dashboard
- Summary row at top (includes SRF + vendor registration counts in pending).
- Tabs: **Pending** | **Approved** | **Rejected** | **Completed** (replaces Action Items / All Requests).
- Pending tab: retained action sections (first approval, vendor selection, PO signature, SRF director review) with breakdown stat cards.

---

## SCM Platform — User Management Department Dropdown (Platform Plan §13, Jul 2026)

### Migration `2026_07_02_190000_normalize_user_departments_to_standard_list`
One-time normalization of `users.department` and `employees.department` to the fixed list below. Legacy mapping (documented in migration):

| Legacy | Standard |
|--------|----------|
| Information Technology, ICT | IT |
| Human Resources, HR | Human Resources |
| Finance, FIN | Finance |
| Operations, OPS, Administration | Operations |
| Logistics, LOG, Supply Chain, SC | Supply Chain |
| Engineering, Technical Operations | Technical Operations |
| Marketing, Legal | Business Development |
| Procurement, PRC | Procurement |
| Executive, EXE | Executive |
| Unmapped values | Operations (default) |

Also replaces `department_codes` rows with the nine canonical departments + codes (`BD`, `OPS`, `FIN`, `IT`, `HR`, `PRC`, `EXE`, `SC`, `TEO`).

### `GET /api/users/department-options`
Returns the allowed department labels (admin / user-management permission required).

### `POST /api/users` / `PUT /api/users/{id}`
- `department` validated with `Rule::in(...)` against the fixed list (nullable).

### `App\Support\DepartmentMatcher`
- `STANDARD_USER_DEPARTMENTS` — canonical nine labels.
- `normalizeToStandardDepartment()` — legacy alias resolution.
- `storageLabel()` prefers standard names when aliases match.

### `GET /api/departments/requisition-creators`
Department list is now the fixed nine options (not a union of ad-hoc user spellings).

### Frontend (emerald-supply-chain)
- `src/constants/scmDepartments.ts` — same nine options + `normalizeScmUserDepartment()` for edit form prefill.
- `UserManagement.tsx` — department **Select** dropdown on create and edit (replaces free-text `Input`).

---

## SCM Platform — MRF/SRF Multi-Document Attachments & Backend Performance (Jul 2026)

### Backend attachment API
- New polymorphic `attachments` table stores MRF/SRF supporting documents (`attachable_type`, `attachable_id`, `collection`, `disk`, `file_path`, `original_name`, `mime_type`, `size`, `uploaded_by`).
- `POST /api/mrfs`, `PUT /api/mrfs/{id}`, `POST /api/srfs`, and `PUT /api/srfs/{id}` accept multiple files using `attachments[]` or `documents[]`. Legacy single-file names `attachment` and `invoice` are also accepted.
- Allowed file types: `pdf`, `xls`, `xlsx`, `csv`, `png`, `jpg`, `jpeg`, `webp`. Max size: 10 MB per file.
- Detail/create/update responses include both `attachments` and `documents` arrays. MRF legacy fields (`attachmentUrl`, `attachmentShareUrl`, `attachmentName`) still point to the first uploaded document for old clients.

### Attachment response item
```json
{
  "id": 12,
  "collection": "supporting_documents",
  "fileName": "vendor-quote.pdf",
  "mimeType": "application/pdf",
  "sizeBytes": 384221,
  "downloadUrl": "https://...",
  "uploadedBy": { "id": 4, "name": "Amina Bello", "email": "amina@example.com" },
  "createdAt": "2026-07-03T17:48:00+01:00"
}
```

### Frontend (emerald-supply-chain)
- Replace MRF/SRF single-file inputs with a reusable multi-file dropzone. Keep selected `File[]` in form state and show removable chips/cards before submit.
- For multipart submit, append each file separately:
```ts
files.forEach((file) => formData.append("attachments[]", file));
```
- Continue JSON-stringifying `items` / `line_items` arrays when sending multipart form data.
- Detail pages should render `response.attachments ?? response.documents ?? []` as a document list/grid with file icon, name, size, uploaded timestamp, and `downloadUrl` link.
- After successful create/update, render the returned attachment array immediately and invalidate the relevant MRF/SRF detail + list query keys.

### Backend performance
- `GET /api/srfs` now uses strict server-side pagination, narrow requester eager-loads, and explicit list columns by default. Rich line-item rows remain opt-in via `include_line_items=1`.
- Dashboard aggregate stats are cached for short intervals and invalidated when MRF/SRF models change.
- Added guarded indexes for MRF/SRF status/stage/requester/date sorts plus attachment and line-item lookup paths.


---

## SCM Platform — PO Module Forensic Fix (Jul 2026)

### Root causes addressed
1. **Draft save intermittent 500** — `$rfq` was referenced on the draft path before it was loaded when `allow_missing_rfq=true`.
2. **Draft fields missing on reopen** — `GET /api/mrfs/{id}` omitted `ship_to_address`, `tax_rate`, `delivery_date` / `expected_delivery_date`, `payment_terms` / `po_payment_terms`, `po_type`, and hydrate never restored them.
3. **Edit PO slow** — full `show()` loaded 6 relations + attachments + vendor invoice for form hydrate.
4. **Generate timeouts** — sync PDF already queued; failures were silent (no `po_generation_error`).
5. **PO list / Finance AP** — leading-wildcard search; remaining-balance filtered after pagination.

### Backend API changes

#### `POST /api/mrfs/{id}/generate-po` with `save_as_draft: true`
- Loose validation only (no finalise required-field checks).
- Persists: `ship_to_address`, `tax_rate`, `tax_amount`, `custom_terms`, `po_terms_mode`, `po_type`, `po_payment_terms`, `invoice_*`, `remarks`, `expected_delivery_date`, `currency`.
- Response `data.mrf` includes full PO form fields + `payment_milestones` + `timing_ms`.
- Logs: `PO draft save started|completed` with `elapsed_ms`, `db_write_ms`, `payload_keys`.

#### `POST /api/mrfs/{id}/generate-po` (finalise)
- Auto-generation returns **202** `{ processing: true }` and queues `ProcessPurchaseOrderGenerationJob`.
- On queue failure writes `po_generation_error` / `po_generation_failed_at` (surfaced on list/detail).
- Step logs: validation, PDF start/end, DB write, queue dispatch.

#### `GET /api/mrfs/{id}?for_po=1` (NEW query flag)
- Lightweight hydrate for Create/Edit PO: only `priceComparisons` + payment milestones + `poFormApiFields()`.
- Use this instead of the full MRF detail when opening the PO form.

#### `GET /api/mrfs?po_list=1`
- Search uses **prefix** `LIKE 'term%'` on `mrf_id` / `formatted_id` / `po_number` (index-friendly); requester name still allows contains for ≥3 chars.
- Logs `PO list query completed` with `elapsed_ms` + `row_count`.

#### Dashboards
- `po_summary` / `poSummary` cached counts (`total`, `draft`, `pending`, `signed`, `rejected`, `completed`) on:
  - `GET /api/dashboard/procurement-manager`
  - `GET /api/dashboard/supply-chain-director`
  - `GET /api/dashboard/executive`

#### Finance AP open POs
- `remainingBalance > 0` exclusion of fully-paid schedules moved **before** pagination.
- `listForVendor($id, $page = 1, $perPage = 25)` — no hard 100-only path.

#### Migration
- `2026_07_13_150000_add_po_draft_and_generation_columns.php` — `po_type`, `po_payment_terms`, `po_generation_error`, `po_generation_failed_at`, partial index `mrfs_po_draft_active_idx`.

### Frontend (emerald-supply-chain)
- `procurementApi.getMRFForPO` → `GET /mrfs/{id}?for_po=1`
- `CreatePOForm` hydrate restores delivery date, payment terms, po_type, ship-to, tax, milestones; always sends `delivery_date` + `payment_terms` on save.
- Manual save waits for autosave (`isAutoSavingRef`) before acquiring lock.
- Empty payment milestones treated as valid (optional).
- Download prefers stored S3/HTTP URL before client jsPDF rebuild.
- `pollForGeneratedPO` surfaces `po_generation_error` as a hard failure toast.

---

## SCM Platform — Emerald PO Template + Procurement CC (Jul 2026)

### Download / generation
- Unsigned PO downloads always use the **Emerald** layout (frontend jsPDF first; API regenerates Emerald Dompdf and refreshes S3).
- Signed POs still open the stored signed PDF (signature must be preserved).
- Response header `X-PO-Layout: emerald` on API unsigned download.

### Invoice CC
- Default invoice CC is now `lateef.olanrewaju@emeraldcfze.com, procurement@emeraldcfze.com`.
- Backend always merges `procurement@emeraldcfze.com` into `invoice_submission_cc` on draft save / generate.
- PO-generated notification recipients always include `procurement@emeraldcfze.com`.
- Config keys: `scm.invoice_submission_cc`, `scm.po_cc_recipients`.

### Performance (from Render logs)
- `DashboardStatsCache::poSummaryCounts()` is a **single** aggregate query (not 6 COUNTs) + request memoisation.
- `TableColumnCache` memoises within the request to avoid repeated DB-cache round-trips (~850ms each on Render).
- Price comparison bulk save batch-loads vendors + bulk `insert` (was N+1 `create` per row).

### Performance root cause — DB-backed cache tax (Jul 13 2026)
Render logs show **every** query (even `count(*)` on `notifications`) taking ~850–900ms — a per-query
DB latency floor. With `CACHE_STORE=database`, every cache read/write is itself an ~850ms query, so
caching was *adding* latency. Fixes:
- New `App\Support\FastCache`: routes hot, short-TTL, per-instance-safe caches (schema columns, list
  counts, dashboard stats, notification unread count) to the local **file** store instead of the slow
  DB cache store. Auto-activates whenever `cache.default = database`; override with `CACHE_FAST_STORE`.
- `GET /api/notifications` and `GET /api/notifications/unread-count`: `unread_count` is now served from a
  15s FastCache entry (invalidated on mark-read / mark-all-read / delete). This drops the endpoint from
  3 remote queries (~2.6s) to 2. Response contract is unchanged; the badge count may lag up to 15s.

### REQUIRED Render env changes (infra — biggest win, not code)
The ~850ms/query floor is the dominant cost of every slow screen. Set on the Render web service:
- `CACHE_STORE=file` (or `redis` if a Redis instance is added) — stops cache ops hitting the DB.
- `SESSION_DRIVER=file` (or `cookie`) — stops per-request session read/write DB queries.
- `QUEUE_CONNECTION=redis`/`database` **with a running worker** (`php artisan queue:work`) for true
  background PDF + email. `afterResponse()` already defers work off the client response, but a real
  worker guarantees it. If the DB itself is the slow tier, upgrade the DB plan / co-locate the DB in the
  same region as the web service — no code change removes a 850ms network floor.

---

## SCM Platform — Procurement Performance & Storage Audit Fix (Jul 13 2026)

### Backend (this deploy)

| Area | Fix |
|------|-----|
| **Procurement dashboard** | Pending MRF/SRF/registration/quotation **lists** cached 60s via FastCache (stats already 5m). SRF pending status uses case-insensitive match. |
| **MRF detail** | Always mints **fresh** unsigned/signed PO links (stream URL preferred). Opt-in `?include_documents=1` and existing `?include_pnl=1` to avoid extra nested round-trips. Duplicate `items`/`line_items` mapping removed. |
| **Procurement documents** | `GET /mrfs/{id}/procurement-documents` (and any `transform()`) **re-signs S3 URLs from `file_path` on every request** — never return DB-cached pre-signed URLs (fixes `AccessDenied` / `Request has expired`). |
| **Reports** | `ReportCache` now uses **FastCache** (not DB cache store). |
| **PO list counts** | `ListCountCache` scope `mrf_po` is bumped on MRF save/delete (was stale forever). PO/SRF search uses **prefix** on identifiers. |
| **Emerald PO PDF** | Generation job stores filenames with `_emerald_`; download serves Emerald only (`X-PO-Layout: emerald`). |

### Frontend REQUIRED changes (emerald-supply-chain)

1. **PO download — kill green/jsPDF path**  
   Never open raw S3/`unsigned_po_url` from a stale API cache and **never** rebuild with client jsPDF. Always:
   - `GET /api/mrfs/{id}/download-po` (auth), or  
   - Follow `unsignedPoUrl` only when it is the **Laravel signed stream** URL returned by a **fresh** MRF/PO detail fetch.  
   Expect header `X-PO-Layout: emerald`.

2. **Procurement documents**  
   On open/download, use `fileUrl` / `file_url` from a **just-fetched** `GET /mrfs/{id}/procurement-documents` (or MRF show with `include_documents=1`). Do not persist document links in React state across sessions/tabs.

3. **MRF details modal**  
   Prefer **one** hydrate when possible:  
   `GET /mrfs/{id}?include_pnl=1&include_documents=1`  
   then reuse `profitAndLoss` + `procurementDocuments` instead of mounting panels that each hit `/line-item-pnl` and `/procurement-documents` independently after a blank 25s wait. Keep `full-details` for quotation timeline only when the user opens that section.

4. **Edit / Create PO**  
   - Hydrate with `GET /mrfs/{id}?for_po=1` or `GET /pos/{id}` — **not** full MRF show.  
   - Vendor/MRF selects: **async search only** — `GET /vendors?dropdown=1&search=…`, `GET /mrfs?dropdown=1&search=…`. Never `getAll()` vendors/MRFs on modal mount.

5. **Dashboard / Reports**  
   Do not block first paint on non-critical widgets; stats/lists are now server-cached, but still show shell UI immediately. Reports re-hit cache after date-range change; avoid refetch spam.

6. **Service Requests table**  
   Keep `GET /srfs` without `include_line_items` (default). Paginate; do not client-accumulate all pages for the table view.

---

## PO Document Upload & Visibility — Multi-File Support (Jul 20 2026)

### Problem
- Single-file uploads only; procurement teams need to batch-upload multiple documents (GRN, waybill, JCC, PFI, delivery confirmation, etc.) in one request.
- Document visibility was limited to requester and procurement; Supply Chain Director, Executive, and Logistics Manager roles could not view PO documents.
- Fast-track PO creation (manual, without RFQ) had no support for attaching documents on creation/update.

### Backend changes

#### Role / Permission expansion
- `PermissionService::canViewDocument()` now grants full document visibility to:
  - `supply_chain_director`, `supply_chain`
  - `executive`, `director`
  - `logistics_manager`, `logistics`
  - `finance`, `finance_officer`
  - `procurement_manager`, `procurement`, `procurement_officer`
  - `admin`
  
  Plus: Logistics Manager (read-only overview role) sees read-only document types per `ProcurementOverviewAccess::readOnlyDocumentTypes()`.

- `ProcurementOverviewAccess` role arrays expanded:
  - `DELIVERY_DOCUMENT_ROLES`: Added `procurement_officer`, `logistics_officer` support for GRN/waybill/JCC uploads.
  - `MANAGEMENT_ROLES`: Added `procurement_officer` for fast-track PO access.

#### Multi-file document upload endpoint (MODIFIED)

**`POST /api/mrfs/{id}/procurement-documents`** now accepts **two formats**:

**Single file (backward compatible):**
```json
{
  "type": "grn",
  "file": (multipart file),
  "remarks": "optional notes"
}
```

**Multiple files (NEW):**
```json
{
  "documents": [
    {
      "type": "grn",
      "file": (multipart file),
      "remarks": "optional notes"
    },
    {
      "type": "waybill",
      "file": (multipart file),
      "remarks": "optional notes"
    }
  ]
}
```

**Response (NEW):**
```json
{
  "success": true,
  "message": "Document uploaded successfully",
  "data": {
    "documents": [
      {
        "id": 123,
        "type": "grn",
        "fileName": "grn_2026.pdf",
        "fileUrl": "https://s3.../procurement-documents/.../grn_2026.pdf?X-Amz-Signature=...",
        "uploadedAt": "2026-07-20T10:30:00Z",
        "uploadedBy": { "id": 42, "name": "Jane Doe" }
      }
    ],
    "failed": [
      {
        "index": 1,
        "type": "jcc",
        "fileName": "jcc_doc.pdf",
        "status": "failed",
        "error": "You do not have permission to upload this document type at the current workflow stage."
      }
    ]
  }
}
```

**Error case (all uploads failed):**
```json
{
  "success": false,
  "message": "Some documents uploaded successfully",
  "error": "All document uploads failed",
  "code": "UPLOAD_FAILED",
  "data": {
    "documents": [],
    "failed": [...]
  }
}
```
Returns **422** if all documents fail, **200/201** if at least one succeeds.

#### Document list / retrieval endpoint (MODIFIED)

**`GET /api/mrfs/{id}/procurement-documents`** now **enforces view permissions per document**:
- Only documents the user is authorized to view are returned (filtered by `PermissionService::canViewDocument()`).
- Each document in `documentsByType` and `activeByType` is permission-gated at retrieval time (not just at creation).
- Fresh S3 pre-signed URLs are generated on every request (unchanged behavior; prevents `AccessDenied`).

#### Fast-track PO creation / update with documents (NEW)

**`POST /api/pos`** (Create PO) now accepts optional `documents[]`:

```json
{
  "title": "Office Supplies",
  "category": "Supplies",
  "estimatedCost": 50000,
  "currency": "NGN",
  "items": [
    { "itemName": "Chairs", "quantity": 10, "unit": "pcs", "budgetAmount": 30000 }
  ],
  "documents": [
    {
      "type": "pfi",
      "file": (multipart file),
      "remarks": "PFI from vendor"
    }
  ]
}
```

**`PUT /api/pos/{id}`** (Update PO) accepts documents the same way.

**Response:** Same as PO create/update; documents are stored as attachments to the MRF and do not block the response. Failed document uploads are logged but do not fail the PO creation.

#### Validation rules (UPDATED)

- `StorePurchaseOrderRequest::rules()` now accepts:
  - `documents`: nullable array
  - `documents.*.file`: file, mimes: pdf,jpg,jpeg,png,doc,docx, max 20MB
  - `documents.*.type`: nullable string, max 50 chars
  - `documents.*.remarks`: nullable string, max 2000 chars

- `UpdatePurchaseOrderRequest::rules()`: Same document validation as create.

### Frontend implementation checklist

- [ ] **Multi-file upload UI**: On PO/document upload modal, allow selecting multiple files with per-file type/remarks fields.
- [ ] **Document list** (`GET /api/mrfs/{id}/procurement-documents`):
  - Always re-fetch before displaying documents (do not cache in React state across page load/tab switch).
  - Handle the new `failed[]` array in response (show upload errors to user).
  - Display fresh `fileUrl` on every download/preview (do not persist S3 URLs).
- [ ] **PO creation form**: Add optional **Documents** section below items. On submit, build `documents[]` array from file inputs and pass to `POST /api/pos`.
- [ ] **Dashboard / PO detail view**: Grant document visibility to users with roles: `supply_chain_director`, `supply_chain`, `executive`, `director`, `logistics_manager`, `logistics`. Show "Documents" panel when user is authorized.
- [ ] **Permission checks**: Replace single-role view logic with multi-role list (Logistics Manager can now see PO documents in overview).

### Migration / deployment

**No database migrations required.** Permission changes are backend-only; table schemas unchanged.

**Backward compatibility:** Single-file `POST /api/mrfs/{id}/procurement-documents` continues to work; multi-file is opt-in.

**Testing:**
- Upload multiple files to a PO; verify all or partial success in response.
- Verify unauthorized upload returns 403 with clear error message.
- Verify Logistics Manager sees documents in overview (read-only).
- Verify fresh S3 URLs on every document list fetch.

