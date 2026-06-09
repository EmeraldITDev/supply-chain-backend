# Batch 3 — SMS / Termii Integration Contract

**Status:** Documentation only (no implementation in this batch).  
**Purpose:** Lock the backend contract and ops notes so SMS can ship independently; frontend wires surfaces in a later batch without re-litigating shape.

---

## Scope

| In scope | Out of scope |
|----------|--------------|
| API contract, data model, queue job, env vars, trigger catalogue | Frontend code, SPA env vars, UI components |
| Ops notes (Termii console, worker queue) | Bulk / marketing SMS, OTP, two-way replies |
| Future UI surfaces (documented for reference) | Replacing email or in-app notifications |

SMS is sent **in addition to** existing email + in-app channels, never instead of.

---

## Provider

- **Termii** — [https://termii.com](https://termii.com)
- Nigerian carriers, alphanumeric sender ID support, delivery receipts (DLR) via webhook.
- Account: shared Oando ops Termii workspace.
- API keys: one per environment (staging / production).

### Termii outbound API (reference)

| Item | Value |
|------|-------|
| Default base URL | `https://api.ng.termii.com/api` |
| Send endpoint | `POST {TERMII_BASE_URL}/sms/send` |
| Auth | `api_key` in JSON body |
| Phone format to Termii | International digits **without** leading `+` (e.g. `2348012345678`). SCM stores E.164 (`+234…`); strip `+` at send time. |
| Sender ID | Alphanumeric, 3–11 chars, pre-registered in Termii console |
| Channel | `dnd` for transactional (recommended for ERP alerts); account must have DND route enabled |

### Termii DLR webhook (reference)

| Item | Value |
|------|-------|
| Method | `POST`, `Content-Type: application/json` |
| Signature header | `X-Termii-Signature` — HMAC-SHA512 of raw request body, keyed with `TERMII_DLR_SECRET` |
| Console setup | Register webhook URL in Termii developer console → Events & Reports |

**DLR payload fields (subset):**

| Field | Description |
|-------|-------------|
| `message_id` | Termii message ID — maps to `sms_logs.termii_message_id` |
| `receiver` | Destination number |
| `status` | e.g. `DELIVERED`, `Message Failed`, `DND Active on Phone Number`, expired / validity failures |
| `cost` | Per-message charge (string/decimal from Termii) |
| `sent_at` | Send timestamp |

**Status mapping → `sms_logs.status`:**

| Termii `status` (case-insensitive match) | `sms_logs.status` |
|------------------------------------------|-------------------|
| `DELIVERED` | `delivered` |
| Contains `expired` / validity failure | `expired` |
| `Message Failed`, `DND Active on Phone Number`, other failures | `failed` |

On `delivered`: set `delivered_at` from DLR `sent_at` (or webhook receipt time if absent). On `failed` / `expired`: set `failed_at`, populate `error` with raw Termii status text.

---

## API Endpoints (backend — to implement)

All paths are under the existing `/api` prefix. Webhook route is **unauthenticated** (signature-verified only).

### `POST /api/notifications/sms/send`

**Purpose:** Enqueue a single SMS. Internal use only — called from `NotificationService` / dispatchers, **never from the browser**.

**Auth:** `auth:sanctum` + middleware restricting to system/internal callers (e.g. service token or `admin` for manual ops). SPA users must not have access.

**Request body:**

```json
{
  "to": "+2348012345678",
  "message": "Oando ERP: MRF MRF-2026-001 needs your approval. Open the portal to review.",
  "trigger": "mrf.approval_required",
  "entity_type": "mrf",
  "entity_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

| Field | Type | Rules |
|-------|------|-------|
| `to` | string | Required. E.164 (`+` + country code + subscriber). |
| `message` | string | Required. Max **459** characters (3× GSM-7 segments). |
| `trigger` | string | Required. Enum — see [Triggers](#triggers-initial-set). |
| `entity_type` | string | Optional. Polymorphic type key (e.g. `mrf`, `po`, `rfq`, `trip`). |
| `entity_id` | string | Optional. UUID or business id of related entity. |

**Response `201`:**

```json
{
  "success": true,
  "data": {
    "queued": true,
    "log_id": "7c9e6679-7425-40de-944b-e07fc1f90ae7"
  }
}
```

**Behaviour:**

1. Validate body; normalise `to` to E.164.
2. Insert `sms_logs` row with `status: queued`, `queued_at: now()`.
3. Dispatch `App\Jobs\SendTermiiSms` on connection `redis`, queue `sms`.
4. Return `log_id`.

**Preferred call path:** `App\Services\Sms\SmsDispatchService::enqueue(...)` invoked directly from notification code. The HTTP route exists for parity with logistics internal notification pattern and admin resend tooling.

---

### `POST /api/webhooks/termii/dlr`

**Purpose:** Termii delivery-receipt webhook. Updates log `status`, `delivered_at` / `failed_at`, `cost`, `error`.

**Auth:** None. Verify `X-Termii-Signature` against raw body using `TERMII_DLR_SECRET`. Return `401` on mismatch.

**Route registration:** Place alongside existing public webhooks (e.g. `POST /api/webhooks/finance-ap`), **outside** `auth:sanctum` group.

**Response `200`:**

```json
{
  "success": true
}
```

**Idempotency:** If `message_id` already maps to a terminal status (`delivered`, `failed`, `expired`), acknowledge without duplicate updates.

---

### `GET /api/notifications/sms/logs`

**Purpose:** Paginated SMS log feed for future Admin UI.

**Auth:** `auth:sanctum` + `role:admin,supply_chain_director`

**Query parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `page` | int | Default `1` |
| `per_page` | int | Default `20`, max `100` |
| `trigger` | string | Filter by trigger key |
| `status` | string | `queued` \| `sent` \| `delivered` \| `failed` \| `expired` |
| `to` | string | Partial E.164 match |
| `date_from` | date | Inclusive (`YYYY-MM-DD`) on `created_at` |
| `date_to` | date | Inclusive end of day |

**Response `200`:**

```json
{
  "success": true,
  "data": [
    {
      "id": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
      "to": "+2348012345678",
      "message": "Oando ERP: …",
      "trigger": "mrf.approval_required",
      "entity_type": "mrf",
      "entity_id": "550e8400-e29b-41d4-a716-446655440000",
      "status": "delivered",
      "termii_message_id": "3905204342778053556",
      "error": null,
      "cost": "2.50",
      "queued_at": "2026-06-09T10:00:00+00:00",
      "sent_at": "2026-06-09T10:00:02+00:00",
      "delivered_at": "2026-06-09T10:00:15+00:00",
      "failed_at": null,
      "created_at": "2026-06-09T10:00:00+00:00"
    }
  ],
  "pagination": {
    "total": 142,
    "per_page": 20,
    "current_page": 1,
    "last_page": 8,
    "from": 1,
    "to": 20
  }
}
```

**Future:** `POST /api/notifications/sms/logs/{id}/resend` (admin only, `failed` rows) — not in Batch 3; reserved for Admin UI.

---

## Environment Variables (backend only)

Add to backend `.env` / `.env.example` at implementation time. **Do not** add to the SPA.

| Variable | Description |
|----------|-------------|
| `TERMII_API_KEY` | Per-environment API key from Termii dashboard |
| `TERMII_SENDER_ID` | Alphanumeric sender ID, max 11 chars (e.g. `OANDO`). Must be pre-registered. |
| `TERMII_BASE_URL` | Default `https://api.ng.termii.com/api` |
| `TERMII_DLR_SECRET` | Shared secret for DLR webhook HMAC verification |
| `SMS_ENABLED` | Feature flag. When `false`, job logs payload and short-circuits without calling Termii |

**Config file:** `config/sms.php` (mirror `config/finance_ap.php` pattern).

---

## Queue

| Item | Value |
|------|-------|
| Connection | `redis` |
| Queue name | `sms` |
| Job class | `App\Jobs\SendTermiiSms` |
| Retries | `3` |
| Backoff | Exponential: `10`, `30`, `90` seconds (`public array $backoff = [10, 30, 90]`) |
| Failed jobs | Laravel `failed_jobs` table with original payload |

**Worker command (production):**

```bash
php artisan queue:work redis --queue=sms --tries=3
```

Run alongside existing default / logistics workers.

### `SendTermiiSms` job flow

```
1. Load sms_logs row by id (from job payload)
2. If SMS_ENABLED=false → log info, set status=failed OR leave queued with note; return (implementation: log + skip API, do not retry)
3. Validate to is E.164; else status=failed, error=no_phone, return (no retry)
4. POST Termii /sms/send with api_key, to (no +), from, sms, channel=dnd, type=plain
5. On success → status=sent, sent_at=now(), termii_message_id from response
6. On HTTP/Termii error → throw to trigger retry; after final failure → status=failed, error=message, failed_at=now()
```

---

## Data Model — `sms_logs`

**Migration:** `create_sms_logs_table`

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID (PK) | |
| `to` | string | E.164 |
| `message` | text | Rendered body (≤459 chars enforced at enqueue) |
| `trigger` | string | Enum key — see triggers |
| `entity_type` | string, nullable | Polymorphic type |
| `entity_id` | string, nullable | Polymorphic id |
| `status` | enum | `queued` \| `sent` \| `delivered` \| `failed` \| `expired` |
| `termii_message_id` | string, nullable | From Termii send response / DLR |
| `error` | text, nullable | e.g. `no_phone`, Termii error text |
| `cost` | decimal(10,2), nullable | From DLR |
| `queued_at` | timestamp, nullable | |
| `sent_at` | timestamp, nullable | |
| `delivered_at` | timestamp, nullable | |
| `failed_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | |

**Indexes:**

- `(trigger, status)`
- `(entity_type, entity_id)`
- `(to, created_at)`
- `(termii_message_id)` — for DLR lookup

**Model:** `App\Models\SmsLog`

---

## `notification_preferences` (prerequisite / parallel)

Per-user SMS opt-in per trigger. Default **OFF** until user enables on future Settings UI.

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | FK → users | |
| `trigger` | string | Same enum as `sms_logs.trigger` |
| `sms_enabled` | boolean | Default `false` |
| `email_enabled` | boolean | Existing behaviour |
| `in_app_enabled` | boolean | Existing behaviour |

Unique `(user_id, trigger)`.

**Gate:** Before enqueue, check recipient's `notification_preferences.sms_enabled` for the trigger. If no row exists, treat as `sms_enabled = false`.

---

## Triggers (initial set)

Each trigger maps to a Blade template under `resources/views/sms/`. Variables match the same notification payload used by email + in-app channels.

| Trigger key | When it fires | Recipient | Template file | Sample body (≤160 chars) |
|-------------|---------------|-----------|---------------|--------------------------|
| `mrf.approval_required` | MRF reaches an approver's queue | Approver | `mrf-approval-required.blade.php` | `Oando ERP: MRF {{mrf_id}} needs your approval. Open the portal to review.` |
| `mrf.rejected` | MRF rejected at any stage | Requester | `mrf-rejected.blade.php` | `Oando ERP: MRF {{mrf_id}} was rejected. Reason: {{reason}}.` |
| `po.signed` | SCD signs a PO | Requester + vendor primary contact | `po-signed.blade.php` | `Oando ERP: PO {{po_number}} has been signed and dispatched.` |
| `rfq.invitation` | RFQ sent to a vendor | Vendor primary contact | `rfq-invitation.blade.php` | `Oando ERP: New RFQ {{rfq_id}} — submit your quote by {{deadline}}.` |
| `trip.assigned` | Trip assignment to driver / passengers | Driver + each passenger | `trip-assigned.blade.php` | `Oando trip {{trip_id}}: {{origin}} → {{destination}} on {{date}} {{time}}.` |
| `document.expiring` | Vehicle or driver document enters Critical (≤7 days) | Logistics manager + driver | `document-expiring.blade.php` | `Oando ERP: {{doc_type}} for {{subject}} expires in {{days}} days.` |

**Trigger enum (PHP):** `App\Enums\SmsTrigger` or string constants on `SmsLog`.

### Integration hooks (existing `NotificationService`)

Wire SMS enqueue **after** existing email/in-app sends (non-blocking):

| Trigger | Existing method / event |
|---------|-------------------------|
| `mrf.approval_required` | `notifyMRFSubmitted`, `notifyMRFPendingChairmanApproval`, `notifyMRFPendingProcurement`, `notifyMRFForwardedToExecutive`, `notifyLazarusDirectorApprovalPending` |
| `mrf.rejected` | `notifyMRFRejected` |
| `po.signed` | `notifyPOSignedToFinance` (+ requester/vendor paths when PO signed workflow completes) |
| `rfq.invitation` | `notifyRFQAssigned` |
| `trip.assigned` | `TripSchedulingNotificationService` / driver assignment flows |
| `document.expiring` | `notifyDocumentExpiry`, fleet document expiry jobs |

---

## Recipient resolution

| Recipient type | Phone source | Notes |
|----------------|--------------|-------|
| Internal users | `users.phone` | Normalise Nigerian formats to E.164 on save (mutator or form request) |
| Vendors | `vendors.primary_contact_phone` | Contract field name. **Current column:** `contact_person_phone` — alias or migrate at implementation |
| External trip drivers | `logistics_trips.external_driver.phone` | JSON column on trip (Batch 2 Item 8) |
| Fleet drivers | `fleet_drivers.phone_number` | For `document.expiring` when subject is a driver |

**No valid E.164:** Create log row with `status: failed`, `error: no_phone`, **do not** call Termii, **do not** retry.

**E.164 validation (Nigeria-focused):**

- Accept `+234…`, `234…`, `0…` on input; persist as `+234XXXXXXXXXX` (10 digits after country code).
- Reject if normalised length ≠ 14 chars for `+234` numbers.

---

## Blade templates

Directory: `resources/views/sms/`

Templates render **plain text only** (no HTML). Max output 459 characters; truncate with ellipsis in service if exceeded (log warning).

```
resources/views/sms/
  mrf-approval-required.blade.php
  mrf-rejected.blade.php
  po-signed.blade.php
  rfq-invitation.blade.php
  trip-assigned.blade.php
  document-expiring.blade.php
```

**Renderer:** `App\Services\Sms\SmsTemplateRenderer` — `render(string $trigger, array $payload): string`

---

## Suggested file layout (implementation)

```
app/
  Enums/SmsTrigger.php
  Http/Controllers/Api/
    SmsNotificationController.php      # send + logs
    TermiiDlrWebhookController.php     # DLR webhook
  Jobs/SendTermiiSms.php
  Models/SmsLog.php
  Services/Sms/
    SmsDispatchService.php
    TermiiClient.php
    SmsTemplateRenderer.php
    PhoneNormalizer.php
config/sms.php
database/migrations/xxxx_create_sms_logs_table.php
database/migrations/xxxx_create_notification_preferences_table.php  # if not exists
resources/views/sms/*.blade.php
routes/api.php                         # register 3 routes
tests/Feature/Sms/
  SendTermiiSmsTest.php
  TermiiDlrWebhookTest.php
```

---

## Ops checklist

1. **Termii console**
   - Register sender ID (`OANDO` or env value).
   - Enable DND route for transactional SMS (support ticket if needed).
   - Set DLR webhook URL: `https://<backend-host>/api/webhooks/termii/dlr`
   - Copy API key + DLR secret into Render/env per environment.

2. **Render / deployment**
   - Add env vars to backend service only.
   - Add `sms` queue worker process (or extend existing worker with `--queue=default,sms`).
   - Set `SMS_ENABLED=false` in staging until smoke-tested.

3. **Smoke test**
   - `SMS_ENABLED=true`, send test via tinker calling `SmsDispatchService::enqueue`.
   - Confirm `sms_logs` row progresses `queued → sent → delivered`.
   - Toggle `SMS_ENABLED=false` and confirm no Termii HTTP call.

4. **Monitoring**
   - Alert on `failed_jobs` where queue = `sms`.
   - Admin log feed (`GET /api/notifications/sms/logs`) for support audits.

---

## Future UI surfaces (NOT Batch 3)

Documented for future agents — **no frontend work in this batch**.

1. **Settings → Notification Preferences**  
   Add SMS column next to Email / In-app per trigger. Persists `notification_preferences.sms_enabled`.

2. **Admin → SMS Logs**  
   Paginated table via `GET /api/notifications/sms/logs`. Columns: timestamp, to, trigger, entity link, status, cost, error. Resend action for `failed` rows (admin only).

3. **User profile**  
   Phone mandatory when any trigger has `sms_enabled = true`. Show validator error if missing.

See also: `docs/frontend_changes.md` → **Batch 3 — SMS / Termii**.

---

## Related docs

- `app/Services/NotificationService.php` — email + in-app notification entry points
- `app/Jobs/SendLogisticsNotification.php` — queue job pattern reference
- `app/Http/Controllers/Api/FinanceApWebhookController.php` — webhook signature pattern reference
- `docs/frontend_changes.md` — future SPA integration notes
