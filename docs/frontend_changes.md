# Frontend Changes — Finance AP Implementation

Living document for API changes requiring frontend updates. See `FINANCE_AP_IMPLEMENTATION_PLAN.md`.

---

## Phase 0 — Pre-implementation foundations

### MRF responses now include `scmTransactionId`

**Affected endpoints (additive fields only):**

- `GET /api/mrfs`
- `GET /api/mrfs/{id}`
- `GET /api/mrfs/{id}/full-details`
- `GET /api/mrfs/{id}/progress-tracker`
- `POST /api/mrfs` (create response)

**New fields:**

```json
{
  "scmTransactionId": "550e8400-e29b-41d4-a716-446655440000",
  "scm_transaction_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Frontend action:** Add to TypeScript MRF types. Display only if needed for admin/debug; primary use is future Finance AP correlation (do not use for routing logic in UI).

---

### `GET /api/mrfs/{id}/procurement-documents`

**Query parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `type` | string optional | Filter: `vendor_invoice`, `grn`, `waybill`, `jcc`, `pfi`, `po_pdf`, `signed_po`, `delivery_confirmation`, `other` |
| `include_inactive` | boolean optional | Default false; set true to include superseded versions |

**Response:**

```json
{
  "success": true,
  "data": {
    "mrfId": "MRF-EMERALD-2026-001",
    "scmTransactionId": "uuid",
    "documents": [
      {
        "id": 1,
        "mrfId": 12,
        "vendorId": 3,
        "type": "signed_po",
        "fileName": "po_signed.pdf",
        "filePath": "procurement-documents/2026/05/...",
        "fileUrl": "https://...",
        "uploadedBy": { "id": 1, "name": "Jane Doe" },
        "uploadedAt": "2026-05-31T12:00:00+00:00",
        "version": 1,
        "isActive": true
      }
    ]
  }
}
```

**Frontend action:** No UI required in Phase 0 unless an documents panel already exists — prepare types for Phase 2+ document upload flows.

---

## Phase 1 — Payment schedule & milestones

### `GET /api/payment-term-templates`

Lists predefined payment term templates (100% advance, 70/30, 50/50, 30/40/30).

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "key": "70_30_delivery",
      "name": "70% Advance / 30% Upon Delivery",
      "milestones": [
        {
          "milestone_number": 1,
          "label": "Advance",
          "percentage": 70,
          "trigger_condition": "on_advance",
          "required_documents": ["signed_po", "pfi"]
        }
      ]
    }
  ]
}
```

**Frontend action:** Populate template picker on RFQ create/edit and payment schedule UI.

---

### `GET /api/mrfs/{id}/payment-schedule`

Returns the current structured payment schedule for an MRF (404 if none).

**Response fields:** `id`, `templateKey`, `templateName`, `version`, `isLocked`, `lockedAt`, `summary`, `milestones[]` (each with `milestoneNumber`, `label`, `percentage`, `amount`, `triggerCondition`, `triggerLabel`, `requiredDocuments`, `status`).

---

### `POST /api/mrfs/{id}/payment-schedule`

Create schedule from template or custom milestones. Percentages must total 100%.

**Body (template):**

```json
{ "templateKey": "70_30_delivery" }
```

**Body (custom):**

```json
{
  "milestones": [
    { "milestoneNumber": 1, "label": "Advance", "percentage": 60, "triggerCondition": "on_advance" },
    { "milestoneNumber": 2, "label": "Balance", "percentage": 40, "triggerCondition": "upon_delivery" }
  ]
}
```

Returns `201` with full schedule payload. Returns `422` if percentages ≠ 100% or schedule already exists.

---

### `PUT /api/mrfs/{id}/payment-schedule`

Update schedule (versioned audit trail). Returns `409 SCHEDULE_LOCKED` after PO generation.

---

### Propagation (additive fields)

| Endpoint | New fields |
|----------|------------|
| `GET /api/rfqs`, `GET /api/rfqs/{id}`, `POST /api/rfqs` | `paymentSchedule`, `payment_schedule` |
| Vendor RFQ list (`RFQWorkflowController`) | `paymentSchedule`, `payment_schedule` |
| `GET /api/quotations` | `paymentSchedule`, `payment_schedule` (read-only from MRF) |
| `GET /api/mrfs/{id}/price-comparisons` | Top-level `paymentSchedule`; each row has `paymentTerms` / `paymentScheduleSummary` |

**PO PDF:** Auto-generated POs now render a milestone table instead of free-text `payment_terms`. Schedule locks on `POST /api/mrfs/{id}/generate-po`.

**Frontend action:**

- RFQ create/edit: select template or customize milestones via payment-schedule API before/at RFQ send.
- Quotation evaluation / price comparison: show `paymentScheduleSummary` column.
- PO preview: expect milestone table on generated PDFs.

---

## Phase 2 — Document registry completion

### `GET /api/mrfs/{id}/procurement-documents` (updated)

Response now includes grouped views:

```json
{
  "success": true,
  "data": {
    "mrfId": "MRF-EMERALD-2026-001",
    "scmTransactionId": "uuid",
    "documents": [ "...flat list..." ],
    "documentsByType": {
      "grn": [ { "id": 1, "type": "grn", "isActive": true, "...": "..." } ],
      "po_pdf": [ "..."]
    },
    "activeByType": {
      "grn": { "id": 1, "type": "grn", "version": 1, "...": "..." },
      "signed_po": { "...": "..." }
    }
  }
}
```

Query params unchanged: `type`, `include_inactive`.

---

### `POST /api/mrfs/{id}/procurement-documents`

Upload waybill, JCC, PFI, delivery confirmation, GRN (file), or other supporting documents to the registry.

**Body (multipart):**

| Field | Required | Description |
|-------|----------|-------------|
| `type` | yes | `grn`, `waybill`, `jcc`, `pfi`, `delivery_confirmation`, `other` |
| `file` | yes | PDF/DOC/DOCX/JPG/PNG, max 20MB |

Returns `201` with document payload. Procurement roles only; allowed after PO signed (and related delivery/finance states).

---

### `GET /api/mrfs/{id}/grn/preview`

Generates a **preview GRN PDF** from MRF line items (not saved). Returns `application/pdf` inline.

**Also available:** `POST /api/mrfs/{id}/grn/preview` with the same body as generate (use after editing line quantities).

**Optional parameters** (query string for GET, JSON body for POST):

| Field | Description |
|-------|-------------|
| `dateOfReceipt` / `receivedAt` | Date of receipt (default: today) |
| `deliveryNoteNumber` | Delivery note reference (default: N/A) |
| `deliveryDate` | Delivery date |
| `carrierName` | Carrier/driver name (default: N/A) |
| `driverNumber` | Driver contact number (default: N/A) |
| `vehiclePlateNumber` | Vehicle plate (default: N/A) |
| `comments` | Pre-fill comments row (optional) |
| `lineItems[]` | Override `quantityReceived` per row before preview/generate |

**GRN number format (auto on preview):** `GRN-{MRF_ID}-{YYYY}-{sequence}` (e.g. `GRN-MRF-OANDO-PRC-MAIN-2026-029-2026-001`)

**Layout:** Emerald logo top-left, "GOODS RECEIVED NOTE" top-right, delivery/supplier columns, material table with pricing from approved quotation, comments row, 2×2 authorized signatories block.

**Frontend flow:** preview PDF → edit quantities in UI → POST preview again or POST generate with overrides.

---

### `POST /api/mrfs/{id}/grn/generate`

Generates the GRN PDF and **saves to document registry** after PM confirmation.

**Body:**

```json
{
  "confirm": true,
  "dateOfReceipt": "2026-02-02",
  "deliveryNoteNumber": "DN-001",
  "deliveryDate": "2026-04-10",
  "carrierName": "N/A",
  "driverNumber": "N/A",
  "vehiclePlateNumber": "N/A",
  "comments": "",
  "lineItems": [
    { "index": 0, "quantityReceived": 1 }
  ]
}
```

`confirm` must be `true` (default). Returns `201` with `document` and syncs legacy `grn_url` on MRF.

Legacy `POST /api/mrfs/{id}/complete-grn` (file upload) also writes to registry.

---

### PO document registry (automatic)

On PO generation and signing, the backend now registers:

| Event | Registry type |
|-------|----------------|
| `POST /api/mrfs/{id}/generate-po` | `po_pdf` |
| `POST /api/mrfs/{id}/upload-signed-po` or `POST /api/purchase-orders/{po}/sign` | `signed_po` |

No frontend change required — documents appear in `GET /api/mrfs/{id}/procurement-documents`.

---

### Permission flags (MRF actions API)

New/updated action flags for procurement users:

- `canGenerateGRN` — preview/generate GRN from line items
- `canUploadGRN` — upload GRN file (legacy + registry)

GRN operations are allowed after PO signed (not only after legacy post-payment GRN request).

**Frontend action:**

- Delivery confirmation UI: preview GRN → confirm generate; upload waybill/JCC/delivery docs via procurement-documents POST.
- Documents panel: group by `documentsByType` / show `activeByType` badges.

---

## Phase 3 — Workflow gates (invoice timing, delivery confirmation, closure)

Phase 3 adds backend workflow gates for Finance AP MRFs (`mrfUsesFinanceAp()`). Legacy MRFs keep existing behaviour.

### `GET /api/mrfs/{id}/workflow-gates`

Single endpoint for gate status used by internal UI and (Phase 4) vendor portal.

**Response `data`:**

| Field | Description |
|-------|-------------|
| `usesFinanceAp` | Whether Finance AP rules apply |
| `workflowState` | Current canonical workflow state |
| `vendorInvoiceGate.canSubmit` | Vendor may submit invoice (Phase 4 enforces on upload) |
| `vendorInvoiceGate.gateType` | `advance` or `delivery` when applicable |
| `vendorInvoiceGate.reason` | Human-readable gate explanation |
| `deliveryConfirmation.required` | Whether delivery confirmation stage applies |
| `deliveryConfirmation.satisfied` | All required delivery docs present |
| `deliveryConfirmation.requiredDocuments` | Aggregated doc types from milestones |
| `deliveryConfirmation.missingDocuments` | Still required |
| `closureReadiness.canClose` | MRF may transition to `closed` |
| `closureReadiness.blockers` | Reasons closure is blocked |
| `closureReadiness.milestoneSummary` | Per-milestone financial/doc status |

**Vendor invoice gate rules:**

| Payment structure | Gate opens when |
|-------------------|-----------------|
| 100% advance or any advance milestone | After SCD vendor quote approval (`invoice_approved` or later) |
| Standard / split / delivery-based | After GRN received and confirmed |

**Post-PO routing (automatic on signed PO):**

| Schedule | Next workflow state |
|----------|---------------------|
| Advance-only (e.g. `100_advance`) | `finance_handoff_pending` (skips delivery confirmation step in tracker) |
| Delivery-based milestones | `delivery_confirmation_pending` → auto-advances when GRN/waybill docs satisfied |

Uploading or generating GRN, waybill, JCC, or delivery confirmation documents triggers auto-evaluation of delivery confirmation and intermediate completion states.

**Closure:** `WorkflowStateService` rejects transition to `closed` unless `closureReadiness.canClose` is true (all milestones paid + required docs present).

---

### Progress tracker (`GET /api/mrfs/{id}/progress-tracker`)

For Finance AP MRFs, steps after PO generation:

| Step | Name | Notes |
|------|------|-------|
| 7 | Purchase Order Signed | Replaces legacy "Process Complete" |
| 8 | Delivery Confirmation | **Omitted** for advance-only schedules (e.g. 100% advance) |
| 8 or 9 | Finance Handoff | Pending from `finance_handoff_pending` through milestone payments |

Delivery Confirmation step includes `requiredDocuments` when present.

**SCD quote approval response:** `POST` vendor selection approval responses now include `vendorInvoiceGateOpen` (boolean) for Finance AP MRFs.

**Frontend actions:**

- Poll or refresh `GET /api/mrfs/{id}/workflow-gates` on MRF detail after PO sign, GRN generate/upload, and document uploads.
- Show delivery confirmation checklist from `deliveryConfirmation.missingDocuments`.
- Disable close/archive actions when `closureReadiness.canClose` is false; display `blockers`.
- Vendor invoice upload UI (Phase 4): show only when `vendorInvoiceGate.canSubmit` is true.

---

## Phase 4+ (pending)

Document vendor invoice portal and Finance sync endpoints here as they are implemented.
