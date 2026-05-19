# Contract Type Free-Text Implementation - Complete Summary

## Overview

The backend has been updated to support arbitrary free-text contract type values while maintaining support for the four Emerald standards (emerald, oando, dangote, heritage) as default options. Custom contract types are automatically routed to the Supply Chain Director, bypassing the Executive review stage entirely.

## What Changed

### 1. Database Schema
**Migration**: `database/migrations/2026_05_19_000001_contract_type_free_text.php`

- **contract_type column**: Changed from `enum('emerald', 'oando', 'dangote', 'heritage')` to `varchar(100)`
- **Constraint removal**: Dropped database CHECK constraint that enforced enum validation
- **New column**: Added `routed_reason` (varchar 100, nullable) to track the routing decision reason

### 2. Model Update
**File**: `app/Models/MRF.php`

- Added `routed_reason` to `$fillable` array to allow mass assignment
- No enum casting present (wasn't needed)

### 3. Routing Logic - New MRF Creation
**File**: `app/Http/Controllers/Api/MRFController.php::store()`

```php
// Define standard contract types
$standardContractTypes = ['emerald', 'oando', 'dangote', 'heritage'];
$normalizedContractType = strtolower(trim((string) $request->contractType));
$isStandardType = in_array($normalizedContractType, $standardContractTypes, true);

// Routing decision
if (!$isStandardType) {
    // Custom contract type: Route to Supply Chain Director, skip Executive
    $initialStage = 'supply_chain_director_review';
    $initialWorkflowState = WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW;
    $routed_reason = 'custom_contract_type';
} else {
    // Standard type: Apply existing Emerald/non-Emerald logic
    // Set routed_reason = 'standard_contract_type' or 'logistics_exception'
}
```

### 4. Routing Logic - Resubmitted MRF
**File**: `app/Http/Controllers/Api/MRFController.php::resubmit()`

- Same routing logic as create endpoint
- Custom contract types always → Supply Chain Director (skip Executive)
- Standard types apply existing conditional logic

### 5. Audit Trail Logging
**File**: `app/Http/Controllers/Api/MRFController.php::store()` (after MRF creation)

When a custom contract type is detected, an approval history entry is logged:

```php
MRFApprovalHistory::create([
    'mrf_id' => $mrf->id,
    'stage' => 'system',
    'action' => 'auto_routed',
    'performed_by' => $user->id,
    'performer_name' => 'System',
    'performer_role' => 'system',
    'remarks' => "Auto-routed to Supply Chain Director (non-standard contract type: {$contractType})"
]);
```

**Visibility**: This entry appears in the MRF approval timeline, making the routing decision transparent to all users.

### 6. Notification Routing
**File**: `app/Services/NotificationService.php::notifyMRFSubmitted()`

Updated notification logic:

| Contract Type | Routing | Notified Recipients |
|---|---|---|
| **Non-standard (custom)** | Supply Chain Director | supply_chain_director, supply_chain, admin |
| **Emerald (logistics exception)** | Supply Chain Director | supply_chain_director, supply_chain, admin |
| **Emerald (standard)** | Executive | bunmi.babajide@emeraldcfze.com (or executive role) |
| **Other standard (oando, dangote, heritage)** | Supply Chain Director | supply_chain_director, supply_chain, admin |

### 7. Validation & Access Control
**Files**: `MRFController.php`, `MRFWorkflowController.php`

- **Create endpoint validator**: `contractType` is `required|string|max:255` (no enum restriction)
- **Update endpoint**: Does NOT allow changing contract_type after creation
- **Executive approval**: Still validates that only `emerald` contract types can use this endpoint (protects against workflow bypass)

## Deployment Steps

1. **Run migration**:
   ```bash
   php artisan migrate
   ```
   This will:
   - Convert contract_type column to varchar(100)
   - Remove CHECK constraint
   - Add routed_reason column

2. **Test custom contract types**:
   - Create MRF with contract_type = "MyCustomType"
   - Verify workflow_stage = 'supply_chain_director_review'
   - Verify approval_history shows auto-routing entry
   - Verify Supply Chain Director receives notification (not Executive)

3. **Verify existing MRFs**:
   - Standard contract types should work as before
   - Emerald contracts should still route to Executive (unless logistics exception)

## MRF ID Generation
The `MRF.generateMRFId()` method supports arbitrary contract types:

- Format: `MRF-{CONTRACTPREFIX}-{YEAR}-{SEQUENCE}`
- Example for custom type "MyCustomType": `MRF-MYCUSTOMTYPE-2026-001`

## High-Value Override Decision
**Current recommendation**: For custom contract types with value >₦1M, route to Supply Chain Director only (do NOT require Chairman approval as well).

**Reason**: Custom contracts bypass Executive entirely, so the high-value rule becomes SCD's responsibility to enforce.

**Status**: Ready for stakeholder confirmation. Implementation can be added if needed.

## Reports & Filters
**Recommendation**: When grouping MRFs by contract_type in dashboards/reports:
- Standard types: Show as-is (Emerald, Oando, Dangote, Heritage)
- Custom types: Group under "Other" category or show individually

**Implementation needed in**: Any report endpoints that filter or aggregate by contract_type.

## Backward Compatibility
- ✅ Existing Emerald contract MRFs continue to work
- ✅ Non-Emerald standard types (Oando, Dangote, Heritage) continue to work
- ✅ Logistics exception routing for Emerald still works
- ✅ MRF ID generation supports arbitrary types
- ✅ Resubmission workflow applies new routing logic

## Files Modified

1. `database/migrations/2026_05_19_000001_contract_type_free_text.php` (NEW)
2. `app/Models/MRF.php`
3. `app/Http/Controllers/Api/MRFController.php`
4. `app/Services/NotificationService.php`

## Files NOT Modified (but affected indirectly)
- `app/Http/Controllers/Api/MRFWorkflowController.php` - No changes needed; executiveApprove() already validates contract_type === 'emerald'
- `app/Services/WorkflowStateService.php` - No changes needed
- `app/Support/LogisticsMrfRouting.php` - No changes needed
- `app/Services/PermissionService.php` - No changes needed

## Testing Checklist

- [ ] Run migrations successfully
- [ ] Create MRF with custom contract type
  - [ ] Verify workflow_stage = 'supply_chain_director_review'
  - [ ] Verify routed_reason = 'custom_contract_type'
  - [ ] Verify approval_history shows auto-routing entry
  - [ ] Verify Supply Chain Director notified (not Executive)
- [ ] Create MRF with standard type (emerald, oando, etc.)
  - [ ] Verify standard routing logic still applies
  - [ ] Verify routed_reason = 'standard_contract_type' or 'logistics_exception'
- [ ] Verify Executive can still approve Emerald contracts
- [ ] Verify Executive cannot approve custom contract types (workflow validation)
- [ ] Resubmit a rejected custom-type MRF
  - [ ] Verify routes to Supply Chain Director
  - [ ] Verify approval_history updated

## Notes for Support/Operations

- Custom contract types always bypass Executive review entirely
- The routed_reason field documents the routing decision
- Approval timeline shows "Auto-routed to Supply Chain Director (non-standard contract type: ...)" for all custom types
- No database validation prevents arbitrary contract_type values (intentional design)
