# Developer Handover — Clean-Slate SCM Analytics

## 1. Audit findings and metric definitions

See [`docs/ANALYTICS_AUDIT.md`](ANALYTICS_AUDIT.md) for:
- The awaiting-approval dual-metric root cause and fix
- Full metric definitions (population, timestamp, treatment, SLA, colour thresholds)
- Frontend broken navigation inventory
- SLA constants now moved to backend config payload

---

## 2. Backend files changed / created

| File | Type | Purpose |
|---|---|---|
| `database/migrations/2026_10_19_100000_add_analytics_baseline_settings.php` | Migration (additive) | Seeds analytics_baseline sentinel in system_settings |
| `database/migrations/2026_10_19_100001_add_analytics_classification_to_mrfs.php` | Migration (additive) | Adds analytics_class + reconciliation columns to m_r_f_s |
| `database/migrations/2026_10_19_100002_create_procurement_reminder_dispatches_table.php` | Migration (new table) | Reminder deduplication log |
| `app/Support/AnalyticsBaseline.php` | New | Read-only access to baseline config from system_settings |
| `app/Console/Commands/EstablishAnalyticsBaseline.php` | New | One-time baseline setup command |
| `app/Console/Commands/AnalyticsInventorySnapshot.php` | New | Pre/post deploy record count snapshot |
| `app/Services/Analytics/AnalyticsClassificationService.php` | New | Classification scopes for KPI queries (not operational queues) |
| `app/Services/Analytics/ScmKpiService.php` | New | Unified KPI calculations with documented eligibility rules |
| `app/Services/Analytics/ReminderRecipientResolver.php` | New | Assignment-aware reminder recipient lookup |
| `app/Http/Controllers/Api/AnalyticsController.php` | New | Analytics API (baseline, KPIs, legacy list, classify) |
| `app/Models/ProcurementReminderDispatch.php` | New | Dedup dispatch model |
| `app/Notifications/ProcurementActionReminderNotification.php` | Modified | Descriptive subject, record deep links, action_url in toArray |
| `app/Console/Commands/SendProcurementApprovalReminders.php` | Modified | Assignment-aware resolver + dedup; keeps 24h/hourly cadence |
| `routes/api.php` | Modified | Added /analytics/* routes |
| `docs/ANALYTICS_AUDIT.md` | New | Audit findings |
| `docs/PROPOSED_REMINDER_SLAS.md` | New | Proposed (not activated) SLA escalation rules |
| `docs/ANALYTICS_DEPLOY_RUNBOOK.md` | New | Deployment procedure |
| `tests/Feature/AnalyticsBaselineTest.php` | New | Baseline command + config tests |
| `tests/Feature/ScmKpiServiceTest.php` | New | KPI eligibility + API endpoint tests |
| `tests/Feature/ProcurementReminderTest.php` | New | Recipient resolution, dedup, deep link tests |

---

## 3. Frontend files changed / created

| File | Type | Purpose |
|---|---|---|
| `src/services/api.ts` | Modified | Added analyticsApi + ScmKpi types; added notificationApi.deleteAll |
| `src/hooks/useScmKpis.ts` | New | React Query hook for KPI payload + baseline |
| `src/components/supplychain/SupplyChainCommandCentre.tsx` | Modified | Relabelled metrics, backend KPIs, fixed drills, baseline label, carry-over badge, insufficient data |
| `src/components/analytics/ReconciliationPanel.tsx` | New | Authorised legacy classification UI |
| `src/pages/Settings.tsx` | Modified | Added Analytics tab for admin/SCD |
| `src/pages/Auth.tsx` | Modified | returnTo query param for email deep-link post-login redirect |
| `src/pages/details/MRFDetailPage.tsx` | Modified | Reads ?tab=po&action=sign from email CTA; shows sign toast |
| `src/App.tsx` | Modified | ProtectedRoute preserves returnTo for email deep links |

---

## 4. Database migration details

All three migrations are **additive and reversible**:

| Migration | Tables touched | Rows changed | Reversible |
|---|---|---|---|
| `_add_analytics_baseline_settings` | `system_settings` | Inserts 1 sentinel row | Yes (`down()` deletes category=analytics_baseline) |
| `_add_analytics_classification_to_mrfs` | `m_r_f_s` | 0 (adds nullable columns) | Yes (drops columns) |
| `_create_procurement_reminder_dispatches` | Creates new table | 0 | Yes (drops table) |

Pre/post snapshot: run `php artisan scm:analytics-inventory-snapshot` before and after deploy, then compare the output files in `storage/app/analytics-snapshots/`.

---

## 5. Baseline effective timestamp

Established at production cutover by:
```bash
php artisan scm:establish-analytics-baseline --at="..." --timezone="Africa/Lagos"
```

Query after deployment:
```sql
SELECT * FROM system_settings WHERE category = 'analytics_baseline';
```

Verify in application:
```php
\App\Support\AnalyticsBaseline::config();
```

---

## 6. Reminder rules active (Phase 1, 2A)

| Setting | Value |
|---|---|
| Schedule | Hourly |
| Stale threshold | 24 hours (`--hours=24`) |
| Deduplication | One reminder per (user, record, state) per calendar day |
| Recipient strategy | `ReminderRecipientResolver` — specific assignee, not role broadcast |
| Assignment gap behaviour | Log to `procurement_reminder_dispatches`, skip notification |
| Deep link format | `{FRONTEND_URL}/mrfs/{mrf_id}` or `?tab=po&action=sign` for PO states |

Proposed new SLA/escalation rules: see [`docs/PROPOSED_REMINDER_SLAS.md`](PROPOSED_REMINDER_SLAS.md).
**Not activated.** Requires business-owner approval before implementation.

---

## 7. Dashboard indicators made actionable

| Indicator | Before | After |
|---|---|---|
| "Awaiting approval" Key Numbers (SCD) | Drilled org pending_approval MRFs | Scrolls to personal approval workspace |
| "Awaiting approval" label | Ambiguous — used for both personal queue and org pipeline stage | Relabelled: "Waiting on you" (personal) + "MRFs awaiting approval org-wide" (pipeline) |
| PipelineBoard stage click (SCD) | Always opened generic "open" bucket | Routes to stage-specific filtered bucket |
| DeliveryBreakdown click (SCD) | Always opened overdue_delivery | Routes to selected bucket |
| Vendor performance table (SCD) | Static (no click) | Click navigates to `/vendors?name=...` |
| Delivery performance (SCD) | Showed "100% on time" when 0 deliveries | Shows "Insufficient data" panel when no eligible records |
| Email action button (reminder) | Opened `/procurement` generic list | Opens `/mrfs/{mrf_id}` or `/mrfs/{mrf_id}?tab=po&action=sign` |

---

## 8. Test results

Run:
```bash
php artisan test tests/Feature/AnalyticsBaselineTest.php tests/Feature/ScmKpiServiceTest.php tests/Feature/ProcurementReminderTest.php
```

Tests cover:
- Baseline set-once guard (refuses second run without `--force`)
- Unreconciled legacy MRFs remain in operational counts (queues not affected)
- Performance metrics exclude pre-baseline records
- Insufficient data flag when no eligible deliveries
- Recipient resolver — parallel approval, role assignment, direct assignee field
- Deduplication within and across period keys
- Deep link format (`/mrfs/{id}`, `/mrfs/{id}?tab=po&action=sign`)
- Reminder dry-run completes without sending

---

## 9. Outstanding decisions and limitations

| Item | Status | Owner |
|---|---|---|
| New SLA/escalation intervals for MRF, SRF, PO reminders | Documented in `PROPOSED_REMINDER_SLAS.md` — not activated | Business owner approval required |
| `org-wide MRF count > 200` analytics list cap | Frontend analytics still uses `per_page: 200` for local pipeline calculations; backend KPI API has no cap | Future: migrate all local aggregations to backend ScmKpiService |
| SRF/trip/finance reminders | MRF reminders only in Phase 1 | Extend `ReminderRecipientResolver` for SRF/trip once assignment fields are confirmed |
| Executive/Chairman/Finance/Logistics command centre KPI wiring | Only SCD `SupplyChainCommandCentre` wired to backend KPIs in Phase 1 | Apply same `useScmKpis` hook pattern to other command centres |
| Delivery on-time uses `delivery_confirmed_at` field | Field must be populated at delivery confirmation step | Verify workflow populates this field; add to delivery confirmation action if missing |
