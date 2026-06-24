# Finance AP ↔ SCM Integration — Phase 6 Requirements (Finance AP Side)

**Audience:** Development on `financeap-backend` and `EmeraldFinanceAP` (frontend)  
**SCM counterpart:** `supply-chain-backend` Phase 6 (`FinanceIntegrationService`)  
**Status:** Integration decisions **locked** (2026-05-31) — implementation not started  
**Copies:** Same file lives at `financeap-backend/docs/FINANCE_AP_SIDE_SCM_INTEGRATION.md`

This document describes what exists in Finance AP today, what SCM will send, and **what must be added or changed on Finance AP** for Phase 6. Finance AP is an **existing** platform — SCM integrates via API and webhooks; it is not rebuilt from scratch.

---

## 1. Clarification (important)

| | Finance AP (`financeap-backend`) | SCM (`supply-chain-backend`) |
|---|----------------------------------|------------------------------|
| **Role** | Payment execution & finance review **system of record** | Procurement orchestration, documents, milestones, gates |
| **Phase 6 on SCM** | Receives packages; exposes webhooks + document refresh | `FinanceIntegrationService`, push packages, handle inbound webhooks |
| **Phase 6 on Finance AP** | Inbound package API, SCM cases, outbound webhooks | Phases 0–5 already complete on SCM |

---

## 2. Locked integration decisions

These answers are **authoritative** for Phase 6 design and implementation.

| # | Topic | Decision |
|---|--------|----------|
| 1 | **SCM case vs PO/Invoice** | SCM push creates an **SCM case only**. **Do not** auto-create Finance AP `purchase_orders` or `invoices`. Staff may manually create or link PO/invoice from within the case if needed. |
| 2 | **Payments** | Every milestone payment uses existing **`payment_requests`** + **`approval_thresholds`**. SCM milestone data describes what is expected; execution follows the current Finance AP approval chain. **No new payment flow.** |
| 3 | **Documents** | **Do not** treat SCM as long-term file storage. On case create and on each **delta** push, Finance AP **pulls and caches** document bytes from SCM signed S3 URLs. When a URL expires, Finance AP calls SCM’s **document refresh** endpoint and re-caches. |
| 4 | **Vendors** | **SCM is vendor master (Pattern A).** Push on package/delta **and** `POST /api/v1/integrations/scm/vendors` (implemented on FA). See **`docs/FINANCE_AP_VENDOR_SYNC_PATTERN_A.md`**. |
| 5 | **PO numbers** | SCM `po_number` is a **human-readable reference only**. Finance AP keeps **`PO-YYYY-NNNNN`** for its own POs. Both systems store the other’s reference for lookup; neither overrides the other’s numbering. |
| 6 | **Webhooks (Finance AP → SCM)** | `POST https://supply-chain-backend-hwh6.onrender.com/api/webhooks/finance-ap` (SCM will implement in Phase 6). |
| 7 | **Human workflow** | Ingest creates case in **`pending_review`** — **no auto-approve**. **Account Officer:** initial review + document verification. **Finance Manager:** case approval + milestone payment authorization. |
| 8 | **Finance AP API base (SCM → Finance AP)** | `https://financeap-backend.onrender.com` — SCM pushes packages here (`FINANCE_AP_BASE_URL`). |
| 9 | **Frontend** | Separate repo: **`EmeraldFinanceAP`** (`/Users/asukuonukaba/Desktop/EmeraldFinanceAP`). New nav **“SCM Cases”** for **Account Officer** and **Finance Manager** only, gated via `GET /api/v1/me` `navigation`. Lists cases, documents, milestone schedules, status. |

---

## 3. Environment URLs

| Direction | URL |
|-----------|-----|
| SCM → Finance AP (push package / delta) | `https://financeap-backend.onrender.com/api/v1/integrations/scm/...` (routes TBD on FA) |
| Finance AP → SCM (webhooks) | `https://supply-chain-backend-hwh6.onrender.com/api/webhooks/finance-ap` |
| Finance AP → SCM (document refresh) | SCM endpoint TBD in Phase 6 (authenticated GET by `scm_transaction_id` + `document_id`) |

Shared secret: `FINANCE_AP_WEBHOOK_SECRET` / `SCM_WEBHOOK_SECRET` (same value both sides).

---

## 4. Current Finance AP architecture (as-is)

### 4.1 Stack & API surface

- **Framework:** Laravel, API prefix **`/api/v1`**
- **Auth:** Laravel Sanctum Bearer tokens for **human users** (`POST /api/v1/login`)
- **Response envelope:** `{ success, data, message }` via `App\Http\Controllers\Concerns\ApiResponse`
- **RBAC:** `App\Enums\UserRole` + Gates in `AppServiceProvider`
- **Spec reference:** `FINANCE_AP_SPEC_V2_BACKEND.md`

### 4.2 Existing domains (unchanged for SCM)

| Domain | SCM integration |
|--------|-----------------|
| Float requests | No SCM coupling |
| Payment requests | **Used** for milestone payouts (link via `scm_payment_milestones.payment_request_id`) |
| Purchase orders / Invoices | **Manual only** from SCM cases — not auto-created from push |
| Vendors | Synced from SCM on push via mapping table |
| Audit logs | Reuse for SCM case events |

### 4.3 Missing today

- SCM case model, inbound integration routes, outbound webhooks, document cache layer, vendor mapping table

### 4.4 Repository hygiene (blocker)

`financeap-backend/routes/api.php` has an **unresolved Git merge conflict** — resolve before adding routes.

---

## 5. SCM → Finance AP: package contract

Stable primary key: **`scm_transaction_id`** (UUID on MRF).

### 5.1 Initial package (`pushPackage`)

| Section | Content |
|---------|---------|
| **Header** | `scm_transaction_id`, `mrf_id`, `formatted_id`, `scm_po_number` (reference), **`vendor`** (full snapshot — see `FINANCE_AP_VENDOR_SYNC_PATTERN_A.md`), contract type, department, currency, totals |
| **Payment schedule** | Milestones with `scm_milestone_id`, %, amount, triggers, `required_documents[]` |
| **Line items** | MRF/RFQ/PO lines |
| **Approvals summary** | Stage, status, timestamp, **role label only** (no user names/emails) |
| **Document manifest** | `document_id`, `type`, `file_name`, `sha256`, `version`, signed URL for pull |
| **Context** | Optional `requested_milestone_id` |

### 5.2 Delta (`pushDelta`)

Updated manifest (e.g. `vendor_invoice_submitted`). Finance AP re-pulls and re-caches changed documents.

### 5.3 Document refresh (Finance AP → SCM)

When cached copy is stale or URL expired, Finance AP calls SCM refresh API; SCM returns fresh signed URL; Finance AP re-caches.

---

## 6. Finance AP → SCM: webhooks

| `event_type` | When | SCM action |
|--------------|------|------------|
| `approved` | Case or milestone approved in FA | Update MRF / milestone state |
| `rejected` | Rejection in FA | Notify Procurement |
| `payment_posted` | Milestone paid via `payment_requests` flow | Update milestone paid fields |
| `case_closed` | FA case closed | Closure readiness on SCM |
| `rfi_raised` | RFI in FA | Notify Procurement |

Payload must include: `scm_transaction_id`, `finance_ap_case_id`, `event_id`, `occurred_at`, optional `scm_milestone_id`, HMAC signature.

---

## 7. Finance AP implementation checklist

### 7.1 Configuration

| Key | Example / notes |
|-----|-----------------|
| `SCM_INTEGRATION_API_KEY` | Validates inbound calls from SCM |
| `SCM_WEBHOOK_URL` | `https://supply-chain-backend-hwh6.onrender.com/api/webhooks/finance-ap` |
| `SCM_WEBHOOK_SECRET` | Shared with SCM |
| `SCM_DOCUMENT_REFRESH_BASE_URL` | SCM API base for document refresh |

### 7.2 Data model (high level)

- **`scm_procurement_cases`** — `scm_transaction_id` (unique), `finance_ap_case_id`, `scm_po_number` (reference), `status` starting at **`pending_review`**, `package_snapshot`, `package_version`
- **`scm_payment_milestones`** — mirror SCM milestones; `payment_request_id` FK when payment initiated
- **`scm_case_documents`** — manifest + **`cached_file_path`** + `cached_at` + `scm_document_id` (pull/cache on ingest/delta)
- **`vendor_scm_mappings`** — `scm_vendor_id` → `vendors.id`
- **`scm_inbound_events`** / **`scm_outbound_webhooks`** — idempotency and delivery log

Optional on case: nullable `finance_ap_po_id`, `finance_ap_invoice_id` for **manual** links only.

### 7.3 Inbound routes (machine auth)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/v1/integrations/scm/packages` | Create/update case (`pending_review`) |
| `POST` | `/api/v1/integrations/scm/packages/{scm_transaction_id}/delta` | Delta + re-cache docs |
| `GET` | `/api/v1/integrations/scm/packages/{scm_transaction_id}` | Case status for SCM / support |

Response on create: `{ finance_ap_case_id, status: "pending_review", ... }` → SCM stores `finance_ap_case_id` on MRF.

### 7.4 Human workflow (Finance AP UI)

| Role | Actions |
|------|---------|
| **Account Officer** | Open case (`pending_review` → review), verify cached documents, flag issues / RFI |
| **Finance Manager** | Approve case, authorize milestone → creates **`payment_request`** per existing rules |
| **Executive** | Read-only (existing pattern) |

Milestone payment: Finance Manager action → `payment_requests` + `approval_thresholds` → on paid → webhook `payment_posted`.

### 7.5 Frontend (`EmeraldFinanceAP`)

- Nav item **“SCM Cases”** from `GET /api/v1/me` `navigation` (Account Officer + Finance Manager only)
- List: MRF ref, vendor, amount, status, milestone summary
- Detail: document viewer (cached files), milestone table, actions to link manual PO/invoice, start payment request

### 7.6 Out of scope for Finance AP Phase 6

- Auto-creating `purchase_orders` / `invoices` from SCM push
- New approval engine (reuse `payment_requests`)
- Float request automation from SCM

---

## 8. SCM Phase 6 checklist (reference)

SCM (`supply-chain-backend`) will implement:

| Item | Notes |
|------|--------|
| `finance_sync_events` table | Outbound/inbound audit |
| `finance_ap_case_id`, `finance_ap_status` on MRF | |
| `FinanceIntegrationService` | `buildPackage`, `pushPackage`, `pushDelta`, `handleWebhook` |
| `POST /api/webhooks/finance-ap` | Receives FA webhooks (URL above) |
| Document refresh API | For FA when signed URLs expire |
| `FINANCE_AP_BASE_URL` | `https://financeap-backend.onrender.com` |
| Finance monitor UI on SCM | Sync status, no “Process Payment” for Finance AP MRFs |

---

## 9. Implementation order (when approved to start)

**Finance AP**

1. 
2. Migrations + config + API key middleware  
3. Package ingest → `pending_review` + vendor sync + document pull/cache 
4. Outbound webhooks to SCM  
5. Milestone → `payment_requests` linkage  
6. Delta + document re-cache  
7. `GET /me` navigation + SCM Cases API for frontend  
8. E2E with SCM staging  

**SCM**

1. `FinanceIntegrationService` + sync events  
2. Webhook receiver at `/api/webhooks/finance-ap`  
3. Document refresh endpoint  
4. Push to `https://financeap-backend.onrender.com`  

**EmeraldFinanceAP**

1. SCM Cases list + detail screens  

---

*Last updated: 2026-05-31 — integration decisions locked; implementation not started.*
