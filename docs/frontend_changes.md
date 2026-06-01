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

## Phase 4 — Vendor invoice portal

Vendor-authenticated endpoints for final invoice upload on Finance AP MRFs. Gate enforcement is server-side via `VendorInvoiceGateService`.

### Auth

Use vendor portal login (`POST /api/vendors/auth/login`) and Bearer token on all routes below. Vendor user must be linked to the MRF's `selected_vendor_id`.

### `GET /api/vendor-portal/mrfs`

Lists MRFs where the authenticated vendor is the selected vendor.

**Response `data.mrfs[]`:** `mrfId`, `title`, `workflowState`, `vendorInvoiceGate`, `invoiceSubmitted`.

Alias: `GET /api/vendors/portal/mrfs`

---

### `GET /api/vendor-portal/mrfs/{mrfId}/invoice`

Invoice submission status for one MRF.

| Field | Description |
|-------|-------------|
| `canSubmit` | Gate open and no active invoice yet |
| `submitted` | Active `vendor_invoice` exists |
| `document` | Registry document when submitted |
| `gateType` | `advance` or `delivery` |

Alias: `GET /api/vendors/portal/mrfs/{mrfId}/invoice`

---

### `POST /api/vendor-portal/mrfs/{mrfId}/invoice`

Upload final vendor invoice (multipart).

**Body:** `invoice` — required file (`pdf`, `jpg`, `jpeg`, `png`; max 10MB)

**Success:** `201` with registry `document` row; syncs legacy `invoice_url` on MRF.

**Errors:**

| Code | HTTP | When |
|------|------|------|
| `INVOICE_GATE_CLOSED` | 422 | Gate not open (advance/delivery rules) |
| `INVOICE_ALREADY_SUBMITTED` | 422 | Active invoice already exists |
| `FORBIDDEN` | 403 | Not the selected vendor |
| `VALIDATION_ERROR` | 422 | Invalid/missing file |

Alias: `POST /api/vendors/portal/mrfs/{mrfId}/invoice`

**Frontend:**

- Show Upload Invoice only when `canSubmit === true`.
- After submit, read-only UI (`submitted === true`); no resubmit.
- Internal MRF detail: `vendor_invoice` appears in `GET /api/mrfs/{id}/procurement-documents` (`activeByType.vendor_invoice`).

---

### Notifications

**On SCD vendor quote approval** (Finance AP MRFs only):

- Email: `VendorQuoteApprovedMail` — no approver names/roles; explains when invoice upload opens.
- In-app: `VendorQuoteApprovedNotification` on vendor portal user.

**On vendor invoice submit:**

- In-app to Procurement, SCD, Executive, Finance (monitor), Admin.

**Delta sync:** If Finance AP package was already pushed (`finance_ap_case_id` set, Phase 6), backend calls `FinanceIntegrationService::pushDelta(..., 'vendor_invoice_submitted')` automatically.

---

## Phase 5 — Delivery confirmation UI (backend)

Procurement Manager panel on MRF/PO detail for Finance AP MRFs with delivery-based payment milestones. Advance-only schedules (e.g. `100_advance`) skip this panel.

### `GET /api/mrfs/{id}/delivery-confirmation`

Primary endpoint for the Delivery Confirmation panel. Combines gate status, milestone context, document checklist, and permission flags.

**Response `data` (key fields):**

| Field | Description |
|-------|-------------|
| `showPanel` | Render the panel when `true` |
| `required` / `satisfied` | Delivery gate status |
| `currentMilestone` | Pending milestone driving the checklist |
| `checklist[]` | Per-document rows (see below) |
| `missingDocuments` / `uploadedDocuments` | Aggregate doc types |
| `permissions` | Role + stage flags for upload/generate actions |
| `refreshHint` | Poll this endpoint (or `workflow-gates`) after uploads |

**Checklist item shape:**

```json
{
  "type": "grn",
  "label": "Goods Received Note (GRN)",
  "required": true,
  "satisfied": false,
  "document": null,
  "actions": ["generate_grn", "upload_grn"]
}
```

| `type` | Suggested frontend action |
|--------|---------------------------|
| `grn` | Preview/generate via GRN endpoints **or** upload via procurement-documents |
| `waybill` | `POST /api/mrfs/{id}/procurement-documents` with `type=waybill` |
| `jcc` | `type=jcc` |
| `delivery_confirmation` | `type=delivery_confirmation` |
| `other` | `type=other` |

**Permissions object:**

| Flag | Meaning |
|------|---------|
| `canManageDeliveryConfirmation` | PM/admin can act on the panel |
| `canGenerateGRN` / `canUploadGRN` | GRN actions |
| `canUploadWaybill` | Waybill upload |
| `canUploadJcc` | JCC upload (service/completion milestones) |
| `canUploadDeliveryConfirmation` | Delivery confirmation proof upload |

Also exposed on `GET /api/mrfs/{id}/available-actions`:

- `showDeliveryConfirmationPanel`
- `canManageDeliveryConfirmation`
- `canUploadWaybill`, `canUploadJcc`, `canUploadDeliveryConfirmation`
- Action keys: `view_delivery_confirmation`, `manage_delivery_confirmation`, `upload_waybill`, etc.

---

### Related endpoints (panel actions)

| Action | Endpoint |
|--------|----------|
| Preview GRN PDF | `GET/POST /api/mrfs/{id}/grn/preview` |
| Generate + register GRN | `POST /api/mrfs/{id}/grn/generate` |
| Upload waybill/JCC/delivery docs | `POST /api/mrfs/{id}/procurement-documents` |
| List all docs | `GET /api/mrfs/{id}/procurement-documents` |
| Gate status (poll) | `GET /api/mrfs/{id}/workflow-gates` (includes `deliveryConfirmation.checklist`) |
| Progress tracker step | `GET /api/mrfs/{id}/progress-tracker` — step 8 when delivery stage applies |

---

### Frontend implementation guide

1. **Show panel** when `available-actions.showDeliveryConfirmationPanel === true` (or `delivery-confirmation.showPanel`).
2. **Render checklist** from `checklist[]` — tick satisfied rows, show upload/generate buttons from `actions` when `permissions` allow.
3. **After any upload or GRN generate**, poll `GET /delivery-confirmation` or `GET /workflow-gates` until `satisfied === true` (auto-advances workflow to `finance_handoff_pending`).
4. **Tracker:** step 8 “Delivery Confirmation” appears only for non-advance schedules (see progress-tracker response).
5. **Read-only mode:** when `satisfied === true` or workflow moves past delivery confirmation, show checklist as completed reference.

---

## Phase 6 — Finance AP integration (SCM backend)

Cross-system contract: `docs/FINANCE_AP_SIDE_SCM_INTEGRATION.md`.

| | Production URL |
|---|----------------|
| SCM pushes to Finance AP | `https://financeap-backend.onrender.com` |
| Finance AP webhooks to SCM | `POST /api/webhooks/finance-ap` |
| Finance AP document refresh | `GET /api/v1/integrations/scm/documents/{scm_transaction_id}/{document_id}` (machine auth) |

### MRF fields (Finance AP routing)

| Field | Description |
|-------|-------------|
| `finance_ap_case_id` | Set after successful package push |
| `finance_ap_status` | Mirror of Finance AP case status (`pending_review`, `in_review`, `rejected`, `rfi`, `closed`, …) |

### `GET /api/mrfs/{id}/finance-sync`

Auth: Sanctum (same as other MRF detail endpoints).

**Response `data`:**

| Field | Description |
|-------|-------------|
| `usesFinanceAp` | Cutover routing |
| `financeApCaseId` | Finance AP case id when pushed |
| `financeApStatus` | Last known FA status |
| `packagePushed` | `true` when `financeApCaseId` is set |
| `integrationConfigured` | SCM has `FINANCE_AP_BASE_URL` + `FINANCE_AP_API_KEY` |
| `financeApBaseUrl` | Configured FA base URL |
| `lastOutbound` / `lastInbound` | Latest sync event summary |
| `recentEvents` | Last 20 rows from `finance_sync_events` |

**UI:** Finance monitor dashboard — show sync status, last event, link to Finance AP case when available. **Hide** “Process Payment” / chairman payment actions when `usesFinanceAp === true` (API returns `422` `FINANCE_AP_ROUTED` on those endpoints).

### Internal payment endpoints (post-cutover MRFs)

| Endpoint | When blocked |
|----------|----------------|
| `POST /api/mrfs/{id}/process-payment` | `mrfUsesFinanceAp(mrf)` |
| `POST /api/mrfs/{id}/approve-payment` | `mrfUsesFinanceAp(mrf)` |

Error: `422`, `code`: `FINANCE_AP_ROUTED`.

### Automatic package push

When workflow reaches `finance_handoff_pending`, SCM calls Finance AP `POST /api/v1/integrations/scm/packages` (if integration configured and not already pushed). After vendor invoice post-push, delta sync runs automatically (`vendor_invoice_submitted`).

**Finance AP (separate repos):** SCM cases ingest API, pull/cache documents, outbound webhooks, **SCM Cases** UI in `EmeraldFinanceAP`.

---

## Phase 7+ (pending)

Document cutover routing and dashboard/reporting endpoints here as they are implemented.
