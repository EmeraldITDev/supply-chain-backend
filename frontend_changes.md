# Frontend Changes — SCM Platform Feature Enhancements

**Backend repo:** `supply-chain-backend`  
**Auth:** Bearer token (`Authorization: Bearer {token}`) on all routes below except public health checks.  
**Base URL:** `/api`

This document is the source of truth for the React/Lovable frontend. **Before wiring any action, check whether a button/page already exists; if not, add UI for every endpoint listed.**

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

### POST `/api/mrfs` & POST `/api/srfs` (MODIFIED)
Include `items[]` with **`budgetAmount`** per line (Feature 9 baseline).

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

Same `data` shape as `profitAndLoss` above.

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
  "scheduled_departure_at": "2026-06-01T08:00:00Z",
  "scheduled_arrival_at": "2026-06-01T18:00:00Z",
  "passenger_user_ids": [2, 5, 8],
  "driver_user_id": 12
}
```

**Response:** `{ "success": true, "data": { "trip": { ... } } }` with `workflow_stage: "trip_request"`.

**Frontend:** **Trip Request** form (new page if missing): destination, purpose, date/time, passenger multi-select, optional driver.

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

## Migration Required (DevOps)

```bash
php artisan migrate
```

Migration: `2026_05_20_160000_scm_platform_feature_enhancements.php`  
(Also run `2026_05_19_000001_contract_type_free_text.php` if not applied.)

---

## UI Checklist (must be reachable)

- [ ] MRF contract type combobox + custom text
- [ ] MRF/SRF line items with budget column
- [ ] MRF/SRF detail P&L table
- [ ] Procurement reports page + export
- [ ] Dashboard KPI tiles (4 counters)
- [ ] Trip request create form
- [ ] Trip workflow action buttons per stage
- [ ] Passenger picker → `eligible-passengers`
- [ ] Optional driver on trip forms
- [ ] Vehicle edit button
- [ ] Driver delete + assign notify
- [ ] Driver form: phone required, email optional
