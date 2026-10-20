# Analytics Clean-Slate — Deployment Runbook

> Follow every step in order. Do not skip steps. If a step fails, stop and investigate
> before proceeding. Record the outcome of each step in a deployment log.

---

## Prerequisites

- [ ] Production database backup confirmed and restoration tested
- [ ] Staging deploy completed and verified
- [ ] Business owner has approved the baseline cutover date/time
- [ ] `FRONTEND_URL` env var set correctly in production backend
- [ ] Artisan commands for new analytics commands are registered (verify with `php artisan list scm`)

---

## Step 1 — Pre-deployment inventory snapshot

Run on production **before** applying migrations:

```bash
php artisan scm:analytics-inventory-snapshot --label=before-deploy
```

Record the output. Verify MRF, SRF, RFQ, PO, vendor, and audit log counts. Save the
`storage/app/analytics-snapshots/before-deploy_*.json` file for comparison.

---

## Step 2 — Deploy code and migrations

```bash
# 1. Pull and build
git pull origin main
npm run build  # or your CI build command

# 2. Maintenance mode
php artisan down

# 3. Run migrations (all additive — no data destructed)
php artisan migrate --force

# Migrations applied:
#   2026_10_19_100000_add_analytics_baseline_settings.php
#     → Seeds analytics_baseline sentinel row in system_settings
#   2026_10_19_100001_add_analytics_classification_to_mrfs.php
#     → Adds nullable analytics_class + reconciliation columns to m_r_f_s
#   2026_10_19_100002_create_procurement_reminder_dispatches_table.php
#     → Creates procurement_reminder_dispatches deduplication table

# 4. Bring up
php artisan up
```

---

## Step 3 — Post-migration inventory snapshot

```bash
php artisan scm:analytics-inventory-snapshot --label=after-deploy
```

Compare `total records` with the `before-deploy` snapshot. All counts must be **identical**
(no deletions). The `procurement_reminder_dispatches` table starts at 0. The
`mrf_analytics_class_dist` field will show `column_not_present: true` in before-deploy
and a distribution in after-deploy — that is expected and correct.

If any business entity count is lower in the after-deploy snapshot, **stop and investigate
before proceeding**.

---

## Step 4 — Establish the analytics baseline (run exactly once)

```bash
php artisan scm:establish-analytics-baseline \
  --at="YYYY-MM-DDTHH:MM:SS+01:00" \
  --timezone="Africa/Lagos" \
  --version="$(git rev-parse --short HEAD)"
```

Replace `YYYY-MM-DDTHH:MM:SS` with the **approved cutover datetime**.

The command will ask for confirmation. Type `yes`. It will record the timestamp, actor,
and version in `system_settings` and write to `audit_logs`. Running it a second time
without `--force` will fail — that is the expected safeguard.

Verify:

```bash
# Should display the configured effective_at
php artisan tinker --execute="echo \App\Support\AnalyticsBaseline::effectiveAt();"
```

---

## Step 5 — Verify application

1. Log in to SCM as Supply Chain Director.
2. Open the Supply Chain command centre.
3. Confirm:
   - Health section shows "Measuring performance from: [baseline date]" label.
   - "Key Numbers" card title reads **"Waiting on you"** (not "Awaiting approval").
   - "Where work is sitting" pipeline description includes org-wide MRF count.
   - Clicking a pipeline stage opens the matching filter (not always "open").
   - If no verified deliveries, delivery performance shows "Insufficient data".
4. Log in as Admin.
5. Open Settings → Analytics tab.
6. Confirm legacy records are listed and can be classified.

---

## Step 6 — Verify reminders (dry run)

```bash
php artisan scm:send-approval-reminders --dry-run
```

Review output:
- Each MRF should show a **specific named recipient** (not a broad role list).
- `ASSIGNMENT_GAP` warnings indicate MRFs with no identifiable assignee — investigate these.
- `DEDUP_SKIP` lines indicate correctly handled duplicates.

Do **not** run without `--dry-run` until the above output looks correct.

---

## Step 7 — Confirm analytics API

```bash
curl -H "Authorization: Bearer <token>" \
     "https://scm.emeraldcfze.com/api/analytics/baseline"
# Should return { success: true, baseline: { established: true, effective_at: "..." } }

curl -H "Authorization: Bearer <token>" \
     "https://scm.emeraldcfze.com/api/analytics/kpis"
# Should return { success: true, data: { baseline: {...}, sla_constants: {...}, ... } }
```

---

## Rollback procedure

If critical issues are found:

1. **Code rollback:** redeploy the previous release tag.
2. **Migrations:**
   - `analytics_class` column is nullable — all existing code ignores it safely.
   - `procurement_reminder_dispatches` table is standalone — no FK risk.
   - `system_settings` rows can be removed without affecting other features.
3. **Disable reminder improvement:** add `--dry-run` to the scheduler temporarily:
   ```
   $schedule->command('scm:send-approval-reminders --dry-run')->hourly()
   ```
4. The analytics baseline setting persists through rollback — it does not affect
   any business data. It can be safely left in `system_settings` during rollback.

No business records are modified by this deployment. Rollback is low risk.
