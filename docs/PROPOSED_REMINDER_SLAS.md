# Proposed SCM Reminder SLA Rules

> **Status: PROPOSED — NOT ACTIVATED**
>
> These SLA defaults require review and explicit approval from the relevant
> business owners before implementation. No new reminder intervals or
> escalation triggers are active in Phase 1 of the Clean-Slate Analytics
> improvement. The system currently uses the existing 24-hour stale threshold
> and hourly check schedule.

---

## Current behaviour (Phase 1, 2A baseline)

| Setting | Value |
|---|---|
| Check cadence | Hourly (via Artisan scheduler) |
| Stale threshold | 24 hours since `updated_at` |
| Deduplication | One reminder per (user, record, state) per calendar day |
| Recipients | Specific assignee resolved by `ReminderRecipientResolver` |
| Assignment gap | Logged to `procurement_reminder_dispatches`; no broadcast |

---

## Proposed rules (pending business-owner approval)

### MRF Approval Reminders

| Stage | Responsible role | Proposed first reminder | Proposed repeat | Proposed escalation trigger |
|---|---|---|---|---|
| `parallel_first_approval` | SCD + Executive (pending side) | 24h after entry | Every 24h | After 72h: notify senior admin |
| `supply_chain_director_review` | Supply Chain Director | 24h after entry | Every 24h | After 48h: notify executive |
| `executive_review` | Executive | 24h after entry | Every 24h | After 48h: notify SCD/chairman |
| `lazarus_director_approval` | Director | 24h after entry | Every 24h | After 72h: notify admin |
| `procurement_review` | Assigned procurement manager | 24h after entry | Every 48h | After 5 days: notify SCD |
| `vendor_selection_pending` | Procurement manager | 48h after entry | Every 48h | After 5 days: notify SCD |
| `pending_scd_signature` | Supply Chain Director | 4h after generation | Every 24h | After 48h: notify executive |
| `po_generated` | Supply Chain Director | 4h after generation | Every 24h | After 48h: notify executive |
| `finance_handoff_pending` | Finance owner | 24h after entry | Every 24h | After 48h: notify SCD |
| `finance_in_review` | Finance owner | 48h after entry | Every 48h | After 5 days: notify SCD |

### SRF Reminders
SRF-specific reminder rules mirror MRF approval stages. Proposed implementation: extend `ReminderRecipientResolver` to handle `SRF` entities and add SRF states to `SendProcurementApprovalReminders`.

### Logistics Trip Reminders
- **Vendor driver/vehicle details**: remind assigned vendor 24h before trip departure if details not submitted.
- **Logistics coordinator report**: remind coordinator 2h before report deadline.
- These are handled by the existing fleet notification infrastructure (`logistics_fleet_notification_dispatches`).

### Escalation Design
Escalation requires a separate `scm:escalate-stale-reminders` command with a configurable escalation matrix. Proposed escalation recipients are additional CC contacts, not replacements for the primary assignee. Business-owner approval required before activation.

---

## Implementation notes

1. Activate by adding `--hours=N` scheduling variants in `bootstrap/app.php`.
2. Calendar-day SLA enforcement requires tracking `stage_entered_at` per workflow state (not yet in schema); propose adding `state_entered_at` timestamp column to `m_r_f_s`.
3. `period_key` in `procurement_reminder_dispatches` is currently `YYYY-MM-DD`. For sub-day repeat schedules, change to `YYYY-MM-DD-HH` windowed keys.
4. All escalation requires additional `assignment_escalation_target` logic in `ReminderRecipientResolver`.

---

## Approval required from
- Supply Chain Director: MRF approval stage SLAs
- Executive: Executive review SLA and escalation triggers
- Finance Owner: Finance handoff SLA
- Logistics Manager: Trip vendor and report reminder timing

Record the approval date and approver name here before activating any rule.
