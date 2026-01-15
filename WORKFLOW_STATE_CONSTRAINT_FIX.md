# Workflow State Constraint Fix

## Problem

The error `SQLSTATE[23514]: Check violation: 7 ERROR: new row for relation "m_r_f_s" violates check constraint "m_r_f_s_workflow_state_check"` occurs when trying to create an MRF with `workflow_state = 'executive_review'`.

## Root Cause

The database has a check constraint on the `workflow_state` column that only allows old workflow states:
- `mrf_created`
- `mrf_approved`
- `mrf_rejected`
- `po_generated`
- `po_reviewed`
- `po_signed`
- `po_rejected`
- `payment_processed`
- `grn_requested`
- `grn_completed`

However, the new workflow implementation uses these additional states:
- `executive_review` ❌ **Not in constraint - causing the error**
- `executive_approved`
- `executive_rejected`
- `procurement_review`
- `vendor_selected`
- `invoice_received`
- `invoice_approved`
- `closed`

## Solution

Created migration `2026_01_15_212005_update_workflow_state_enum_in_m_r_f_s_table.php` that:
1. Drops the old check constraint
2. Converts enum type to text (if needed)
3. Creates a new check constraint with all workflow states

## How to Fix

Run the migration:

```bash
php artisan migrate
```

This will update the database constraint to allow all new workflow states.

## Allowed Workflow States (After Migration)

The new constraint allows:
- `mrf_created`
- `executive_review` ✅
- `executive_approved` ✅
- `executive_rejected` ✅
- `procurement_review` ✅
- `vendor_selected` ✅
- `invoice_received` ✅
- `invoice_approved` ✅
- `po_generated`
- `po_signed`
- `payment_processed`
- `grn_requested`
- `grn_completed`
- `closed` ✅

## Verification

After running the migration, verify the constraint:

```sql
SELECT 
    conname AS constraint_name,
    pg_get_constraintdef(oid) AS constraint_definition
FROM pg_constraint
WHERE conrelid = 'm_r_f_s'::regclass
AND conname = 'm_r_f_s_workflow_state_check';
```

You should see all the new workflow states in the constraint definition.
