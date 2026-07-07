# Backend Performance Forensic Audit

**Repository:** `supply-chain-backend`  
**Audit date:** 2026-07-07  
**Scope:** MRF lists, SRF lists, Purchase Orders, Dashboard statistics  
**Method:** Static code review of routes, controllers, models, services, and migrations. No application code was modified during this audit.

---

## Executive Summary

The backend has received partial performance hardening (server-side pagination, selective column lists for MRF/SRF, `DashboardStatsCache`, and several July 2026 index migrations). However, severe latency is still likely under production load because:

1. **Finance and vendor dashboards** execute many uncached, full-table aggregate and list queries on every page load.
2. **Purchase Order flows** piggyback on the MRF table with OR/`LIKE`-heavy scopes that defeat indexes.
3. **Role-scoped activity feeds** pre-harvest large MRF ID sets before applying a `LIMIT 20`.
4. **Several list endpoints** still ship wide column sets or opt-in `SELECT *` paths that inflate payloads.
5. **N+1 query patterns** remain in the vendor portal MRF list (`PaymentSchedule` lookup per row).
6. **Index coverage gaps** exist for finance routing, PO draft lifecycle, activities, and quotation status filters.

Recent frontend changes (documented in `frontend_changes.md`) reduced some client-side over-fetching, but backend hot paths listed below remain the primary bottleneck for table loads and dashboard statistics.

---

## 1. Identified Bottlenecks & Code Location Traces

### 1.1 MRF Lists — `GET /api/mrfs`

| Attribute | Detail |
|-----------|--------|
| **Route** | `routes/api.php` L361 |
| **Controller** | `app/Http/Controllers/Api/MRFController.php` |
| **Entry method** | `index()` L208–327 |
| **Model helpers** | `app/Models/MRF.php` — `resolveListApiSelect()` L630–655, `toListApiArray()` L662–734, `scopeForPoList()` L61–74, `scopeWithPoLifecycleStatus()` L79–111 |
| **Pagination** | Yes — `ResolvesPaginatedLists` trait; default `per_page=25`, max `100` (`MRFController.php` L319–320) |

#### Execution flow

1. Base query selects columns from `MRF::resolveListApiSelect()` (L211–213).
2. Eager-loads `requester:id,name,email,department` (L213).
3. Optional PO list mode when `po_list=1` or `has_po=1` applies `forPoList()` (L215–218).
4. Status / workflow / date / search filters applied (L222–265).
5. Sort (default `created_at desc`; PO list defaults to `updated_at desc`) (L267–287).
6. Role scoping for employees (`requester_id`) (L315–317).
7. `paginate()` then `toListApiArray()` per row (L319–325).

#### Bottlenecks identified

| Severity | Issue | Location | Impact |
|----------|-------|----------|--------|
| **High** | PO list scopes use nested `OR` conditions and `LOWER(...) LIKE '%...%'` for rejected/completed buckets | `MRF.php` L61–111 (`forPoList`, `withPoLifecycleStatus`) | Prevents index use on `po_number`, `po_draft_saved_at`, `signed_po_url`; forces full-table or large-range scans as `m_r_f_s` grows |
| **High** | Search filter uses leading-wildcard `LIKE '%…%'` across four columns | `MRFController.php` L254–264 | Cannot use B-tree indexes on `mrf_id`, `formatted_id`, `po_number`, `requester_name` efficiently |
| **Medium** | `LIST_API_SELECT` still selects ~40 columns including document URLs, GRN fields, approval metadata | `MRF.php` L608–621 | Rows are “selective” relative to `SELECT *` but still large; JSON serialization and network transfer scale with page size (25–100 rows) |
| **Medium** | Eager-loaded `requester` relation is unused in list payload — `toListApiArray()` emits `requester` as a plain string from `requester_name` | `MRF.php` L679 vs L213 in controller | Wasted JOIN + hydration on every list page |
| **Low** | `MrfParallelFirstApprovalService::apiFields()` invoked per row via `app()` inside `toListApiArray()` | `MRF.php` L690 | Minor CPU overhead; resolves in-memory only (no extra DB) |
| **Medium** | Dashboard sub-lists filter `where('status', 'Pending')` with capital **P** | `DashboardController.php` L75, L98; `supplyChainDirectorDashboard()` L209 | If workflow migration normalized status to lowercase (`pending`), these filters return zero rows while still scanning; also mismatches list endpoint filters |

#### N+1 patterns

- **Default list path:** No N+1 detected — single paginated query + one eager load.
- **Detail paths (related):** `show()` L422–607 and `getFullDetails()` L641+ load deep relation trees and call multiple services per request (not list, but contributes to perceived slowness when detail panels open).

#### SELECT * / wide fetch

- List endpoint uses explicit column list (good).
- `show()` and `getFullDetails()` load full model rows with no column restriction (`MRFController.php` L424–433, L669–692).

---

### 1.2 SRF Lists — `GET /api/srfs`

| Attribute | Detail |
|-----------|--------|
| **Route** | `routes/api.php` L441 |
| **Controller** | `app/Http/Controllers/Api/SRFController.php` |
| **Entry method** | `index()` L58–173 |
| **Model helpers** | `app/Models/SRF.php` — `resolveListApiSelect()` L111–136, `toListApiArray()` L141+, `LIST_API_SELECT` L101–106 |
| **Presentation** | `presentSrf()` L180+ (heavy path) |

#### Execution flow

1. When `include_line_items` is **false** (default): selective `SRF::resolveListApiSelect()` + eager `requester` (L60–63).
2. Status, search, date, role, and logistics scoping filters (L65–146).
3. Optional `with('items')` when `include_line_items=true` (L149–151).
4. Paginate, map to `toListApiArray()` or `presentSrf()` (L161–167).
5. Response duplicates data under both `data` and `srfs` keys (L169–172).

#### Bottlenecks identified

| Severity | Issue | Location | Impact |
|----------|-------|----------|--------|
| **Critical** | `include_line_items=true` **skips** column selection — implicit `SELECT *` on `s_r_f_s` | `SRFController.php` L60–62 | Loads JSON blob columns (`vehicle_snapshot`, `maintenance_history`, `rfq_prefill`, `approval_history`, `payment_milestones`) for every row on the page |
| **High** | `presentSrf()` always embeds `approvalHistory`, `vehicleSnapshot`, `maintenanceHistory`, `rfqPrefill`, payment milestones, and calls `RequesterEditWindowService::metaForSrf()` per row | `SRFController.php` L180–253 | Massive JSON payloads; unsuitable for table views |
| **High** | Logistics officer / procurement-overview scoping uses `OR` + `LIKE 'Fleet%'` + `LOWER(department) LIKE '%logistics%'` | `SRFController.php` L124–145 | Index-unfriendly; broad scans for logistics dashboards |
| **Medium** | Search uses leading-wildcard `LIKE` on three columns | `SRFController.php` L71–80 | Same index problem as MRF search |
| **Low** | Duplicate response keys (`data` + `srfs`) | `SRFController.php` L169–172 | ~2× JSON encoding work for the same array |

#### N+1 patterns

- Default list: No N+1 — single query + eager `requester`.
- `include_line_items=true`: Eager `items` prevents line-item N+1, but parent row bloat is the dominant cost.

---

### 1.3 Purchase Orders

There is **no dedicated PO list table or controller**. PO lists are served through MRF endpoints and finance services.

| Surface | Route / Service | File & Lines | Notes |
|---------|-----------------|--------------|-------|
| **PO tab list** | `GET /api/mrfs?po_list=1` | `MRFController.php` L215–218; `MRF.php` L61–111 | Same MRF list pipeline with `forPoList()` + lifecycle status scopes |
| **PO close** | `POST /api/pos/{id}/close` | `routes/api.php` L404; `PurchaseOrderController.php` | Mutation, not list |
| **Finance AP open POs** | `GET /api/vendors/{scm_vendor_id}/open-purchase-orders` | `FinanceApOpenPurchaseOrderController.php`; `FinanceApOpenPurchaseOrderService.php` L41–82 | Finance integration list |
| **Vendor portal PO-related MRFs** | `GET /api/vendor-portal/mrfs` | `VendorPortalMrfController.php` L31–103 | Vendor-facing list with invoice gate status |

#### Bottlenecks identified

| Severity | Issue | Location | Impact |
|----------|-------|----------|--------|
| **High** | PO lifecycle filtering relies on `scopeForPoList()` OR branches and `LOWER(...) LIKE` status buckets | `MRF.php` L61–111 | Primary PO table view performance risk |
| **High** | `FinanceApOpenPurchaseOrderService::paginateForVendor()` filters **after** pagination by `remainingBalance > 0` | `FinanceApOpenPurchaseOrderService.php` L70–81 | Page may return fewer rows than `per_page`; `total` count is wrong; frontend may over-fetch pages |
| **High** | `listForVendor()` calls `paginateForVendor(..., 1, 100)` with no further paging | `FinanceApOpenPurchaseOrderService.php` L33–35 | Hard cap of 100; unpaginated array return for consumers using `listForVendor()` |
| **High** | Vendor portal: `VendorInvoiceGateService::status()` called per row; internally calls `PaymentScheduleService::findForMrf()` | `VendorPortalMrfController.php` L71–73; `VendorInvoiceGateService.php` L30–50; `PaymentScheduleService.php` L32–37 | **N+1:** up to 25–50 extra queries per page (one `payment_schedules` + `milestones` load per MRF) |
| **Medium** | Finance AP open PO query eager-loads `paymentSchedule.milestones` + nested RFQ/quotation per MRF | `FinanceApOpenPurchaseOrderService.php` L53–60 | Heavy hydration; acceptable if paginated correctly |

#### SELECT * / wide fetch

- Vendor portal list uses a tight 9-column select (good) — `VendorPortalMrfController.php` L41–52.
- Finance AP open PO list uses explicit select (good) — `FinanceApOpenPurchaseOrderService.php` L48–52.

---

### 1.4 Dashboard Statistics & List Payloads

| Endpoint | Route | Controller Method | Caching | Recomputes on every load? |
|----------|-------|-------------------|---------|---------------------------|
| KPIs | `GET /api/dashboard/kpis` | `DashboardKpiController::index()` L14–45 | **Yes** — `DashboardStatsCache` 300s | No (cached) |
| Procurement Manager | `GET /api/dashboard/procurement-manager` | `DashboardController::procurementManagerDashboard()` L28–177 | Stats cached; **lists not cached** | Stats: no; lists: **yes** |
| Supply Chain Director | `GET /api/dashboard/supply-chain-director` | `DashboardController::supplyChainDirectorDashboard()` L182–294 | Stats + metrics cached | Stats/metrics: no; SRF approval list + registrations: **yes** |
| Finance | `GET /api/dashboard/finance` | `DashboardController::financeDashboard()` L424–579 | **None** | **Yes — every request** |
| Vendor | `GET /api/dashboard/vendor` | `DashboardController::vendorDashboard()` L299–418 | **None** | **Yes — every request** |
| Recent Activities | `GET /api/dashboard/recent-activities` | `DashboardController::getRecentActivities()` L584–679 | **None** | **Yes — every request** |
| Logistics stats | `GET /api/dashboard/logistics-statistics` | `LogisticsDashboardController::stats()` L13–43 | **None** | **Yes — 4 COUNT clones** |
| Reports dashboard | `GET /api/reports/dashboard` | `ReportsDashboardController::index()` → `ReportsDashboardService::dashboard()` | **Yes** — `ReportCache` | No (cached by date range) |

#### Finance dashboard deep trace (`financeDashboard()`)

**File:** `app/Http/Controllers/Api/DashboardController.php` L424–579

Per request, the endpoint executes:

1. **Three separate paginated MRF queries** (each with eager loads):
   - Legacy finance cohort — L503–509
   - Finance AP cohort — L506–510
   - Unified cohort — L512–514
2. Each paginated query uses `financeDashboardEager()` (L719–729): `requester`, `selectedVendor`, `executiveApprover`, `rfqs` (limited to 1) with `selectedQuotation`.
3. **Five additional COUNT queries** on cloned base queries — L520–541.
4. Per-row mapping calls `FinanceRoutingService::routingMeta()`, `poOriginApiFields()`, `poDraftApiFields()`, and `freshUnsignedPoStreamUrl()` — L446–500.

**Estimated DB round-trips per finance dashboard load:** 8+ queries (3 paginated + 5 counts), each paginated query potentially running 4–5 eager-load sub-queries.

#### Recent activities deep trace (`getRecentActivities()`)

**File:** `app/Http/Controllers/Api/DashboardController.php` L584–679

1. `MRF::where('requester_id', $user->id)->pluck('mrf_id')` — L590 (unbounded ID harvest).
2. Role-based **additional** `pluck('mrf_id')` queries with `orWhere` chains — L605–627 (procurement, finance, SCD roles).
3. `array_unique` on potentially thousands of IDs — L630.
4. Final `Activity` query with `whereIn('entity_id', $relevantMRFIds)` — L636–662.
5. Vendor branch adds subquery on `quotations` — L651–657.

**Problem:** Steps 1–3 run before `LIMIT 20` is applied. For admin/procurement/finance roles, this can materialize very large ID arrays and produce slow `whereIn` clauses.

#### Vendor dashboard deep trace (`vendorDashboard()`)

**File:** `app/Http/Controllers/Api/DashboardController.php` L299–418

1. `RFQ::whereHas('vendors', ...)->with(['mrf'])->orderByDesc('created_at')->get()` — L348–365 — **unpaginated**.
2. `Quotation::where('vendor_id', ...)->with(['rfq'])->orderByDesc('created_at')->get()` — L368–381 — **unpaginated**.
3. Three separate `Quotation::where(...)->count()` for stats — L386–394.

Vendors with long histories will receive ever-growing JSON arrays.

#### Procurement manager dashboard lists (uncached portion)

**File:** `app/Http/Controllers/Api/DashboardController.php` L53–128

Four bounded list queries (`limit($listLimit)`) run on every load:
- Pending vendor registrations — L54–67
- Pending MRFs — L70–90
- Pending SRFs — L93–111
- Pending quotations with `rfq` + `vendor` eager loads — L114–128

Stats block is cached (L130–163), but list payloads are not.

---

### 1.5 Additional Unpaginated or Heavy Endpoints (Cross-Cutting)

| Endpoint | File | Lines | Issue |
|----------|------|-------|-------|
| `GET /api/users` (user directory) | `UserManagementController.php` | L59 | `$query->get()` — entire users table, no pagination |
| `GET /api/search?q=…` | `SearchController.php` | L26–72 | `SELECT *` on MRF, SRF, RFQ (limit 20 each); `LOWER(...) LIKE` prevents index use |
| `GET /api/mrfs/{id}` | `MRFController.php` | L422–607 | Full row + 6 relations + 5+ service calls |
| `GET /api/mrfs/{id}/full-details` | `MRFController.php` | L641–750+ | Deep tree: RFQs → quotations → vendor → items; loops building payloads |
| `FinanceApOpenPurchaseOrderService::listForVendor()` | `FinanceApOpenPurchaseOrderService.php` | L33–35 | Returns up to 100 rows as flat array |

---

### 1.6 N+1 Query Pattern Summary

| Location | Pattern | Queries per page |
|----------|---------|------------------|
| `VendorPortalMrfController::index()` L71–73 | `gateService->status($mrf)` → `findForMrf($mrf)` per row | 1 + N (N = page size) |
| `DashboardController::financeDashboard()` | Not classic N+1, but 3× redundant paginated list queries | 3× page cost |
| `MRFController::getFullDetails()` | Deep eager loading prevents quotation N+1, but processes all RFQs/quotations in PHP loops | CPU-bound |

**Note:** `VendorPortalMrfController` already batch-loads submitted invoice flags (L58–69) — the remaining N+1 is the payment schedule lookup inside `VendorInvoiceGateService`.

---

### 1.7 Unpaginated or Oversized JSON Payload Summary

| Source | Risk |
|--------|------|
| `VendorPortalMrfController` + `PaymentSchedule` N+1 | High latency per vendor portal page |
| `vendorDashboard()` RFQ + quotation `->get()` | Payload grows unbounded with vendor activity |
| `UserManagementController::index()` `->get()` | Full user directory on every directory view |
| `SRFController` with `include_line_items=1` | Table views accidentally requesting line items will timeout |
| `MRF::LIST_API_SELECT` (~40 cols × 100 rows) | Large list responses even without `SELECT *` |
| `getRecentActivities()` ID harvesting | Memory + slow `whereIn` before limit |

---

## 2. Database Schema & Indexing Analysis

### 2.1 Core Tables Reviewed

| Table | Primary access patterns | Migration / model source |
|-------|------------------------|--------------------------|
| `m_r_f_s` | List by `status`, `workflow_state`, `requester_id`, `created_at`; PO list by `po_number`, `po_draft_saved_at`, `signed_po_url`; finance by `workflow_state` + `signed_po_url`; vendor portal by `selected_vendor_id` + `workflow_state` | `app/Models/MRF.php`; migrations through `2026_07_03_000002` |
| `s_r_f_s` | List by `status`, `date`, `requester_id`; logistics by `service_type`, `department` | `app/Models/SRF.php`; migrations through `2026_07_03_000002` |
| `activities` | Filter by `entity_type` + `entity_id`, order by `created_at` | `2026_01_20_120224_create_activities_table.php` |
| `payment_schedules` | Lookup by `mrf_id` (unique FK) | `2026_05_31_100004_create_payment_schedules_table.php` |
| `procurement_documents` | Lookup by `mrf_id`, `type`, `is_active`, `vendor_id` | `2026_05_31_100003_create_procurement_documents_table.php` |
| `quotations` | Dashboard counts by `status`; vendor portal by `vendor_id` | Various |
| `quotations` + `r_f_q_s` | On-time delivery join in procurement stats | `DashboardController.php` L138–145 |

### 2.2 Existing Indexes (July 2026 performance migrations)

| Migration | Indexes added |
|-----------|---------------|
| `2026_07_01_140000_add_list_query_indexes.php` | `mrfs_status_created_idx`, `mrfs_workflow_updated_idx`, `mrfs_requester_idx`, `mrfs_po_number_idx` |
| `2026_07_02_120000_add_mrf_list_search_indexes.php` | `mrfs_created_at_idx`, `mrfs_formatted_id_idx` |
| `2026_07_02_140000_add_module_search_indexes.php` | `mrfs_po_number_idx`, `mrfs_requester_name_idx`; SRF `formatted_id`, `srf_id`, `requester_name` |
| `2026_07_02_200000_add_hot_path_performance_indexes.php` | `mrfs_vendor_workflow_po_signed_idx`; `quotations_vendor_rfq_idx`; `vendor_registrations_status_created_idx`; `rfq_vendors_vendor_rfq_idx` |
| `2026_07_03_000002_add_mrf_srf_attachment_performance_indexes.php` | `mrfs_status_stage_created_idx`, `mrfs_workflow_created_idx`, `mrfs_requester_created_idx`; SRF `status_created`, `status_stage_created`, `requester_created`, `date_created`; line items; attachments |

**Operational note:** Several migrations use `indexExists()` guards because earlier migrations may have created overlapping indexes (e.g. `mrfs_po_number_idx` appears in both `2026_07_01` and `2026_07_02_140000`). Verify applied state in target environment before adding new indexes.

### 2.3 Critical Index Gaps

| Column(s) / predicate | Used by | Current index? | Risk |
|----------------------|---------|----------------|------|
| `po_draft_saved_at` | `scopeForPoList()` draft bucket; PO list default sort `updated_at` | **No dedicated index** | PO draft tab scans rows with `po_draft_saved_at IS NOT NULL` |
| `finance_ap_status` | Finance dashboard AP counts | **No** | Full scan for `finance_ap_case_id` / status filters |
| `signed_po_url` (partial: `IS NOT NULL`) | `FinanceRoutingService::scopeLegacyFinanceReady()`, `scopeFinanceApFinanceReady()` | **No partial index** | Finance cohort queries always filter `whereNotNull('signed_po_url')` |
| `(workflow_state, signed_po_url, created_at)` | Finance AP + unified finance scopes | Partial — `mrfs_workflow_created_idx` lacks `signed_po_url` | Composite filter may not use index efficiently |
| `activities (entity_type, entity_id, created_at)` | `getRecentActivities()` | Only separate indexes on `entity_type` and `created_at` (`2026_01_20` migration L16–25) | `whereIn(entity_id, [...])` + `entity_type = 'mrf'` cannot use optimal composite |
| `quotations (status, created_at)` | Dashboard pending/approved counts, vendor stats | **No** — only `quotations_vendor_rfq_idx` | Status-filtered counts scan quotation table |
| `s_r_f_s (service_type)` or `(department)` | Logistics scoping `LIKE 'Fleet%'` / `LOWER(department)` | **No** | Leading wildcard / function on column prevents index anyway — needs query rewrite |
| `m_r_f_s (current_stage, status)` | Dashboard `where('status','Pending')->where('current_stage',…)` | Partial via `mrfs_status_stage_created_idx` | Useful if status casing is corrected |
| `payment_schedules.mrf_id` | `findForMrf()` | **Yes** — unique FK (`2026_05_31_100004` L13) | Index exists; N+1 is the problem, not missing index |
| `procurement_documents (mrf_id, type, is_active)` | Vendor portal batch check | **Yes** (L26) | Adequate |

### 2.4 Foreign Keys & Status Fields

- `m_r_f_s.requester_id` → `users.id` — indexed (`mrfs_requester_idx`, `mrfs_requester_created_idx`).
- `m_r_f_s.selected_vendor_id` → `vendors.id` — covered by `mrfs_vendor_workflow_po_signed_idx`.
- `payment_schedules.mrf_id` → `m_r_f_s.id` — unique constraint (one schedule per MRF).
- `procurement_documents.mrf_id` → `m_r_f_s.id` — indexed with `type`, `is_active`.

**Status casing inconsistency:** Dashboard list queries use `'Pending'` (capital P) — e.g. `DashboardController.php` L75, L98, L209. MRF/SRF list endpoints pass through `$request->status` without normalization. If production data uses lowercase workflow states (`pending`, `approved`), dashboard filters and counts will silently miss rows while still incurring scan costs.

---

## 3. Dashboard Aggregate Statistics Evaluation

### 3.1 What is cached today

**Service:** `app/Services/DashboardStatsCache.php`  
**TTL:** 300 seconds (5 minutes)  
**Keys:**

| Cache key | Populated by | Contents |
|-----------|--------------|----------|
| `dashboard.procurement_manager.stats` | `DashboardController::procurementManagerDashboard()` L130–163 | 8 aggregate metrics including JOIN-based on-time delivery |
| `dashboard.supply_chain_director.stats` | `supplyChainDirectorDashboard()` L264–279 | 12 `COUNT()` metrics across vendors, MRFs, RFQs, quotations, SRFs |
| `dashboard.supply_chain_director.metrics` | L281–285 | `AVG(price)`, approved quotation/MRF counts |
| `dashboard.kpis` | `DashboardKpiController::index()` L21–38 | PO generated count, approved MRF/SRF counts, price comparison distinct count |

**Invalidation:** `MRF` and `SRF` model `saved` / `deleted` hooks call `DashboardStatsCache::forgetAll()` (`MRF.php` L35–36; `SRF.php` equivalent). No invalidation on Quotation, Vendor, VendorRegistration, RFQ, or PriceComparison changes — cached stats can be stale up to 5 minutes after those entities change.

### 3.2 What recalculates on every page load

| Dashboard section | Dynamic computation | Cost driver |
|-------------------|---------------------|-------------|
| Procurement manager lists | 4× `limit(N)` queries + quotation eager loads | Moderate — bounded by `listLimit` |
| SCD dashboard lists | SRF approval queue + 20 registrations | Moderate |
| Finance dashboard | 3× paginated lists + 5× `count()` + per-row routing meta | **Severe** |
| Vendor dashboard | Unbounded RFQ/quotation lists + 3 counts | **Severe** for active vendors |
| Recent activities | Up to 4 `pluck()` + activity query | **Severe** for privileged roles |
| Logistics stats | 4× `COUNT(*)` on vehicles | Low–moderate |
| Reports dashboard | Cached via `ReportCache` | Low after first hit |

### 3.3 Execution flow impact

```
Typical finance user page load
├── GET /api/dashboard/finance
│   ├── Query 1: legacy finance MRFs (paginated + eager)
│   ├── Query 2: finance AP MRFs (paginated + eager)
│   ├── Query 3: unified finance MRFs (paginated + eager)
│   ├── Queries 4–8: status/workflow counts
│   └── PHP: mapMrf() × (3 × perPage) rows
├── GET /api/dashboard/recent-activities
│   ├── pluck mrf_ids (possibly thousands)
│   └── Activity whereIn + limit 20
└── GET /api/mrfs?... (table)
    └── Paginated list query
```

A single finance dashboard visit can therefore trigger **10+ database round-trips** before the main data table loads. None of the finance path is cached.

Procurement and SCD dashboards split the cost: aggregate numbers are cached, but the UI still waits on uncached list queries. If the frontend fires dashboard + list + activities in parallel, connection pool contention amplifies latency.

### 3.4 Reports dashboard (reference)

`ReportsDashboardService::dashboard()` (`app/Services/ReportsDashboardService.php` L22–34) caches by date range via `ReportCache`. This path is **not** a primary bottleneck relative to finance/vendor dashboards.

---

## 4. Actionable Refactoring Blueprint (Step-by-Step)

Recommendations are ranked by **performance impact** (High / Medium / Low) and include **effort** (implementation complexity). Each item lists exact files to modify in the execution phase.

---

### 4.1 HIGH Impact

#### H1. Eliminate vendor portal N+1 on payment schedules

**Problem:** `VendorInvoiceGateService::status()` calls `PaymentScheduleService::findForMrf()` per MRF row.

**Fix:** Batch-load payment schedules for paginated MRF IDs before the map loop; pass preloaded schedules into gate evaluation.

**Files to modify:**
- `app/Http/Controllers/Api/VendorPortalMrfController.php` (L58–93)
- `app/Services/FinanceAp/VendorInvoiceGateService.php` (add overload accepting preloaded schedule)
- `app/Services/PaymentScheduleService.php` (add `findForMrfs(array $mrfIds)` batch method)

**Effort:** Medium | **Impact:** High (removes 25–50 queries per vendor portal page)

---

#### H2. Refactor finance dashboard to a single query + cached aggregates

**Problem:** Triple paginated list queries + five uncached counts on every load.

**Fix:**
1. Expose **one** paginated `financeMRFs` list (unified cohort) for the table.
2. Move count aggregates into `DashboardStatsCache` with keys like `dashboard.finance.stats`.
3. Drop redundant `legacyPaginated` / `financeApPaginated` duplicate lists unless UI explicitly needs three tabs.

**Files to modify:**
- `app/Http/Controllers/Api/DashboardController.php` (`financeDashboard()` L424–579)
- `app/Services/DashboardStatsCache.php` (add finance keys to `KEYS` array)
- `app/Services/Finance/FinanceRoutingService.php` (optional: extract count helpers)
- Invalidate cache from MRF workflow transitions (workflow controllers / `MRF` model hooks)

**Effort:** Medium–High | **Impact:** High (reduces ~8 queries to ~2 per finance dashboard load)

---

#### H3. Rewrite PO list scopes for index-friendly predicates

**Problem:** `forPoList()` and `withPoLifecycleStatus()` use `OR` + `LOWER(...) LIKE '%...%'`.

**Fix:**
1. Replace LIKE buckets with explicit `workflow_state` / `status` enum lists (match `WorkflowStateService` constants).
2. Add partial indexes: `(po_draft_saved_at)` WHERE draft; `(signed_po_url)` WHERE NOT NULL.
3. Consider a materialized `po_lifecycle_status` column updated on workflow transitions to avoid runtime OR logic.

**Files to modify:**
- `app/Models/MRF.php` (`scopeForPoList` L61–74, `scopeWithPoLifecycleStatus` L79–111)
- `app/Http/Controllers/Api/MRFController.php` (`index()` PO branch L215–228)
- New migration under `database/migrations/` (indexes + optional `po_lifecycle_status` column)
- Workflow transition services that update MRF state (e.g. `app/Http/Controllers/Api/MRFWorkflowController.php`)

**Effort:** High | **Impact:** High (PO tab is a primary table view)

---

#### H4. Fix `getRecentActivities()` ID harvest pattern

**Problem:** Unbounded `pluck('mrf_id')` before `LIMIT 20`.

**Fix:**
1. Replace ID harvest with a single subquery join: `activities` INNER JOIN role-appropriate MRF scope.
2. Add composite index `activities_entity_type_entity_id_created_at_idx`.
3. For procurement/finance roles, use `exists` subqueries instead of materializing ID arrays.

**Files to modify:**
- `app/Http/Controllers/Api/DashboardController.php` (`getRecentActivities()` L584–679)
- `database/migrations/` (new activities composite index)

**Effort:** Medium | **Impact:** High for admin/finance/procurement roles

---

#### H5. Guard SRF `include_line_items` from default table views

**Problem:** `include_line_items=true` forces `SELECT *` and `presentSrf()` heavy payloads.

**Fix:**
1. Backend: always apply a dedicated `SRF::resolveDetailListSelect()` even with line items; never drop column selection entirely.
2. Split endpoint: `GET /api/srfs/{id}/line-items` for detail panels; list endpoint returns counts only.
3. Frontend (separate repo): ensure table views never pass `include_line_items=1`.

**Files to modify:**
- `app/Http/Controllers/Api/SRFController.php` (`index()` L60–167)
- `app/Models/SRF.php` (new select constant for list+items)
- `frontend_changes.md` / emerald-supply-chain API client (coordination)

**Effort:** Medium | **Impact:** High when flag is misused

---

### 4.2 MEDIUM Impact

#### M1. Remove wasted `requester` eager load from MRF list

**Fix:** Either use eager-loaded `requester` in `toListApiArray()` (structured object like SRF) or remove `->with(['requester:...])` from `index()`.

**Files to modify:**
- `app/Http/Controllers/Api/MRFController.php` (L213)
- `app/Models/MRF.php` (`toListApiArray()` L679)

**Effort:** Low | **Impact:** Medium

---

#### M2. Paginate vendor dashboard RFQ and quotation collections

**Fix:** Replace `->get()` with `->limit($listLimit)->get()` or cursor pagination; keep stats as `count()` queries (optionally cached).

**Files to modify:**
- `app/Http/Controllers/Api/DashboardController.php` (`vendorDashboard()` L348–394)

**Effort:** Low | **Impact:** Medium (grows with vendor activity)

---

#### M3. Fix Finance AP open PO post-pagination filter

**Fix:** Push `remainingBalance > 0` into SQL via subquery on `payment_milestones`, or over-fetch pages until `per_page` satisfied; fix `total` count.

**Files to modify:**
- `app/Services/Finance/FinanceApOpenPurchaseOrderService.php` (L41–82, `formatMrf()` L87+)
- `app/Http/Controllers/Api/FinanceApOpenPurchaseOrderController.php`

**Effort:** Medium | **Impact:** Medium (finance AP integration accuracy + perf)

---

#### M4. Add missing database indexes

**New migration recommended indexes:**
- `m_r_f_s (po_draft_saved_at)` — PO draft list
- `m_r_f_s (finance_ap_status, workflow_state)` — finance AP dashboard counts
- Partial index on `m_r_f_s (signed_po_url)` WHERE `signed_po_url IS NOT NULL`
- `activities (entity_type, entity_id, created_at DESC)`
- `quotations (status, created_at)`

**Files to modify:**
- `database/migrations/` (new migration file)

**Effort:** Low | **Impact:** Medium

---

#### M5. Normalize status casing across dashboard queries

**Fix:** Audit production `status` values; use `whereIn` with both casings or normalize on write; align dashboard filters with list endpoints.

**Files to modify:**
- `app/Http/Controllers/Api/DashboardController.php` (L75, L98, L152–154, L209, L271–272)
- `app/Http/Controllers/Api/DashboardKpiController.php` (L28)
- Potentially seeders / workflow services if normalization on write is chosen

**Effort:** Low–Medium | **Impact:** Medium (correctness + index utilization)

---

#### M6. Paginate user directory

**Fix:** Replace `UserManagementController::index()` `->get()` with `paginate()`.

**Files to modify:**
- `app/Http/Controllers/Api/UserManagementController.php` (L42–59)
- `routes/api.php` (document query params if needed)

**Effort:** Low | **Impact:** Medium as user base grows

---

#### M7. Slim MRF list column set for table views

**Fix:** Split `LIST_API_SELECT` into `LIST_TABLE_SELECT` (minimal) vs `LIST_API_SELECT` (current); omit URL columns from list responses (serve via detail/signing endpoints).

**Files to modify:**
- `app/Models/MRF.php` (`LIST_API_SELECT` L608–621, `resolveListApiSelect()`, `toListApiArray()`)
- `app/Http/Controllers/Api/MRFController.php` (`index()`)

**Effort:** Medium | **Impact:** Medium (payload size)

---

#### M8. Extend cache invalidation beyond MRF/SRF saves

**Fix:** Call `DashboardStatsCache::forgetAll()` (or targeted forget) from Quotation, VendorRegistration, RFQ, PriceComparison model observers.

**Files to modify:**
- `app/Services/DashboardStatsCache.php`
- `app/Models/Quotation.php`, `VendorRegistration.php`, `RFQ.php`, `PriceComparison.php` (observers or boot hooks)

**Effort:** Low | **Impact:** Medium (staleness vs load tradeoff — optional TTL-only if stale stats acceptable)

---

#### M9. Optimize global search

**Fix:** Add selective columns to `SearchController`; consider full-text index (PostgreSQL `tsvector` / MySQL `FULLTEXT`) instead of `LOWER(...) LIKE`.

**Files to modify:**
- `app/Http/Controllers/Api/SearchController.php` (L26–72)
- `database/migrations/` (full-text indexes)

**Effort:** Medium | **Impact:** Medium

---

### 4.3 LOW Impact

#### L1. Remove duplicate `srfs` key in SRF list response

**Files:** `app/Http/Controllers/Api/SRFController.php` (L169–172)

**Effort:** Low | **Impact:** Low (minor serialization savings; breaking change if clients rely on `srfs` key — deprecate first)

---

#### L2. Cache logistics vehicle stats

**Files:**
- `app/Http/Controllers/Api/V1/Logistics/LogisticsDashboardController.php` (L13–43)
- `app/Services/DashboardStatsCache.php`

**Effort:** Low | **Impact:** Low (4 counts on small table)

---

#### L3. Resolve duplicate index migration overlap

**Files:** Review and consolidate `2026_07_01_140000`, `2026_07_02_140000` migrations before adding more `mrfs_po_number_idx` variants.

**Effort:** Low (ops) | **Impact:** Low (deployment safety)

---

#### L4. Inject `MrfParallelFirstApprovalService` once in `toListApiArray()`

**Files:** `app/Models/MRF.php` (L690)

**Effort:** Low | **Impact:** Low

---

### 4.4 Recommended Execution Order

| Phase | Items | Expected outcome |
|-------|-------|------------------|
| **Phase 1 — Quick wins (1–2 days)** | H1, M1, M4, M5, M6 | Remove N+1; add indexes; fix casing; paginate users |
| **Phase 2 — Dashboard relief (3–5 days)** | H2, H4, M2, M8 | Finance + activities dashboards usable under load |
| **Phase 3 — PO & SRF tables (5–8 days)** | H3, H5, M3, M7 | PO tab and SRF tables stable at scale |
| **Phase 4 — Polish** | M9, L1–L4 | Search perf, response cleanup |

---

### 4.5 Verification Checklist (Post-Implementation)

1. Enable Laravel query log or Telescope in staging; record query count for:
   - `GET /api/mrfs` (default and `po_list=1`)
   - `GET /api/srfs` (with and without `include_line_items`)
   - `GET /api/dashboard/finance`
   - `GET /api/dashboard/recent-activities` (admin user)
   - `GET /api/vendor-portal/mrfs`
2. Target: **≤ 3 queries** for standard paginated lists; **≤ 5 queries** for finance dashboard (including counts).
3. Run `EXPLAIN ANALYZE` on PO list, finance cohort, and activities queries before/after index migrations.
4. Measure JSON response sizes: MRF list 100 rows should be < 500 KB after M7.
5. Load-test with realistic row counts (10k+ MRFs, 5k+ SRFs) on staging.

---

## Appendix A — Route Reference

| Method | Path | Controller@method |
|--------|------|-------------------|
| GET | `/api/mrfs` | `MRFController@index` |
| GET | `/api/mrfs?po_list=1` | `MRFController@index` (PO mode) |
| GET | `/api/srfs` | `SRFController@index` |
| GET | `/api/vendor-portal/mrfs` | `VendorPortalMrfController@index` |
| GET | `/api/vendors/{id}/open-purchase-orders` | `FinanceApOpenPurchaseOrderController@index` |
| GET | `/api/dashboard/kpis` | `DashboardKpiController@index` |
| GET | `/api/dashboard/procurement-manager` | `DashboardController@procurementManagerDashboard` |
| GET | `/api/dashboard/supply-chain-director` | `DashboardController@supplyChainDirectorDashboard` |
| GET | `/api/dashboard/finance` | `DashboardController@financeDashboard` |
| GET | `/api/dashboard/vendor` | `DashboardController@vendorDashboard` |
| GET | `/api/dashboard/recent-activities` | `DashboardController@getRecentActivities` |
| GET | `/api/dashboard/logistics-statistics` | `LogisticsDashboardController@stats` |
| GET | `/api/reports/dashboard` | `ReportsDashboardController@index` |

---

## Appendix B — Related Frontend Amplifiers (Cross-Repo)

Documented in `frontend_changes.md` (emerald-supply-chain):

- `fetchAllListPages()` walking multiple API pages for `getAll()` helpers
- Global search prefetching large lists
- 30s polling on dashboard/RFQ views
- Recent frontend mitigation: default `per_page=25`, dashboard uses `per_page=100` single page, `fetchAllListPages` capped at 2 pages

Backend fixes above remain necessary even with frontend caps — the server still does redundant work per request.

---

*End of audit. No application code was modified. Proceed to implementation phase using Section 4 as the prioritized backlog.*
