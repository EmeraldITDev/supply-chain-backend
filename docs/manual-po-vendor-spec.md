# Vendor Creation via Manual PO — Backend Specification

Status: **Backend implemented** · **Frontend integrated** (Jun 2026)

This document describes the manual-PO vendor dedupe and onboarding contract between
the React frontend and the Laravel API (`supply-chain-backend`). Backend
behaviour is live; the frontend calls `GET /api/vendors/lookup`, surfaces
`resolvedVendors` on PO finalise, and honours `profile_completed` on the
Vendor Portal.

**Vendor list:** `GET /api/vendors` excludes `Inactive` rows by default
(merged duplicates). PO/RFQ directory pickers use the default list. Vendor
Management supports **bulk delete** for cleanup; use **Refresh** after server-side
merge + purge.

---

## 1. Problem recap

When a buyer creates a manual / fast-track PO (Procurement → Purchase Orders →
**Create PO**), they can type a brand-new supplier inline on the price
comparison sheet instead of picking from the vendor directory. Previously the
backend created a fresh vendor record each time, so the same vendor ended up
duplicated:

```
Vendor A (V001)
Vendor A (V002)
Vendor A (V003)
```

The improvements: prevent duplicates, require email + phone, auto-onboard the
vendor to the Vendor Portal, and let them complete their profile later.

---

## 2. Frontend changes shipped (context for backend)

These are already implemented in the frontend repo and define the contract the
backend must honour:

| Area | File | Behaviour |
|------|------|-----------|
| Manual vendor on price comparison | `src/components/procurement/PriceComparisonTable.tsx` | Email **and** phone are required and validated client-side; email format checked. Placeholders show `Email *` / `Phone *`. |
| Duplicate detection (client-side hint) | `src/components/procurement/PriceComparisonTable.tsx` (`findExistingVendorMatch`) | As the buyer types a manual vendor name/email, it is matched against the loaded `/vendors` directory. If a match is found, a warning + **"Use existing vendor"** button switches the row to the directory vendor. This is a **UX hint only** and is **not** authoritative. |
| Manual vendor payload | `src/services/procurementApi.ts` (`serializeRow`) | Rows still send `manual_vendor: { name, email, phone, address?, contact_person?, contact_person_email? }` on `PUT /mrfs/{id}/price-comparisons`. |
| Vendor Portal profile completion | `src/pages/VendorPortal.tsx`, `vendorAuthApi.updateProfile` in `src/services/api.ts` | The profile editor submits additional fields and shows a "Complete your profile" banner when company-level fields are missing. |
| Vendor directory bulk delete | `src/pages/Vendors.tsx` + `vendorApi.bulkDelete` | Row checkboxes + **Delete selected**; calls `POST /api/vendors/bulk-delete`. |

> The client-side directory scan can miss matches (pagination, filters). **`GET /api/vendors/lookup` on blur is authoritative**; backend find-or-create on save is the source of truth for persistence.

---

## 3. Backend contract

### 3.1 Enforce vendor de-duplication (REQUIRED)

**Goal:** never create more than one vendor for the same identity.

1. **Application-level uniqueness** (implemented): case-insensitive lookup on
   `email` (primary identity) and normalized `name` (trim + lowercase + collapse
   whitespace). Recommended future hardening: DB unique index on
   `LOWER(TRIM(email))` and a normalized-name index for lookups.
2. **Find-or-create on price comparison save** — when
   `PUT /api/mrfs/{id}/price-comparisons` receives a row with `manual_vendor`
   (not `vendor_id`), the backend must **find-or-create**:
   - If a vendor with the same email (or normalized name) exists → **link to the
     existing vendor**; do not create a new record. Update only safe-to-fill
     blank fields (e.g. set phone if missing). Do **not** overwrite existing
     non-empty data.
   - Else → create a new vendor (see §3.2).
   - Two manual rows with the same email in one save → resolve to a single vendor
     (in-request cache).
3. **Portal onboarding on PO finalise** — `POST /api/mrfs/{id}/generate-po`
   provisions portal access and sends the onboarding email for selected manual-PO
   vendors (see §3.2–3.4). Dedupe itself happens at step 2, not here.
4. Return enough info for the UI to reflect onboarding outcomes (see §3.4).

**Authoritative lookup endpoint:**

```
GET /api/vendors/lookup?email={email}&name={name}
```

Response:

```json
{
  "success": true,
  "data": {
    "match": {
      "id": "V014",
      "name": "Acme Industrial Ltd",
      "email": "sales@acme.com",
      "phone": "+234...",
      "status": "Active",
      "matchedOn": "email"
    }
  }
}
```

- Returns `data.match: null` when there is no match.
- Matches case-insensitively on normalized email/name. Email wins over name.
- May return `Inactive` matches (with `status`) for audit; directory default list
  excludes `Inactive`.

### 3.2 Auto-create + onboard vendor from manual PO (REQUIRED)

When a genuinely new `manual_vendor` is saved on the price comparison sheet:

1. **Create a vendor record** with the basic info captured on the PO:
   - `name` (required), `email` (required, unique at app level), `phone` (required),
     plus optional `address`, `contact_person`, `contact_person_email`.
2. **Mark the record as portal-enabled but profile-incomplete** (implemented):
   - `status: "Active"` (visible in directory),
   - `profile_completed: false`,
   - `onboarding_source: "manual_po"`.
   The frontend treats a vendor as incomplete when `category`, `address`,
   `tax_id`, or `website` are blank; the backend also requires `category !==
   "General"` before setting `profile_completed: true`.
3. **On PO finalise**, provision Vendor Portal access the same way as
   invite/approval: temporary password + `must_change_password: true` → login
   response field `requiresPasswordChange: true`.
4. **Validate server-side** that email + phone are present and email is valid.
   Reject with 422 and a clear message if not (enforced on
   `PUT /api/mrfs/{id}/price-comparisons`).

### 3.3 Vendor onboarding notifications (REQUIRED)

On auto-create from manual PO, when the PO is finalised:

- Send the vendor an **onboarding email**: welcome + Vendor Portal URL + login
  email + temporary password + prompt to complete profile
  (`emails/vendor-manual-po-onboarding`).
- Internal Vendor Management / Procurement notification on new PO vendor is
  **not implemented** (optional future work).
- Reuse the existing vendor-invite credential pipeline where possible. The PO
  flow's `suppress_notifications` applies to MRF/PO workflow noise only — the
  **vendor onboarding email still sends**.

### 3.4 `generate-po` response contract (REQUIRED)

Extend the `POST /api/mrfs/{id}/generate-po` success payload with per-supplier
resolution metadata:

```json
{
  "success": true,
  "data": {
    "mrf": { },
    "po_url": "https://...",
    "fast_tracked": true,
    "resolvedVendors": [
      {
        "input": { "name": "Acme", "email": "sales@acme.com" },
        "vendorId": "V014",
        "action": "linked_existing",
        "onboardingEmailSent": false,
        "status": "Active"
      },
      {
        "input": { "name": "Beta Supplies", "email": "info@beta.com" },
        "vendorId": "V221",
        "action": "created",
        "onboardingEmailSent": true,
        "status": "Active"
      }
    ],
    "resolved_vendors": []
  }
}
```

`resolved_vendors` is a snake_case alias of `resolvedVendors`. Regenerating a PO
(`regenerate: true`) does **not** create duplicate vendors or re-send onboarding
emails for vendors that already have a portal user or
`onboarding_email_sent_at` set.

### 3.5 Profile completion endpoint (REQUIRED)

The Vendor Portal submits extra fields on profile save. The backend accepts and
persists them on:

```
PUT /api/vendors/auth/profile
```

New accepted fields (in addition to existing `contact_person`, `phone`, `address`):

| Field | Type | Notes |
|-------|------|-------|
| `category` | string | One of the known categories or `"Others"`. |
| `category_other` | string | Free text, only when `category === "Others"`. |
| `website` | string (URL) | Optional. |
| `tax_id` | string | TIN / tax information. |
| `year_established` | number/string | |
| `number_of_employees` | string | e.g. `"50-100"`. |
| `annual_revenue` | string | e.g. `"₦500M"`. |

Requirements:

- Validate types; ignore unknown fields gracefully.
- When the profile becomes complete, set `profile_completed: true` so the
  completion banner disappears.
- Return the updated vendor in `data`.

> The Vendor Portal uses **`PUT /api/vendors/auth/profile`** (token-authenticated).
> Mirror fields on `PUT /api/vendors/profile` if the admin edit screen needs them.

---

## 4. Data model / migration notes

Migration `2026_06_20_120000_add_vendor_onboarding_fields.php`:

| Column | Type | Notes |
|--------|------|-------|
| `profile_completed` | bool | Default `true` for existing rows; `false` for new manual-PO vendors |
| `onboarding_source` | string(32) | `registration` \| `invite` \| `manual_po` |
| `onboarding_email_sent_at` | timestamp | Set after successful onboarding email |

**Not yet migrated:** unique DB index on `LOWER(TRIM(email))` and normalized
name index. Dedupe is enforced in PHP (`Vendor::findByEmailCaseInsensitive`,
`Vendor::findByNormalizedName`, `ManualVendorOnboardingService`).

Ensure `category_other` storage matches the registration flow for consistency.

---

## 5. Edge cases (server-side behaviour)

| Case | Behaviour |
|------|-----------|
| Two new manual vendors with the same email in one PO | Single created vendor; both rows link to it (in-request cache). |
| Same email, different name | Email wins; link to existing; do not rename. |
| Same name, different email | Name match links if no email match; email takes precedence when both could apply. |
| **Name-only match** | Backend **auto-links** on save (stronger than frontend warn-only). |
| Inactive/blocked existing vendor | **Currently reuses** the inactive row; lookup returns `status` so UI can warn. |
| Temporary password | Aligns with invite flow; no magic set-password link for PO onboarding. |
| Re-finalise / regenerate PO | No duplicate vendors; no re-send of onboarding email if portal user or prior send exists. |
| Placeholder emails (`@supplier.placeholder`) | Skipped for portal onboarding on generate-po. |

---

## 6. Acceptance criteria

- [x] Saving a manual PO price comparison with a brand-new supplier creates exactly **one**
      vendor; repeating with the same name/email links to it (no V001/V002/V003).
- [x] `GET /api/vendors/lookup` returns authoritative matches by email and name.
- [x] Email + phone enforced server-side on manual vendor rows (422 on missing/invalid).
- [x] New vendor receives portal credentials + onboarding email on PO finalise; can log in.
- [x] Logged-in vendor can complete business fields via `PUT /api/vendors/auth/profile`;
      data persists and the completion banner clears (`profile_completed: true`).
- [x] `generate-po` returns `resolvedVendors` / `resolved_vendors`.
- [ ] DB unique index on case-insensitive email (app-level only today).
- [ ] Optional internal notify when a vendor is created via manual PO.

---

## 7. Resolved design decisions

1. **Onboarding flag:** use **`profile_completed`** (boolean) plus
   **`onboarding_source: "manual_po"`**. New PO vendors are **`Active`** in the
   directory; incompleteness is driven by `profile_completed`, not `status:
   "Pending"`.
2. **Inactive vendor match:** backend **reuses** inactive rows today. Lookup
   surfaces `status` for UI warnings. Block-or-reactivate on link is a future
   change if Procurement requires it.
3. **Credentials:** **temporary password** (same as invite/approval), login returns
   `requiresPasswordChange: true`. Email template:
   `resources/views/emails/vendor-manual-po-onboarding.blade.php`.
4. **Name-only match:** backend **auto-links** on price-comparison save. Frontend
   "Use existing vendor" is a UX shortcut; authoritative check is
   `GET /api/vendors/lookup` on blur.

---

## 8. Cleanup after merge (backend ops)

Only **Active** rows with the same name count as unresolved duplicates in `--list`.
After running merge/purge on the server, refresh **Vendor Management → Vendor Directory**.

```bash
# 1. See what still needs merging (Active duplicates only)
php artisan vendors:merge-duplicates --list

# 2. Merge a group (example)
php artisan vendors:merge-duplicates --canonical=V023 --merge=V020 --force

# 3. Remove inactive merged ghost rows from the database
php artisan vendors:merge-duplicates --purge-merged --force

# 4. Fix any row marked merged but still Active
php artisan vendors:merge-duplicates --repair-inactive --force
```

Keeper selection prefers **real email** (not `@supplier.placeholder`), then portal
user, then lowest vendor code.

| Frontend surface | Behaviour |
|------------------|-----------|
| Vendor Directory (default) | `GET /api/vendors` — Active + Pending (excludes Inactive) |
| **Delete selected** | `POST /api/vendors/bulk-delete` with vendor codes; partial success returns `deleted` + `failed` |
| **Refresh** button | Re-fetches directory + dashboard vendor KPI |
| Manual PO duplicate lookup | `GET /api/vendors/lookup` — any status; Inactive returned with `status` |
| Dashboard **Total Vendors** | Counts from default vendor list (excludes Inactive) |

---

## Implementation reference

| Component | Path |
|-----------|------|
| Find-or-create + lookup | `app/Services/ManualVendorOnboardingService.php` |
| Price comparison save | `app/Http/Controllers/Api/PriceComparisonController.php` |
| PO finalise + `resolvedVendors` | `app/Http/Controllers/Api/MRFWorkflowController.php` |
| Lookup route | `GET /api/vendors/lookup` → `VendorController::lookup` |
| Portal profile | `app/Http/Controllers/Api/VendorAuthController.php` |
| Merge / purge CLI | `app/Console/Commands/MergeDuplicateVendorsCommand.php` |
| Bulk delete | `POST /api/vendors/bulk-delete` → `VendorController::bulkDestroy` |
| Directory filter | `Vendor::scopeForDirectory()` |

See also `frontend_changes.md` §12 (summary pointer).
