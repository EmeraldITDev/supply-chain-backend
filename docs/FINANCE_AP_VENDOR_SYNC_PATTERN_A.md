# Finance AP — Vendor Sync (Pattern A)

Status: **SCM side implemented** · **Finance AP implementation required**

**Audience:** `financeap-backend` and `EmeraldFinanceAP` teams  
**SCM counterpart:** `supply-chain-backend` — vendor master; vendor data is **pushed** on each Finance AP package, not pulled via a live directory API.

Copy this file to `financeap-backend/docs/FINANCE_AP_VENDOR_SYNC_PATTERN_A.md` when syncing repos.

---

## 1. Summary

| Principle | Detail |
|-----------|--------|
| **Vendor master** | SCM (`supply-chain-backend`) only. Finance AP **must not** create or edit SCM vendors. |
| **Pattern** | **A — push sync:** Finance AP upserts a **local read-only copy**. Vendors sync **automatically** on SCM create/update **and** on every package/delta. |
| **No live SCM vendor API** | Finance AP does **not** call `GET /api/vendors` on SCM for browsing. SCM **pushes** to Finance AP. |
| **Identity key** | `scm_vendor_id` (SCM `vendors.id` integer PK) + `vendor_code` (human code e.g. `V023`) |

---

## 2. When SCM sends vendor data

### 2.1 Automatic vendor sync (primary — populates vendor directory)

Whenever a vendor is **created or updated** in SCM (registration approved, manual PO, invite, profile edit), SCM pushes to Finance AP:

```
POST /api/v1/integrations/scm/vendors
X-Api-Key: {FINANCE_AP_API_KEY}
Idempotency-Key: vendor:{scm_vendor_id}:v{updated_at_unix}

{
  "vendor": { /* snapshot — same shape as §3 */ }
}
```

| SCM trigger | When |
|-------------|------|
| Vendor created | Registration approved, manual PO find-or-create, logistics vendor create, invite |
| Vendor updated | Admin profile edit, portal profile save (synced fields only) |
| Skipped | `status = Inactive` (merged duplicates) |

**Backfill existing vendors** (one-time or after deploy):

```bash
php artisan finance-ap:sync-vendors --dry-run
php artisan finance-ap:sync-vendors --force
```

Env on SCM: `FINANCE_AP_VENDOR_SYNC_ENABLED=true` (default), plus `FINANCE_AP_BASE_URL` + `FINANCE_AP_API_KEY`.

Finance AP **must implement** `POST /api/v1/integrations/scm/vendors` and call the same `upsertFromPackageSnapshot()` logic as package ingest (§5.2).

### 2.2 Package / delta sync (secondary — keeps case vendor current)

Vendor snapshot is also included at:

```json
package.header.vendor
```

(or `package.package.header.vendor` on delta — see §4)

| SCM event | Endpoint SCM calls on Finance AP | Vendor in payload |
|-----------|----------------------------------|-------------------|
| Initial finance handoff | `POST /api/v1/integrations/scm/packages` | Yes — full snapshot |
| Delta (e.g. vendor invoice submitted) | `POST /api/v1/integrations/scm/packages/{scm_transaction_id}/delta` | Yes — re-sent in nested `package` |

Finance AP must **upsert on every package, delta, and automatic vendor push**.

---

## 3. Vendor snapshot contract

Path: `header.vendor` (null if MRF has no resolved supplier — treat as validation error on ingest for finance cases).

### 3.1 Field reference

| Field (camelCase) | snake_case alias | Type | Notes |
|-------------------|------------------|------|-------|
| `source` | — | string | Always `"scm"` |
| `scmVendorId` | `scm_vendor_id` | integer | **Primary sync key** — SCM `vendors.id` |
| `vendorCode` | `vendor_code` | string | Display code e.g. `V023` |
| `name` | — | string | Company name |
| `status` | — | string | `Active`, `Pending`, `Inactive`, `Suspended` |
| `category` | — | string | SCM category |
| `categoryDisplay` | `category_display` | string | Formatted label |
| `categoryOther` | `category_other` | string\|null | When category is Others |
| `email` | — | string | Primary email |
| `phone` | — | string\|null | |
| `alternatePhone` | `alternate_phone` | string\|null | |
| `taxId` | `tax_id` | string\|null | TIN / tax ID for AP |
| `website` | — | string\|null | |
| `address` | — | string | Combined line (address + city + state) |
| `addressLine1` | `address_line1` | string\|null | Street |
| `city` | — | string\|null | |
| `state` | — | string\|null | |
| `postalCode` | `postal_code` | string\|null | |
| `countryCode` | `country_code` | string\|null | |
| `contactPerson` | `contact_person` | string\|null | |
| `contactPersonTitle` | `contact_person_title` | string\|null | |
| `contactPersonEmail` | `contact_person_email` | string\|null | |
| `contactPersonPhone` | `contact_person_phone` | string\|null | |
| `profileCompleted` | `profile_completed` | boolean | SCM onboarding flag |
| `onboardingSource` | `onboarding_source` | string\|null | e.g. `manual_po`, `registration`, `invite` |
| `snapshotAt` | `snapshot_at` | ISO8601 string | When SCM built this snapshot |

Accept **either** camelCase or snake_case on ingest; persist using your local naming convention.

### 3.2 Example

```json
{
  "packageVersion": 1,
  "header": {
    "scmTransactionId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "mrfId": "MRF-2026-0042",
    "scmPoNumber": "PO-220626-MochenzComputers-0001",
    "vendor": {
      "source": "scm",
      "scmVendorId": 42,
      "scm_vendor_id": 42,
      "vendorCode": "V023",
      "vendor_code": "V023",
      "name": "Mochenz Computers",
      "status": "Active",
      "email": "sales@mochenz.com",
      "phone": "+2348000000000",
      "taxId": "TIN-123456",
      "tax_id": "TIN-123456",
      "address": "12 Marina, Lagos, LA",
      "contactPerson": "Jane Doe",
      "contact_person": "Jane Doe",
      "snapshotAt": "2026-06-22T16:00:00+01:00"
    }
  }
}
```

### 3.3 Supplier resolution on SCM (for your support team)

SCM resolves the supplier in this order:

1. Selected price-comparison supplier (manual PO / fast-track)
2. MRF `selected_vendor_id`

If `header.vendor` is `null`, the MRF reached Finance AP without a supplier — flag the case for Procurement; do not create a placeholder vendor in Finance AP.

---

## 4. Delta payload shape

```json
{
  "reason": "vendor_invoice_submitted",
  "package": {
    "packageVersion": 2,
    "deltaReason": "vendor_invoice_submitted",
    "header": { "vendor": { } }
  }
}
```

On delta ingest:

1. Re-upsert `header.vendor` using the same rules as initial package.
2. Update the SCM case’s linked vendor if `scm_vendor_id` changed (rare).
3. Re-cache documents from `documentManifest` as today.

---

## 5. Finance AP backend — required implementation

### 5.1 Data model

#### `vendor_scm_mappings` (required)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `scm_vendor_id` | unsigned bigint | **Unique** — SCM `vendors.id` |
| `finance_ap_vendor_id` | FK → `vendors.id` | Local vendor row |
| `vendor_code` | string | Denormalized `V023` for display/search |
| `last_synced_at` | timestamp | From `snapshotAt` |
| `created_at` / `updated_at` | timestamps | |

#### Extend local `vendors` table (or equivalent)

| Column | Notes |
|--------|-------|
| `source` | enum/string: `local` \| `scm` — SCM-synced rows are `scm` |
| `scm_vendor_id` | nullable, unique when set |
| `vendor_code` | SCM human code (optional if you already store code elsewhere) |
| `is_scm_master` | boolean default `false` — `true` = read-only from SCM |

Do **not** allow `POST /api/v1/vendors` to create rows with `source=scm` from human users.

### 5.2 Inbound routes (machine auth from SCM)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/v1/integrations/scm/vendors` | **Required.** Upsert one vendor from `{ "vendor": { ... } }` |
| `POST` | `/api/v1/integrations/scm/packages` | Create/update SCM case + upsert `header.vendor` |
| `POST` | `/api/v1/integrations/scm/packages/{scm_transaction_id}/delta` | Delta + re-upsert vendor |

All use `X-Api-Key` validation (same middleware as package ingest).

**`POST /api/v1/integrations/scm/vendors` handler (required):**

```php
public function upsertVendor(Request $request)
{
    $snapshot = $request->input('vendor') ?? $request->input('data.vendor');
    $vendor = $this->scmVendorSyncService->upsertFromPackageSnapshot($snapshot);

    return response()->json([
        'success' => true,
        'data' => [
            'financeApVendorId' => $vendor->id,
            'scmVendorId' => $snapshot['scm_vendor_id'] ?? $snapshot['scmVendorId'],
            'vendorCode' => $snapshot['vendor_code'] ?? $snapshot['vendorCode'],
        ],
    ]);
}
```

### 5.3 Ingest service (required)

Create e.g. `App\Services\Scm\ScmVendorSyncService`:

```
upsertFromPackageSnapshot(array $vendorPayload): Vendor
```

Algorithm:

1. Read `scm_vendor_id` (or `scmVendorId`). If missing → throw / log validation error.
2. Find `vendor_scm_mappings` by `scm_vendor_id`.
3. If mapping exists → update linked `vendors` row with snapshot fields (overwrite SCM-owned fields).
4. If not → create `vendors` row (`source=scm`, `is_scm_master=true`) + mapping row.
5. Set `last_synced_at` from `snapshotAt`.
6. Return local vendor for linking to `scm_procurement_cases.vendor_id`.

**Idempotent:** same `scm_vendor_id` always maps to one Finance AP vendor.

**Conflict:** if a local vendor already exists with the same email but different `scm_vendor_id`, prefer `scm_vendor_id` as authoritative and log a merge warning for ops (do not auto-merge without rules).

Call this from:

- **`POST /api/v1/integrations/scm/vendors`** (automatic sync — **this is why `GET /api/v1/vendors` is empty until this exists**)
- `POST /api/v1/integrations/scm/packages` handler (after auth)
- `POST /api/v1/integrations/scm/packages/{id}/delta` handler

### 5.4 SCM case linkage

On case create/update:

```php
$vendor = $scmVendorSyncService->upsertFromPackageSnapshot($package['header']['vendor']);
$case->finance_ap_vendor_id = $vendor->id;
$case->scm_vendor_id = $vendorPayload['scm_vendor_id'];
$case->vendor_code = $vendorPayload['vendor_code'];
```

Store denormalized `vendor_name` on the case for list views (optional, from snapshot `name`).

### 5.5 Read APIs for Finance AP UI (required)

Expose **local** read-only endpoints (Sanctum human auth):

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/vendors` | List SCM-synced vendors (`source=scm` or via mapping) |
| `GET` | `/api/v1/vendors/{id}` | Detail |
| `GET` | `/api/v1/scm/cases/{caseId}/vendor` | Vendor for one SCM case (convenience) |

Query params for list: `?search=`, `?status=`, pagination.

**Do not implement** for SCM-sourced vendors:

- `POST /api/v1/vendors` (create)
- `PUT/PATCH /api/v1/vendors/{id}` (edit SCM fields)
- `DELETE /api/v1/vendors/{id}`

Optional: allow editing **Finance AP–only** fields (internal notes, AP account code) on the local row without writing back to SCM.

### 5.6 Inbound auth from SCM

SCM pushes packages with:

```
X-Api-Key: {FINANCE_AP_API_KEY}
Idempotency-Key: {uuid}
```

Validate with your existing SCM integration middleware (same as package ingest).

### 5.7 Configuration

| Env var (Finance AP) | Purpose |
|----------------------|---------|
| `SCM_INTEGRATION_API_KEY` | Validates inbound package/delta from SCM |
| `SCM_WEBHOOK_URL` | FA → SCM webhooks (unchanged) |
| `SCM_WEBHOOK_SECRET` | HMAC for outbound webhooks |
| `SCM_DOCUMENT_REFRESH_BASE_URL` | SCM base for document refresh |

SCM env (for reference):

| Env var (SCM) | Purpose |
|---------------|---------|
| `FINANCE_AP_BASE_URL` | Where SCM pushes packages and vendors |
| `FINANCE_AP_API_KEY` | Outbound key SCM sends as `X-Api-Key` |
| `FINANCE_AP_VENDOR_SYNC_ENABLED` | `true` — auto-push on vendor create/update |

---

## 6. Finance AP frontend (`EmeraldFinanceAP`) — required implementation

### 6.1 SCM Case detail (minimum)

On **SCM Cases → detail**, show vendor panel from the case’s linked local vendor:

- Name, `vendor_code`, status badge
- Email, phone, tax ID
- Address, contact person
- Label: **“Vendor master: SCM”** (read-only)

No “Edit vendor” for `source=scm` rows.

### 6.2 Optional: Synced vendors list

Nav item **“SCM Vendors”** (Account Officer + Finance Manager):

- `GET /api/v1/vendors?source=scm`
- Search by name / code / tax ID
- Read-only detail drawer
- No “Add vendor” button

### 6.3 Manual PO / invoice linking

When staff manually link a Finance AP PO or invoice to a vendor:

- Picker should list **SCM-synced vendors** only (or clearly section them).
- Do not offer “Create new vendor” for SCM procurement flows.

### 6.4 What the frontend must not do

- Call SCM `GET /api/vendors` directly (no CORS / no user token sharing).
- Expose SCM API keys in the browser.
- Provide vendor registration or invite flows (those live in SCM only).

---

## 7. Acceptance criteria

### SCM (supply-chain-backend) — done

- [x] Vendor snapshot on `header.vendor` in package builder.
- [x] **Automatic push on vendor create/update** (`VendorObserver` → `FinanceApVendorSyncService`).
- [x] **Backfill command:** `php artisan finance-ap:sync-vendors --force`.
- [x] Resolves supplier from price comparison + `selected_vendor_id`.
- [x] Extended snapshot fields + snake_case aliases.

### Finance AP backend — todo

- [ ] **`POST /api/v1/integrations/scm/vendors`** ingest handler (critical — without this, vendor list stays empty).
- [ ] `vendor_scm_mappings` migration.
- [ ] `ScmVendorSyncService::upsertFromPackageSnapshot()`.
- [ ] Wire sync into package + delta ingest handlers.
- [ ] Link `scm_procurement_cases` to synced vendor.
- [ ] `GET /api/v1/vendors` read-only for SCM-sourced rows.
- [ ] Block create/update/delete of SCM-master vendor fields via human APIs.

### Finance AP frontend — todo

- [ ] Vendor panel on SCM Case detail.
- [ ] Read-only UX; no add-vendor for SCM source.
- [ ] (Optional) SCM Vendors list page.

---

## 8. Testing checklist (Finance AP)

1. Ingest package with `header.vendor` → one `vendors` row + one `vendor_scm_mappings` row.
2. Re-ingest same `scm_vendor_id` with updated phone → same FA vendor id, phone updated.
3. Second package with different `scm_vendor_id` → second FA vendor.
4. Delta with `reason=vendor_invoice_submitted` → vendor still upserted.
5. Human `POST /vendors` cannot set `source=scm` (or is rejected).
6. UI shows vendor on case; no edit controls for SCM fields.

---

## 9. Related SCM documentation

| Document | Topic |
|----------|-------|
| `docs/FINANCE_AP_SIDE_SCM_INTEGRATION.md` | Full Phase 6 integration |
| `docs/FINANCE_AP_IMPLEMENTATION_PLAN.md` | SCM phase plan |
| `docs/manual-po-vendor-spec.md` | How vendors enter SCM (manual PO) |
| `frontend_changes.md` §13 | PO numbering cross-reference |

---

## 10. FAQ

**Q: `finance-ap:sync-vendors --force` fails with 404 `route api/v1/integrations/scm/vendors could not be found`?**  
A: **SCM is working correctly.** Finance AP has not deployed `POST /api/v1/integrations/scm/vendors` yet. Implement using **`docs/financeap-backend/SCM_VENDOR_INGEST_ROUTE.md`**, deploy FA, then re-run the SCM command. Check `finance_sync_events` (`event_type = vendor_sync`) for `http_status = 200`.

**Q: Can Finance AP add a vendor for a one-off payment?**  
A: Not for SCM procurement cases. SCM is master. For non-SCM spend, use Finance AP’s own local vendor model (`source=local`) if your product allows it — keep separate from SCM-synced rows.

**Q: What if vendor is `Inactive` on SCM?**  
A: Snapshot includes `status`. Display it; do not hide. Payment decisions are human workflow in Finance AP.

**Q: Do we need Pattern B (live SCM vendor API)?**  
A: Not for Pattern A. Revisit only if you need full-directory search of suppliers never seen on a case.

**Q: Portal users (`users` table on SCM)?**  
A: Not included in snapshot. Finance AP cares about the **company** (`vendors`), not vendor portal login accounts.
