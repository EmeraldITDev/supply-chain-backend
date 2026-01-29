# Backend Updates for Vendor Registration Form Changes

This document summarizes frontend changes to the vendor portal registration form and the corresponding backend work required (database, migrations, API, and validation).

---

## 1. Summary of Frontend Changes

### 1.1 Financial Information Section

- A dedicated **Financial Information** section was added to the vendor registration form.
- Vendors can optionally provide:
  - **Account balance**
  - **Bank name** (dropdown for supported countries, free text otherwise)
  - **Account number**
  - **Account name**
  - **Currency** (with country-based default placeholder, e.g. NGN, USD, GBP)

### 1.2 Country and Bank Logic

- **Country** is now a **dropdown** containing a worldwide list of countries (ISO 3166-1 style codes and names).
- Form fields **update dynamically** based on the selected country:
  - **Nigeria**: Bank dropdown populated with Nigerian commercial banks (CBN-style list).
  - **United States**: Bank dropdown populated with US commercial banks (FDIC-style list).
  - **United Kingdom, Ghana, South Africa**: Bank dropdown with relevant commercial banks.
  - **Other countries**: Free-text “Bank name” field (no predefined list).
- **State/Province** label: “State” for US, “County” for UK, “State / Province” otherwise.
- **Postal code** label: “ZIP Code” for US, “Postal Code” otherwise.
- **Phone placeholder** updates by country (e.g. +234 for Nigeria, +1 for US).

### 1.3 Data Sent to Backend

The frontend sends the same existing fields plus optional financial and country metadata:

- Existing: `companyName`, `category`, `email`, `phone`, `address`, `taxId`, `contactPerson`, `documents`.
- New (optional, snake_case in FormData):
  - `account_balance`
  - `bank_name`
  - `account_number`
  - `account_name`
  - `currency`
  - `financial_country_code` (ISO country code, e.g. NG, US)

These are sent only when the user fills at least one financial field.

---

## 2. Database Table Updates

### 2.1 `vendor_registrations` Table

Add columns to store financial and country metadata (if not already present):

| Column               | Type         | Nullable | Description                    |
|----------------------|--------------|----------|--------------------------------|
| `country_code`       | VARCHAR(2)   | YES      | ISO 3166-1 alpha-2 (e.g. NG, US) |
| `account_balance`    | DECIMAL(18,2) or VARCHAR | YES | As entered by vendor        |
| `bank_name`         | VARCHAR(255)  | YES      | Selected bank or free text      |
| `account_number`    | VARCHAR(64)   | YES      | Masked in UI if needed          |
| `account_name`      | VARCHAR(255)  | YES      | Name on account                |
| `currency`          | VARCHAR(3)   | YES      | e.g. NGN, USD, GBP              |

- All of these can be **nullable** so existing rows and registrations without financial info remain valid.

### 2.2 Optional: Separate `vendor_registration_financials` Table

If you prefer to keep financial data in a separate table:

- `vendor_registration_id` (FK to `vendor_registrations.id`)
- `account_balance`, `bank_name`, `account_number`, `account_name`, `currency`, `country_code`
- Timestamps

Use one approach (columns on `vendor_registrations` or a separate table) and keep the API contract below in mind.

---

## 3. Migrations to Create or Run

### 3.1 Migration: Add Financial and Country Columns to `vendor_registrations`

Example (Laravel-style migration):

```php
// Add columns to vendor_registrations
Schema::table('vendor_registrations', function (Blueprint $table) {
    $table->string('country_code', 2)->nullable()->after('address');
    $table->decimal('account_balance', 18, 2)->nullable();
    $table->string('bank_name', 255)->nullable();
    $table->string('account_number', 64)->nullable();
    $table->string('account_name', 255)->nullable();
    $table->string('currency', 3)->nullable();
});
```

- Run: `php artisan migrate` (or your equivalent).
- If you use a separate `vendor_registration_financials` table, create a migration for that table and link it to `vendor_registrations` via `vendor_registration_id`.

---

## 4. Backend Areas to Adjust

### 4.1 Vendor Registration Endpoint

- **Route**: `POST /api/vendors/register` (or your public registration route).
- **Current body**: FormData with `companyName`, `category`, `email`, `phone`, `address`, `taxId`, `contactPerson`, `documents[]`, etc.
- **New FormData fields** (all optional):
  - `account_balance`
  - `bank_name`
  - `account_number`
  - `account_name`
  - `currency`
  - `financial_country_code`

**Actions:**

1. **Request validation**  
   Add optional rules for the new fields, e.g.:
   - `account_balance`: numeric, nullable
   - `bank_name`, `account_number`, `account_name`: string, max length, nullable
   - `currency`: string, size 3, nullable
   - `financial_country_code`: string, size 2, nullable

2. **Persistence**  
   When creating/updating a `VendorRegistration` (or equivalent):
   - Map the new FormData fields to the new DB columns (or to the financials table).
   - Set `country_code` from request if you are also storing it at registration level (optional; can be derived from address/country if you already have that).

3. **Response**  
   No change required to the success/error JSON shape; existing frontend only needs 2xx and the same success payload.

### 4.2 Vendor Registration Model

- Add **fillable** (or equivalent) for: `country_code`, `account_balance`, `bank_name`, `account_number`, `account_name`, `currency`.
- If you use JSON casting for extra data, you could alternatively store financial info in a JSON column and still expose the same fields in API responses.

### 4.3 Vendor Registration Controller (Register Method)

- Read the new fields from the request (e.g. `$request->input('account_balance')`, etc.).
- Pass them into the model or service that creates the registration record.
- Do not require these fields; allow registration without financial info.

### 4.4 Document Service / File Handling

- No change needed for document uploads; financial fields are standard form fields on the same FormData.

### 4.5 Security and Compliance

- **Sensitive data**: Account number and balance are sensitive. Ensure:
  - HTTPS only.
  - Access only by authorized roles (e.g. procurement/finance).
  - Optional: mask account number in logs and in any admin UI (e.g. show last 4 digits only).
- **Validation**: Sanitize and validate length/format for account number and currency to avoid abuse.

### 4.6 Admin / Review UI (Optional)

- If you have a vendor registration review screen, add read-only (or masked) display for the new financial and country fields so procurement can verify bank details when needed.

---

## 5. Checklist for Backend Team

- [ ] Add migration for new columns (or new financials table).
- [ ] Run migrations in all relevant environments.
- [ ] Update `VendorRegistration` model (fillable, casts, any accessors).
- [ ] Update registration request validation to accept and validate the new optional fields.
- [ ] Map new request fields to DB in the registration create/update logic.
- [ ] Ensure existing registrations (without financial data) still work.
- [ ] Apply access control and masking for financial data where appropriate.
- [ ] (Optional) Update admin/review UI to show country and financial info.

---

## 6. API Contract Summary

| FormData key              | Type   | Required | Description                    |
|---------------------------|--------|----------|--------------------------------|
| `account_balance`         | string | No       | Account balance as entered     |
| `bank_name`              | string | No       | Bank name (dropdown or free text) |
| `account_number`         | string | No       | Account number                 |
| `account_name`           | string | No       | Name on account                |
| `currency`                | string | No       | e.g. NGN, USD                   |
| `financial_country_code`  | string | No       | ISO 3166-1 alpha-2 country code |

All other existing registration fields remain unchanged.
