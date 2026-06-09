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

## Phase 7 — Finance routing by cutover date

**Rule:** `mrfUsesFinanceAp(mrf)` = `mrf.created_at >= FINANCE_AP_CUTOVER_DATE`. No feature flag, no per-MRF toggle.

Set `FINANCE_AP_CUTOVER_DATE` in `.env` (see `.env.example`). Until set, all MRFs behave as **legacy internal** finance (vendor invoice portal stays locked).

### `GET /api/mrfs/{id}/available-actions` (updated)

| Field | Meaning |
|-------|---------|
| `usesFinanceAp` | Post-cutover cohort |
| `financeRoute` | `legacy_internal` \| `finance_ap` |
| `cutoverDate` | Configured cutover (ISO date) or null |
| `canProcessPayment` | `false` when `usesFinanceAp` — hide “Process Payment” |
| `canViewFinanceSync` | `true` for finance roles on Finance AP MRFs — show sync panel |
| `availableActions` | Includes `view_finance_sync` when applicable |

### Internal payment endpoints

| Endpoint | Post-cutover |
|----------|----------------|
| `POST /api/mrfs/{id}/process-payment` | `422` `FINANCE_AP_ROUTED` |
| `POST /api/mrfs/{id}/approve-payment` | `422` `FINANCE_AP_ROUTED` |

### `GET /api/dashboard/finance` (updated)

**New `routing` object:** `cutoverDate`, `routingConfigured`, `description`.

**Lists:**

| Key | Content |
|-----|---------|
| `financeMRFs` | Unified list (legacy + Finance AP) |
| `legacyFinanceMRFs` | Pre-cutover, `status` / `chairman_payment` |
| `financeApMRFs` | Post-cutover, `workflow_state` in Finance AP pipeline |

Each MRF row includes `usesFinanceAp`, `financeRoute`, `workflowState`, `financeApCaseId`, `financeApStatus`, `canProcessPaymentInternal`, `financeSyncPath` (when Finance AP).

**Stats:** `stats.legacy.*` (internal pending/chairman) and `stats.financeAp.*` (handoff, in review, package pushed).

**UI:**

- Legacy rows: show Process Payment / chairman flow.
- Finance AP rows: hide internal payment; link to `financeSyncPath` or Finance AP app.

---

## Phase 8 — Progress tracker & Finance AP reporting

### Progress tracker (`GET /api/mrfs/{id}/progress-tracker`)

**Phases (collapsible on UI):**

| Phase | Steps |
|-------|--------|
| Approval | MRF Created → Initial Approval → Procurement Review |
| Sourcing | RFQ Issued → Quotes Received → Vendor Selection Approved |
| Procurement | Vendor Final Invoice → PO Generated → PO Signed by SCD |
| Delivery | GRN / Goods Received → Delivery Documents Uploaded *(hidden when schedule is single 100% `on_advance` milestone)* |
| Payment | Finance Review → one row per milestone → Fully Paid / Closed |

**Response highlights:**

| Field | Purpose |
|-------|---------|
| `phases[]` | `{ id, label, steps[], completedSteps, totalSteps }` |
| `steps[]` | Flat list (same steps as phases); each step has `key`, `status`, `completedAt` (optional), `description` |
| `meta.hideDeliveryPhase` | `true` for 100% advance-only schedules |
| `meta.progressPercent` | Completed steps / total steps (delivery excluded when hidden) |
| `stageTimestamps` | `mrf_created_at`, `initial_approval_at`, `procurement_review_at`, `rfq_issued_at`, `quotes_received_at`, `vendor_selection_approved_at`, `vendor_invoice_submitted_at`, `po_generated_at`, `po_signed_at`, `grn_generated_at`, `delivery_docs_uploaded_at`, `finance_reviewed_at`, `payment_completed_at`, `closed_at` |
| `paymentSchedule` | Same shape as payment-schedule API (drives milestone rows) |
| `documentsByType` / `activeByType` | Same grouping as `GET /api/mrfs/{id}/procurement-documents` |
| `usesFinanceAp` / `financeRoute` | Cutover routing |

**Document-driven completion (backend):**

- Step `vendor_final_invoice`: complete when `activeByType.vendor_invoice` exists.
- Step `grn_received`: complete when `activeByType.grn` or legacy `grn_completed` + `grn_url`.
- Step `delivery_docs_uploaded`: complete when any of `waybill`, `jcc`, `delivery_confirmation` is active.

**UI:** Pass `documentsByType`, `activeByType`, and `paymentSchedule` from this endpoint (or procurement documents + schedule endpoints). Steps may be `completed` without `completedAt` — omit duration line only.

Legacy pre-cutover MRFs still get flat `steps[]` with chairman payment instead of Finance AP milestones.

### Finance AP reports

All require auth; roles: `finance`, `finance_officer`, `procurement_manager`, `procurement`, `supply_chain_director`, `supply_chain`, `admin`.

Query params (where applicable): `from`, `to` (dates), `limit` (list endpoints, default 50, max 100).

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/reports/finance-ap/summary` | Cohort totals: cases pushed, handoff, in review, closed, rejection/RFI rates, outstanding milestone balance |
| GET | `/api/reports/finance-ap/outstanding-milestones` | Unpaid milestones on Finance AP MRFs |
| GET | `/api/reports/finance-ap/advance-delivery-risk` | Advance paid (or paying) but delivery docs still missing |
| GET | `/api/reports/finance-ap/cycle-times` | Avg days PO signed → first milestone paid; PO signed → closed |

**UI:** Finance / procurement dashboards — cards from `summary`, tables from `outstanding-milestones` and `advance-delivery-risk`, KPI strip from `cycle-times`. Scope is post-cutover Finance AP cohort only (`FINANCE_AP_CUTOVER_DATE`).

**Report responses (all four endpoints):** include `cutoverDate` and `routingConfigured` (same semantics as `GET /api/dashboard/finance` → `routing`). When `routingConfigured` is `false`, cohort queries return empty/zero totals — show the cutover warning in UI.

**Backend:** Implemented in `MrfProgressTrackerService`, `FinanceApReportController`, and `FinanceApReportingService` (commit `144ece2` and follow-ups).

---

## SCM Platform — Trip Request & SRF updates (Jun 2026)

### Trip request — mandatory trip type & advance booking

**Trip type** (UI label) is stored as `bookingScope` / `booking_scope`:

| Value | Label | Minimum lead time |
|-------|--------|-------------------|
| `within_state` | Within State | 2 calendar days before trip date |
| `outside_state` | Outside State | 14 calendar days before trip date |

**Do not** use logistics `trip_type` (`personnel` / `material` / `mixed`) on the staff trip request form.

#### `GET /api/trip-requests/booking-rules`

Returns lead-time rules for inline form validation (mirror on the client; backend still enforces on submit).

```json
{
  "success": true,
  "data": {
    "bookingRules": {
      "scopes": [
        {
          "value": "within_state",
          "label": "Within State",
          "minimumLeadDays": 2,
          "violationMessage": "Within state trips must be requested at least 2 days in advance..."
        },
        {
          "value": "outside_state",
          "label": "Outside State",
          "minimumLeadDays": 14,
          "violationMessage": "Outside state trips must be requested at least 2 weeks (14 days) in advance..."
        }
      ],
      "referenceDate": "2026-06-01"
    }
  }
}
```

**Frontend:** Required radio/select for trip type. On date change, compare `scheduled_departure_at` (trip date) to `referenceDate + minimumLeadDays`. Disable submit and show `violationMessage` when invalid.

#### `POST /api/trip-requests` (updated)

**Request (staff):**

```json
{
  "destination": "Lagos Airport",
  "purpose": "Client meeting",
  "origin": "Office",
  "scheduled_departure_at": "2026-06-15T08:00:00Z",
  "scheduled_arrival_at": "2026-06-15T18:00:00Z",
  "passenger_user_ids": [2, 5],
  "bookingScope": "outside_state"
}
```

| Field | Required | Notes |
|-------|----------|--------|
| `bookingScope` | **Yes** | `within_state` \| `outside_state` (aliases: `tripType`, `booking_scope`) |
| `passenger_user_ids` | Yes | min 1 |
| `scheduled_departure_at` | Yes | Must satisfy lead-time for `bookingScope` |
| `driver_user_id` | **Removed** | Do not send; logistics assigns driver on convert |

**422 `BOOKING_LEAD_TIME_VIOLATION`:** `errors.bookingScope`, `errors.scheduled_departure_at`, `minimum_trip_date[]`.

**Response:** `data.trip` includes `bookingScope`, `bookingScopeLabel`, `progressSummary` (for list cards).

---

### My Trip Requests (staff dashboard)

#### `GET /api/trip-requests`

**Auth:** Same users who may create trip requests (`PassengerEligibility`).

**Query:** `limit` or `per_page` (default 50, max 100), optional `status`.

**Response:**

```json
{
  "success": true,
  "data": {
    "trips": [
      {
        "id": 42,
        "tripCode": "TRQ-20260601-ABC123",
        "destination": "Abuja",
        "bookingScope": "within_state",
        "bookingScopeLabel": "Within State",
        "workflowStage": "trip_request",
        "status": "draft",
        "progressSummary": {
          "currentStepKey": "submitted",
          "currentStepLabel": "Submitted",
          "progressPercent": 0
        },
        "progress": { "steps": [], "currentStepKey": "submitted" }
      }
    ],
    "pagination": { "total": 1, "per_page": 50, "current_page": 1, "last_page": 1 }
  }
}
```

**Frontend:** Add **My Trip Requests** section/tab on the staff dashboard; list from this endpoint; show `progressSummary` on each row; link to detail/progress.

#### `GET /api/trip-requests/{id}`

Single trip for the logged-in creator, passenger, or internal logistics/procurement roles.

#### `GET /api/trip-requests/{id}/progress-tracker`

Staff-facing steps: **Submitted → Logistics Review → Confirmed → Completed**.

```json
{
  "success": true,
  "data": {
    "progress": {
      "currentStepKey": "submitted",
      "currentStep": 1,
      "totalSteps": 4,
      "progressPercent": 0,
      "steps": [
        { "key": "submitted", "label": "Submitted", "status": "in_progress", "step": 1 },
        { "key": "logistics_review", "label": "Logistics Review", "status": "pending", "step": 2 }
      ]
    }
  }
}
```

**UI:** Reuse existing stepper/progress component from MRF if present; pass `data.progress.steps`.

---

### SRF — estimated cost optional

#### `POST /api/srfs` / `PUT /api/srfs/{id}`

| Field | Required |
|-------|----------|
| `duration` | **Yes** |
| `estimatedCost` / `estimated_cost` | **No** (nullable) |

All other prior required header fields unchanged.

**Frontend:** Remove required validator from Estimated Cost on SRF form; keep Duration required.

---

### SRF — line item View Details & progress

#### `GET /api/srfs` (updated)

- Staff / `regular_staff` / `employee` / `general_employee`: only own SRFs (`requester_id`).
- Default `include_line_items=true` — each SRF includes `lineItems[]` with `progressSummary` for list **View Details** buttons.

```json
{
  "success": true,
  "data": [
    {
      "id": "SRF-2026-001",
      "lineItems": [
        {
          "id": 10,
          "itemName": "Brake service",
          "progressSummary": {
            "currentStepLabel": "Supply Chain Director review",
            "srfStatus": "Pending",
            "srfStage": "supply_chain_director_review"
          }
        }
      ]
    }
  ],
  "pagination": { "total": 1, "per_page": 50, "current_page": 1, "last_page": 1 }
}
```

Set `include_line_items=false` to omit line items on list (lighter payload).

#### `GET /api/srfs/{id}/line-items/{itemId}`

**Use for:** View Details modal — full line item + parent SRF header + workflow progress.

```json
{
  "success": true,
  "srf": { "id": "SRF-2026-001", "title": "..." },
  "lineItem": { "id": 10, "itemName": "...", "quantity": 1, "progressSummary": {} },
  "progress": [ { "key": "logistics_initiated", "label": "...", "status": "completed" } ],
  "steps": []
}
```

#### `GET /api/srfs/{id}/progress-tracker`

SRF-level timeline (same steps as embedded in `GET /api/srfs/{id}` → `progress`). Prefer this for a dedicated tracker panel.

**Frontend:** Per line item in list, **View Details** → `GET .../line-items/{itemId}`; render `lineItem` + `progress` stepper. Do not duplicate a second progress API if `show` already loaded — extend existing modal.

---

## SRF View Details UI contract & trip draft deletion (Jun 2026 follow-up)

### Important: backend cannot render buttons

The API now exposes explicit **`ui`** blocks so the frontend must wire buttons/navigation. If cards still look “dead”, the dashboard is not reading these fields yet.

**List source:** `GET /api/srfs` (not procurement dashboard-only payloads). Response shape:

```json
{
  "success": true,
  "data": [
    {
      "id": "SRF-EMERALD-BD-TRN-2026-010",
      "formattedId": "SRF-EMERALD-BD-TRN-2026-010",
      "title": "Test SRF",
      "ui": {
        "cardClickable": true,
        "viewDetails": {
          "showButton": true,
          "label": "View Details",
          "method": "GET",
          "path": "/api/srfs/SRF-EMERALD-BD-TRN-2026-010"
        }
      },
      "lineItems": [
        {
          "id": 10,
          "itemName": "Service line A",
          "ui": {
            "viewDetails": {
              "showButton": true,
              "label": "View Details",
              "method": "GET",
              "path": "/api/srfs/SRF-EMERALD-BD-TRN-2026-010/line-items/10"
            },
            "progressTracker": {
              "method": "GET",
              "path": "/api/srfs/SRF-EMERALD-BD-TRN-2026-010/line-items/10"
            }
          }
        }
      ]
    }
  ],
  "srfs": []
}
```

#### Frontend actions (SRF dashboard list)

| User action | When `ui.*.showButton` is true | API call |
|-------------|-------------------------------|----------|
| **SRF card click** or **View Details** on card | `ui.cardClickable` / `ui.viewDetails` | `GET` `ui.viewDetails.path` → SRF detail page/modal |
| **View Details** on a line item row | `lineItems[].ui.viewDetails` | `GET` `lineItems[].ui.viewDetails.path` → modal with `progress` / `steps` |
| Line item progress only | After line item fetch | Render `data.progress` or `data.steps` as stepper (same component as MRF) |

#### `GET /api/srfs/{id}` (SRF detail — updated)

- Wrapped with `"success": true` at root (legacy fields still at top level).
- Each `items[]` / `line_items[]` entry includes **`ui.viewDetails`** (same as list).
- Includes `progress`, `progressTracker.path`, `lineItemCount`.

**Flow:** Card → SRF detail → per line item **View Details** → line item modal with full `progress` timeline.

#### `GET /api/srfs/{id}/line-items/{itemId}` (line item detail + progress)

```json
{
  "success": true,
  "data": {
    "srf": { "title": "...", "ui": { "cardClickable": true } },
    "lineItem": { "itemName": "...", "ui": { "viewDetails": { "showButton": true, "path": "..." } } },
    "progress": [
      { "key": "supply_chain_director_review", "label": "Supply Chain Director review", "status": "in_progress" }
    ],
    "steps": []
  }
}
```

Use **`data.progress`** (or `data.steps`) for the line item progress tracker UI.

---

### Trip request — delete draft

#### `DELETE /api/trip-requests/{id}`

**Who:** Creator only (`created_by` = current user). Same eligibility as creating trip requests.

**When allowed:** `status` = `draft` **and** `workflow_stage` = `trip_request` (staff `TRQ-*` requests only).

**When blocked:** 422 `INVALID_STATE` if already submitted or past draft.

**Request:** No body.

**Response:**

```json
{
  "success": true,
  "data": {
    "message": "Draft trip request deleted successfully",
    "deletedId": 42
  }
}
```

**List/detail flags** (on each trip in `GET /api/trip-requests` and `GET /api/trip-requests/{id}`):

```json
{
  "canDelete": true,
  "isDraft": true,
  "ui": {
    "deleteDraft": {
      "showButton": true,
      "label": "Delete draft",
      "method": "DELETE",
      "path": "/api/trip-requests/42",
      "confirmMessage": "Are you sure you want to delete this draft trip request? This cannot be undone."
    }
  }
}
```

When `canDelete` is `false`, omit the delete button entirely (`ui.deleteDraft` is `null`).

**Frontend:**

1. Show delete control on list row and detail only when `canDelete === true` (or `ui.deleteDraft.showButton`).
2. On click, show `ui.deleteDraft.confirmMessage` in a confirmation dialog.
3. On confirm, `DELETE` `ui.deleteDraft.path`, then remove the row from local state or refetch the list.

---

## Batch 3 — SMS / Termii (future frontend batch)

**Backend contract:** `docs/BATCH3_SMS_TERMII.md`  
**This batch:** documentation only — no SPA code, no SPA env vars.

### What the SPA does NOT need

- No `TERMII_*` or `SMS_ENABLED` in frontend `.env` / `.env.example`.
- No direct calls to `POST /api/notifications/sms/send` (internal/backend only).

### Future surfaces (when backend is live)

#### 1. Settings → Notification Preferences

Add an **SMS** toggle column per trigger alongside Email / In-app.

| Trigger key | Label (example) |
|-------------|-----------------|
| `mrf.approval_required` | MRF needs your approval |
| `mrf.rejected` | MRF rejected |
| `po.signed` | PO signed |
| `rfq.invitation` | RFQ invitation |
| `trip.assigned` | Trip assigned |
| `document.expiring` | Document expiring soon |

- Persist via user notification-preferences API (to be added with backend implementation).
- Default all SMS toggles **off** until user opts in.
- Backend gates sends on `notification_preferences.sms_enabled` per trigger.

#### 2. Admin → SMS Logs

**Endpoint:** `GET /api/notifications/sms/logs`  
**Roles:** `admin`, `supply_chain_director`

**Query params:** `trigger`, `status`, `to`, `date_from`, `date_to`, `page`, `per_page`

**Table columns:** timestamp, to, trigger, entity link (`entity_type` + `entity_id`), status, cost, error.

**Resend (future):** admin-only action on `failed` rows — endpoint TBD (`POST …/logs/{id}/resend`).

#### 3. User profile — phone requirement

- When user enables SMS for **any** trigger, `users.phone` becomes required.
- Validate E.164 (Nigerian `+234…`) before save; surface backend validation errors inline.
- Phone is collected on profile edit, not on the SMS settings page alone.

### TypeScript types (prepare when implementing UI)

```typescript
type SmsLogStatus = 'queued' | 'sent' | 'delivered' | 'failed' | 'expired';

type SmsTrigger =
  | 'mrf.approval_required'
  | 'mrf.rejected'
  | 'po.signed'
  | 'rfq.invitation'
  | 'trip.assigned'
  | 'document.expiring';

interface SmsLog {
  id: string;
  to: string;
  message: string;
  trigger: SmsTrigger;
  entity_type: string | null;
  entity_id: string | null;
  status: SmsLogStatus;
  termii_message_id: string | null;
  error: string | null;
  cost: string | null;
  queued_at: string | null;
  sent_at: string | null;
  delivered_at: string | null;
  failed_at: string | null;
  created_at: string;
}
```
