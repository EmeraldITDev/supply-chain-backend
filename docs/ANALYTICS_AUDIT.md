# SCM Analytics Audit — Findings & Metric Definitions

> Produced as part of the Clean-Slate Analytics & Dashboard Integrity improvement.
> Covers the Supply Chain Director, Executive, Procurement Manager, Chairman,
> Finance, Employee and Logistics dashboards.

---

## 1. Architecture summary

Analytics on the command-centre surfaces are **mostly derived on the frontend** from MRF/SRF/vendor lists fetched from the backend. The backend supplies role-specific approval queues, period stats and platform KPIs. There is no dedicated analytics-baseline configuration yet; reporting periods use a rolling previous-window comparison.

Key frontend helpers:
- `emerald-supply-chain/src/utils/procurementIntelligence.ts` — period ranges, pipeline stages, delivery stats, health, activity series
- `emerald-supply-chain/src/components/supplychain/scdCommandCentre.ts` — SCD personal queue builder
- `emerald-supply-chain/src/utils/executiveIntelligence.ts` — shared MRF classifiers

---

## 2. "Awaiting approval" — the primary discrepancy (17 vs 8)

Two completely different metrics appear under the same label on the Supply Chain Director dashboard.

### Path A — Key Numbers card: `queue.length`
- **Source**: `buildScdApprovalQueue` in `scdCommandCentre.ts`
- **Entity types**: MRFs + SRFs + trip requests + vendor registrations + unsigned POs
- **Scope**: **Personal** — items waiting on **this specific user** (Supply Chain Director)
- **Data cap**: role-pending APIs (50 MRFs from `pending_for_role=scd`) plus separate SRF/trip/vendor lists
- **Label**: "Everything currently waiting on you"

### Path B — "Where work is sitting" pipeline stage: `buildPipeline` awaiting_approval count
- **Source**: `buildPipeline` in `procurementIntelligence.ts`
- **Entity types**: **MRFs only**
- **Scope**: **Org-wide** — all live MRFs whose `workflow_state` matches a loose regex (`/review|approval|submitted|pending/`)
- **Data cap**: `mrfApi.list({ per_page: 200 })` — can undercount if >200 active MRFs
- **Label**: "Awaiting approval" (pipeline stage name)

### Resolution
These two figures legitimately measure different things. The fix is:
1. Rename the Key Numbers card to **"Waiting on you"** to make its personal scope unambiguous.
2. Rename the pipeline stage label to **"MRFs awaiting approval"** to clarify org-wide MRF scope.
3. Fix the click drill-down on Key Numbers so it opens the user's personal action queue (not the org MRF bucket).
4. Fix `PipelineBoard.onSelect` to route to the clicked stage key, not always `open`.

---

## 3. Dashboard metric definitions

### 3.1 Requests raised
| Attribute | Value |
|---|---|
| Business meaning | How many material requests were initiated in the period |
| Source entity | MRF (`m_r_f_s`) |
| Timestamp | `created_at` |
| Eligible statuses | All (not rejected/cancelled for volume; rejections included for performance) |
| Reporting period | Selected frontend period (7d/30d/3m/6m/YTD/custom) |
| Legacy / baseline treatment | **Post-baseline only** for new-period count; pre-baseline records excluded from "new period" count |
| SLA / threshold | None for count |
| Health colour | n/a |
| Inconsistency | None |

### 3.2 Awaiting approval (Key Numbers)
| Attribute | Value |
|---|---|
| Business meaning | Items personally queued for the current SCD user |
| Source entity | MRF + SRF + trip + vendor registration + unsigned PO |
| Timestamp | n/a |
| Eligible statuses | Active, non-rejected, non-completed items in SCD-pending states |
| Reporting period | Real-time (no date filter) |
| Legacy / baseline treatment | **Operational — all classes** (legacy carry-over stays visible) |
| SLA / threshold | >0 → warning tone |
| Health colour | Yellow if >0 |
| Inconsistency | **Label clash with pipeline stage** — see §2 |

### 3.3 MRFs awaiting approval (pipeline stage)
| Attribute | Value |
|---|---|
| Business meaning | How many org-wide MRFs are in an approval-like workflow state |
| Source entity | MRF only |
| Timestamp | n/a (state snapshot) |
| Eligible statuses | `workflow_state` matches `/review|approval|submitted|pending/` AND not approved/completed |
| Reporting period | Real-time (no date filter) |
| Legacy / baseline treatment | **Operational — all classes** |
| SLA / threshold | n/a |
| Health colour | n/a |
| Inconsistency | **Shares "Awaiting approval" label** with Key Numbers card — see §2 |

### 3.4 Purchase commitments
| Attribute | Value |
|---|---|
| Business meaning | Estimated total value of requests raised in the period |
| Source entity | MRF (`po_value` / `poValue` / `estimated_cost`) |
| Timestamp | `created_at` (for period filter) |
| Eligible statuses | All (not rejected/cancelled) |
| Reporting period | Selected frontend period |
| Legacy / baseline treatment | Post-baseline only for new-period spend |
| SLA / threshold | None |

### 3.5 Procurement workflow health
| Attribute | Value |
|---|---|
| Business meaning | Whether overdue/stalled requests indicate a critical procurement bottleneck |
| Source entity | MRF |
| Calculation | `overdue > 5 → critical`; `overdue > 0 → attention`; else `healthy` |
| "Overdue" definition | Active MRF with no delivery and pending SLA exceeded (21 days default) OR flagged overdue |
| Legacy / baseline treatment | When overdue count is driven primarily by unreconciled pre-baseline MRFs, health tooltip should distinguish historical data limitation from current period performance |
| SLA | `STALL_DAYS = 7`, `DELIVERY_SLA_DAYS = 21`, `APPROVAL_SLA_DAYS = 3` (hardcoded in `procurementIntelligence.ts`) |

### 3.6 Approval performance
| Attribute | Value |
|---|---|
| Business meaning | Average days to approve an MRF |
| Source entity | MRF (stage timestamps: `procurement_review_started_at`, `director_approved_at`, `executive_approved_at`) |
| Calculation | Mean days from stage entry → approval for completed approvals in period |
| Eligible | Completed approvals with both entry and exit timestamps |
| Legacy / baseline treatment | **Exclude unreconciled_legacy from average** (missing timestamps → exclude from denominator) |
| SLA | `APPROVAL_SLA_DAYS = 3` |
| Insufficient data | Show "Insufficient data" if <3 eligible approvals in period |

### 3.7 Delivery performance / on-time %
| Attribute | Value |
|---|---|
| Business meaning | % of deliveries received by promised date |
| Source entity | MRF (delivery status + `expected_delivery_date` + delivery timestamps) |
| Calculation | `on_time / (on_time + late + overdue)` × 100 |
| Eligible | MRFs with a `expected_delivery_date` AND a verified delivery event |
| Legacy / baseline treatment | Exclude MRFs without verified delivery dates from denominator |
| SLA | `DELIVERY_SLA_DAYS = 21` |
| Insufficient data | Show "Insufficient data" if no eligible deliveries |
| Inconsistency | "100% on time" displayed when there are 0 eligible deliveries (should show insufficient data) |

### 3.8 Vendor performance
| Attribute | Value |
|---|---|
| Business meaning | How reliably vendors deliver on PO commitments |
| Source entity | Vendor (performance object from API) / MRF GRN records |
| Calculation | Backend `VendorPerformanceMetrics` when available; fallback local GRN heuristics |
| Eligible | Vendors with at least one completed PO with a verified delivery date |
| Legacy / baseline treatment | Exclude pre-baseline GRN records from new-period avg |
| Insufficient data | **"No ratings" text** when no performance data — correct label, not "Healthy" |

### 3.9 Open workload
| Attribute | Value |
|---|---|
| Business meaning | Total outstanding procurement items across all stages |
| Source entity | MRF (active, non-completed, non-rejected) |
| Calculation | Count of live MRFs |
| Legacy / baseline treatment | **Operational — all classes** — legacy carry-over always included; badge as "carry-over" |
| Threshold | `> 40 → attention` (hardcoded) |

---

## 4. Existing reminder system gaps

| Gap | Current state | Fix |
|---|---|---|
| Role-wide recipients | `recipientsFor()` uses `whereIn('supply_chain_role', roles)` — notifies all users with a role | Use `ReminderRecipientResolver` for assignment-aware lookup |
| No dedup | Hourly re-sends while pending | `procurement_reminder_dispatches` dispatch log keyed by `(user_id, subject_type, subject_id, workflow_state, period_key)` |
| Generic deep link | Hardcoded `https://scm.emeraldcfze.com/procurement` | Record-specific `config('app.frontend_url') . '/mrfs/' . $mrf->mrf_id` |
| No SRF/trip/PO reminders | Only MRFs in existing command | Extend resolver to SRF/trip states |
| No stale threshold on SLA | `updated_at <= now() - hours` not calendar-day SLA | Keep 24h for now; document calendar-day SLA proposal |
| Cap 200 MRFs | Hard limit in command | Keep for Phase 1; plan for pagination in future |

---

## 5. Frontend broken navigation

| Dashboard element | Current click | Expected click |
|---|---|---|
| `PipelineBoard.onSelect` (SCD) | Always `openDrill("open")` — ignores stage | Route to matching stage filter |
| `DeliveryBreakdown.onSelect` (SCD) | Always `openDrill("overdue_delivery")` | Route to selected bucket |
| Key Numbers "Awaiting approval" click | `openDrill("pending_approval")` (org MRFs) | Route to personal action queue |
| Vendor performance table (SCD) | Static — no click handler | Navigate to `/vendors?filter=...` |
| Activity chart | Static | n/a (informational only) |
| Health overview cards | Tooltip only | Expose clickable action summary |

---

## 6. SLA constants (currently hardcoded in frontend)

```typescript
// procurementIntelligence.ts
APPROVAL_SLA_DAYS = 3
DELIVERY_SLA_DAYS = 21
STALL_DAYS = 7
HIGH_VALUE = 5_000_000

// executiveApprovalIntel.ts
EXEC_APPROVAL_SLA_DAYS = 2
```

These are client-side only. The new KPI API exposes them from the backend so all dashboards use a single source of truth. No values changed — they move to `config/scm.php` readable from backend and returned in KPI payload.

---

## 7. Org list cap (200 records)

Frontend analytics queries `mrfApi.list({ per_page: 200 })`. If >200 active MRFs exist, pipeline stages, health calculations and delivery stats will silently undercount. The new `ScmKpiService` runs aggregate queries server-side to remove this cap.

---

## 8. Files changed by this implementation

### Backend (new / modified)
- `database/migrations/2026_10_19_100000_add_analytics_fields_to_m_r_f_s.php` — classification columns
- `database/migrations/2026_10_19_100001_create_procurement_reminder_dispatches_table.php` — dedup log
- `app/Services/Analytics/ScmKpiService.php` — unified KPI calculation
- `app/Services/Analytics/AnalyticsClassificationService.php` — classification rules
- `app/Services/Analytics/ReminderRecipientResolver.php` — assignment-aware recipient lookup
- `app/Http/Controllers/Api/AnalyticsController.php` — KPI + baseline API
- `app/Models/ProcurementReminderDispatch.php` — dedup model
- `app/Console/Commands/EstablishAnalyticsBaseline.php` — one-time setup
- `app/Console/Commands/AnalyticsInventorySnapshot.php` — pre/post deploy integrity check
- `app/Notifications/ProcurementActionReminderNotification.php` — deep links, descriptive subjects
- `app/Console/Commands/SendProcurementApprovalReminders.php` — assignment-aware recipients + dedup
- `docs/PROPOSED_REMINDER_SLAS.md` — proposed (not activated) SLA escalation rules

### Frontend (new / modified)
- `src/services/analyticsApi.ts` — KPI API client
- `src/hooks/useScmKpis.ts` — React Query hook
- `src/components/supplychain/SupplyChainCommandCentre.tsx` — relabelled metrics, fixed drills, baseline date
- `src/components/analytics/ReconciliationPanel.tsx` — legacy classification UI
- `src/pages/Settings.tsx` — reconciliation tab (admin/SCD)

### Deploy
- `docs/ANALYTICS_DEPLOY_RUNBOOK.md`
- `docs/HANDOVER.md`
