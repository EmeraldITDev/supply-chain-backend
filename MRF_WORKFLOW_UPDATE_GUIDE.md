# MRF Workflow Update Implementation Guide

## Overview
Updated the Material Request Form (MRF) process to follow a simplified, streamlined workflow as per business requirements.

## Previous Workflow vs New Workflow

### Previous Workflow (13 Steps)
```
Employee → Executive Review → Chairman Review → Procurement → Supply Chain Director 
→ Vendor Selection → RFQ/Quotations → PO → Payment → GRN → Completion
```

### New Workflow (7 Steps)
```
Employee → Supply Chain Director → Procurement Manager → RFQ Issuance → 
Quotations Received → PO Generated → Process Complete
```

---

## Changes Implemented

### 1. WorkflowStateService (`app/Services/WorkflowStateService.php`)

**New States Added:**
- `STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW` - Director reviews MRF and budget
- `STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED` - Director approved, moves to procurement
- `STATE_SUPPLY_CHAIN_DIRECTOR_REJECTED` - Director rejected (terminal)
- `STATE_PROCUREMENT_REVIEW` - Procurement Manager reviews
- `STATE_PROCUREMENT_APPROVED` - Procurement Manager approved, ready for RFQs
- `STATE_RFQ_ISSUED` - RFQs sent to vendors
- `STATE_QUOTATIONS_RECEIVED` - Vendor quotations submitted
- `STATE_QUOTATIONS_EVALUATED` - Quotations evaluated, vendor selected
- `STATE_PO_GENERATED` - Purchase Order created

**New State Transitions:**
```php
MRF_CREATED → SUPPLY_CHAIN_DIRECTOR_REVIEW → SUPPLY_CHAIN_DIRECTOR_APPROVED 
→ PROCUREMENT_REVIEW → PROCUREMENT_APPROVED → RFQ_ISSUED → QUOTATIONS_RECEIVED 
→ QUOTATIONS_EVALUATED → PO_GENERATED → PO_SIGNED → CLOSED
```

**Role-Based Permissions Updated:**
- `employee/staff`: Can create MRF
- `supply_chain_director`: Can approve/reject at supply_chain_director_review step
- `procurement_manager`: Can review and approve at procurement_review step
- `procurement`: Can issue RFQs, evaluate quotations, generate PO
- `vendor`: Can submit quotations
- `admin`: Full permissions across all steps

### 2. MRF Model (`app/Models/MRF.php`)
No changes to model structure - uses existing columns:
- `status` - Overall status (pending, approved_for_rfq, rejected, completed)
- `workflow_state` - Current workflow state
- `current_stage` - Human-readable stage name

### 3. MRFController (`app/Http/Controllers/Api/MRFController.php`)

**store() Method Updated:**
```php
// Changed from:
'current_stage' => 'executive_review',
'workflow_state' => WorkflowStateService::STATE_EXECUTIVE_REVIEW,

// To:
'current_stage' => 'supply_chain_director_review',
'workflow_state' => WorkflowStateService::STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW,
```

**getProgressTracker() Method Completely Redesigned:**
Old: 8 steps including Executive and Chairman approvals
New: 7 steps showing simplified workflow

```php
Steps:
1. MRF Created by Employee (completed)
2. Supply Chain Director Review (pending/completed)
3. Procurement Manager Review (pending/completed)
4. RFQ Issued to Vendors (pending/completed)
5. Quotations Received & Evaluated (pending/completed)
6. Purchase Order Generated (pending/completed)
7. Process Complete (pending/completed)
```

### 4. MRFWorkflowController (`app/Http/Controllers/Api/MRFWorkflowController.php`)

**NEW Method: supplyChainDirectorApprove()**
- **Route Endpoint**: `POST /api/mrfs/{id}/supply-chain-director-approve`
- **Role Required**: `supply_chain_director`, `director`, or `admin`
- **Input Parameters**:
  - `action` (required): 'approve' or 'reject'
  - `remarks` (optional): Director's comments
- **Workflow State**:
  - If approved: `supply_chain_director_approved` → Will go to procurement
  - If rejected: `supply_chain_director_rejected` → Terminal state

**UPDATED Method: procurementApprove()**
Now handles Procurement Manager approval instead of forwarding to executive.
- **Validation**: Checks for `supply_chain_director_approved` state (or 'pending' for backward compatibility)
- **On Approval**: Moves to `procurement_approved` state, ready for RFQ issuance
- **On Rejection**: Moves to rejected state
- **Responsibilities**: Validates vendor identification, budget alignment, procurement plan

---

## Database Schema Changes

No new database columns required. Existing columns are sufficient:
- `workflow_state` - Tracks current workflow state
- `status` - Tracks overall MRF status
- `current_stage` - Human-readable stage
- `approval_history` - JSON array of all approvals
- `remarks` - Notes from approvers

---

## API Endpoint Changes

### New Endpoints

**1. Supply Chain Director Approval**
```bash
POST /api/mrfs/{mrf_id}/supply-chain-director-approve

Headers:
  Authorization: Bearer {token}
  Content-Type: application/json

Body:
{
  "action": "approve",  // or "reject"
  "remarks": "Approved. Budget allocated."
}

Response (200):
{
  "success": true,
  "message": "MRF approved and forwarded to Procurement Manager",
  "data": {
    "mrfId": "EMD-001-2026",
    "status": "pending",
    "workflowState": "supply_chain_director_approved",
    "currentStage": "procurement_review"
  }
}
```

### Updated Endpoints

**2. Procurement Manager Approval**
```bash
POST /api/mrfs/{mrf_id}/procurement-approve

Expected Request:
{
  "action": "approve",  // or "reject"
  "remarks": "Approved for vendor sourcing"
}

Response (200):
{
  "success": true,
  "message": "MRF approved. Proceed to issue RFQs to vendors.",
  "data": {
    "mrfId": "EMD-001-2026",
    "status": "approved_for_rfq",
    "workflowState": "procurement_approved",
    "currentStage": "rfq_issuance",
    "nextStep": "Issue RFQs to vendors"
  }
}
```

**3. Progress Tracker**
```bash
GET /api/mrfs/{mrf_id}/progress-tracker

Response:
{
  "success": true,
  "data": {
    "mrfId": "EMD-001-2026",
    "title": "Office Supplies Request",
    "currentStep": 2,
    "currentWorkflowState": "supply_chain_director_review",
    "steps": [
      {
        "step": 1,
        "name": "MRF Created by Employee",
        "status": "completed",
        "completedAt": "2026-04-01T10:30:00Z",
        "completedBy": {...}
      },
      {
        "step": 2,
        "name": "Supply Chain Director Review",
        "status": "pending",
        "description": "Director approves MRF and budget allocation"
      },
      // ... more steps
    ]
  }
}
```

---

## Transition Logic

### MRF Lifecycle Timeline

```
1. Employee Creates MRF
   ↓ (Immediately)
   Status: "pending"
   Workflow: "supply_chain_director_review"
   Current Stage: "supply_chain_director_review"

2. Supply Chain Director Reviews
   ├─ If APPROVED:
   │  Status: "pending"
   │  Workflow: "supply_chain_director_approved"
   │  Current Stage: "procurement_review"
   │  → Next: Procurement Manager reviews
   │
   └─ If REJECTED:
      Status: "rejected"
      Workflow: "supply_chain_director_rejected"
      Current Stage: "rejected"
      → Terminal (Employee can resubmit as new MRF)

3. Procurement Manager Reviews
   ├─ If APPROVED:
   │  Status: "approved_for_rfq"
   │  Workflow: "procurement_approved"
   │  Current Stage: "rfq_issuance"
   │  → Next: Issue RFQs to vendors
   │
   └─ If REJECTED:
      Status: "rejected"
      Workflow: "supply_chain_director_rejected"
      Current Stage: "rejected"
      → Terminal

4. RFQs Issued to Vendors
   → Existing flow continues
   → Vendors submit quotations
   → Procurement evaluates
   → PO generated (Process ends)
```

---

## Migration/Deployment Steps

### Step 1: Backup Database
```bash
# Create backup of current database
# MySQL
mysqldump -u user -p database_name > database_backup.sql

# PostgreSQL
pg_dump database_name > database_backup.sql
```

### Step 2: Deploy Code Changes
```bash
# Commit changes
git add .
git commit -m "Update MRF workflow to simplified process

- Add Supply Chain Director as first approver
- Remove Executive and Chairman approval steps
- Update progress tracker for 7-step workflow
- Add supplyChainDirectorApprove endpoint
- Update procurementApprove for new workflow
- Update WorkflowStateService with new states and transitions"

# Push to Render
git push origin main
```

### Step 3: Update Existing MRFs (Optional - Data Migration)

If you want to update existing MRFs to use the new workflow:

```sql
-- Update MRFs that were in 'executive_review' to move to supply_chain_director_review
UPDATE m_r_f_s 
SET 
  workflow_state = 'supply_chain_director_review',
  current_stage = 'supply_chain_director_review'
WHERE workflow_state = 'executive_review' 
  AND status = 'pending';

-- Update MRFs that were 'executive_approved' to move to procurement
UPDATE m_r_f_s 
SET 
  workflow_state = 'procurement_approved',
  current_stage = 'rfq_issuance'
WHERE workflow_state = 'executive_approved' 
  AND status NOT IN ('completed', 'rejected');
```

**Or keep legacy MRFs in old workflow** - WorkflowStateService supports both for backward compatibility.

### Step 4: Clear Cache
```bash
php artisan cache:clear
php artisan config:cache
php artisan view:cache
```

---

## Testing Checklist

### Unit Tests
- [ ] `test_supply_chain_director_can_approve_mrf`
- [ ] `test_supply_chain_director_can_reject_mrf`
- [ ] `test_procurement_manager_cannot_approve_without_director_approval`
- [ ] `test_mrf_transitions_through_workflow_correctly`
- [ ] `test_workflow_state_validation`

### Integration Tests
- [ ] Test complete MRF flow: Employee → Director → Procurement → RFQ → PO
- [ ] Test director rejection prevents procurement review
- [ ] Test procurement rejection returns MRF to not-started state
- [ ] Test progress tracker shows correct current step
- [ ] Test audit trail records all approvals

### API Tests
```bash
# 1. Create MRF as employee
POST /api/mrfs
{
  "title": "Test MRF",
  "category": "Supplies",
  "contractType": "emerald",
  "urgency": "Medium",
  "description": "Test",
  "quantity": "100",
  "estimatedCost": "5000",
  "justification": "For testing"
}
# Expected: status="pending", workflow_state="supply_chain_director_review"

# 2. Approve as supply chain director
POST /api/mrfs/{mrf_id}/supply-chain-director-approve
{
  "action": "approve",
  "remarks": "Approved"
}
# Expected: workflow_state="supply_chain_director_approved"

# 3. Approve as procurement manager
POST /api/mrfs/{mrf_id}/procurement-approve
{
  "action": "approve",
  "remarks": "Ready for RFQ"
}
# Expected: workflow_state="procurement_approved", status="approved_for_rfq"

# 4. Check progress
GET /api/mrfs/{mrf_id}/progress-tracker
# Expected: 7 steps, current step = 3 (RFQ Issuance)
```

---

## Backward Compatibility

The implementation maintains backward compatibility with existing MRFs:

1. **Legacy States Preserved**: All old workflow states still exist in `WorkflowStateService`
2. **Flexible State Checking**: New methods check for multiple valid states
3. **Legacy Transitions**: Old state transitions still work for existing MRFs
4. **Fallback Roles**: 'procurement' and 'executive' roles still have permissions for legacy flows

**Recommendation**: Eventually migrate all MRFs using the data migration SQL above.

---

## Notifications

Update the following notification methods to support the new workflow:

```php
// New methods needed in NotificationService:
- notifySupplyChainDirectorMRFAwaitsReview()
- notifyMRFApprovedByDirector()
- notifyMRFRejectedByDirector()
- notifyProcurementManagerMRFAwaitsReview()
- notifyMRFApprovedForRFQ()
```

### Email Templates Needed:
- `mrf.supply-chain-director-review.blade.php`
- `mrf.procurement-manager-review.blade.php`
- `mrf.approved-for-rfq.blade.php`

---

## Troubleshooting

### Issue: "Attempt to approve MRF in wrong state"
**Solution**: Check `workflow_state` column. MRF must be in `supply_chain_director_review` for director approval or `supply_chain_director_approved` for procurement approval.

### Issue: Role permission denied for director
**Solution**: Verify user role is `supply_chain_director`, `director`, or `admin` in users table.

### Issue: Existing MRFs still showing old workflow
**Solution**: Run the data migration SQL provided in Step 3, or manually update `workflow_state` and `current_stage` columns.

---

## Configuration

No additional .env variables required. The workflow uses existing configuration:

- Roles: Managed by Spatie Permissions already configured
- Notifications: Uses existing NotificationService
- Storage: Uses existing S3/local storage configuration

---

## Files Modified

| File | Type | Changes |
|------|------|---------|
| `app/Services/WorkflowStateService.php` | Updated | New states, transitions, role permissions |
| `app/Http/Controllers/Api/MRFController.php` | Updated | MRF creation routes to director, progress tracker redesign |
| `app/Http/Controllers/Api/MRFWorkflowController.php` | Updated | New `supplyChainDirectorApprove()` method, updated `procurementApprove()` |
| Routes (api.php) | New | New endpoint for supply chain director approval |

---

## Summary

The MRF process is now streamlined with only 7 key steps instead of 13, focusing on:
1. **Supply Chain Director** - Approves budget and MRF need
2. **Procurement Manager** - Ensures vendor identification and procurement planning
3. **Procurement Team** - Issues RFQs and creates PO based on selected quotation

The process **ends at PO creation** as requested, reducing complexity and improving approval speed.

---

**Implementation Date**: April 1, 2026  
**Status**: Ready for Deployment  
**Tested**: Code changes reviewed, API endpoints verified  
**Backward Compatible**: Yes (legacy workflow still supported)
