# MRF Workflow - Quick Reference for Developers

## Overview
Material Request Forms now follow a **simplified 7-step workflow** tailored for efficient procurement.

**Key Change**: Supply Chain Director now approves MRFs first (instead of Executives)

---

## Workflow Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│  1. EMPLOYEE CREATES MRF                                        │
│     ↓                                                            │
│     Status: "pending"                                            │
│     WorkflowState: "supply_chain_director_review"               │
│     CurrentStage: "supply_chain_director_review"                │
│                                                                  │
│  2. SUPPLY CHAIN DIRECTOR REVIEWS                               │
│     ├─ APPROVE → supply_chain_director_approved                 │
│     │             ↓                                              │
│     │  3. PROCUREMENT MANAGER REVIEWS                           │
│     │     ├─ APPROVE → procurement_approved                     │
│     │     │             ↓                                        │
│     │     │  4. ISSUE RFQs TO VENDORS                           │
│     │     │     ↓                                                │
│     │     │  5. RECEIVE QUOTATIONS                              │
│     │     │     ↓                                                │
│     │     │  6. EVALUATE & SELECT VENDOR                        │
│     │     │     ↓                                                │
│     │     │  7. GENERATE PURCHASE ORDER                         │
│     │     │     ↓                                                │
│     │     │  END - Process Complete                             │
│     │     │                                                      │
│     │     └─ REJECT → Rejected (Terminal)                       │
│     │                                                            │
│     └─ REJECT → Rejected (Terminal)                             │
│                 (Employee can resubmit as new MRF)              │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## API Endpoints

### 1. Create MRF (Employee Only)
**POST** `/api/mrfs`

```javascript
// Request
{
  "title": "Office Supplies",
  "category": "Supplies",
  "contractType": "emerald",     // emerald, oando, dangote, heritage
  "urgency": "Medium",            // Low, Medium, High, Critical
  "description": "Monthly office supplies",
  "quantity": "100 units",
  "estimatedCost": 50000,
  "justification": "Needed for Q2 operations",
  "department": "Operations",
  "pfi": <file>  // Optional: Proforma Invoice PDF
}

// Response
{
  "success": true,
  "data": {
    "id": 1,
    "mrf_id": "EMD-001-2026",
    "status": "pending",
    "workflow_state": "supply_chain_director_review",  // Automatically routed here!
    "current_stage": "supply_chain_director_review"
  }
}
```

### 2. Supply Chain Director Approves (NEW)
**POST** `/api/mrfs/{mrf_id}/supply-chain-director-approve`

```javascript
// Request
{
  "action": "approve",  // or "reject"
  "remarks": "Budget approved. Allocating $50K from Q2 budget."
}

// Response - Approved
{
  "success": true,
  "message": "MRF approved and forwarded to Procurement Manager",
  "data": {
    "mrfId": "EMD-001-2026",
    "status": "pending",
    "workflowState": "supply_chain_director_approved",
    "nextStep": "Procurement Manager Review"
  }
}

// Response - Rejected
{
  "success": true,
  "message": "MRF rejected",
  "data": {
    "mrfId": "EMD-001-2026",
    "status": "rejected",
    "workflowState": "supply_chain_director_rejected"
  }
}
```

### 3. Procurement Manager Approves
**POST** `/api/mrfs/{mrf_id}/procurement-approve`

```javascript
// Request
{
  "action": "approve",  // or "reject"
  "remarks": "Vendors identified. Ready to issue RFQs."
}

// Response - Approved
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

### 4. Get MRF Progress
**GET** `/api/mrfs/{mrf_id}/progress-tracker`

```javascript
// Response
{
  "success": true,
  "data": {
    "mrfId": "EMD-001-2026",
    "title": "Office Supplies",
    "currentStep": 2,
    "currentWorkflowState": "supply_chain_director_review",
    "steps": [
      {
        "step": 1,
        "name": "MRF Created by Employee",
        "status": "completed",
        "completedAt": "2026-04-01T10:30:00Z",
        "completedBy": { "id": 1, "name": "John Employee" }
      },
      {
        "step": 2,
        "name": "Supply Chain Director Review",
        "status": "pending",
        "description": "Director approves MRF and budget allocation"
      },
      {
        "step": 3,
        "name": "Procurement Manager Review",
        "status": "not_started",
        "description": "Manager reviews MRF and approves vendor selection process"
      },
      {
        "step": 4,
        "name": "RFQ Issued to Vendors",
        "status": "not_started",
        "rfqCount": 0
      },
      {
        "step": 5,
        "name": "Quotations Received & Evaluated",
        "status": "not_started",
        "quotationCount": 0
      },
      {
        "step": 6,
        "name": "Purchase Order Generated",
        "status": "not_started",
        "poNumber": null
      },
      {
        "step": 7,
        "name": "Process Complete",
        "status": "not_started"
      }
    ]
  }
}
```

### 5. List MRFs
**GET** `/api/mrfs?status=pending&sortBy=date`

Filter by workflow state:
```
GET /api/mrfs?status=pending
GET /api/mrfs?workflow_state=supply_chain_director_review
GET /api/mrfs?workflow_state=procurement_approved
```

---

## Workflow States Reference

```php
// New Workflow States
STATE_SUPPLY_CHAIN_DIRECTOR_REVIEW       // Awaiting director approval
STATE_SUPPLY_CHAIN_DIRECTOR_APPROVED     // Director approved
STATE_SUPPLY_CHAIN_DIRECTOR_REJECTED     // Director rejected
STATE_PROCUREMENT_REVIEW                 // Awaiting procurement approval
STATE_PROCUREMENT_APPROVED               // Procurement approved
STATE_RFQ_ISSUED                         // RFQs sent to vendors
STATE_QUOTATIONS_RECEIVED                // Vendors submitted quotations
STATE_QUOTATIONS_EVALUATED               // Quotations analyzed
STATE_PO_GENERATED                       // Purchase Order created
STATE_CLOSED                             // Process complete

// Status Values
"pending"                         // Initial status
"approved_for_rfq"               // Ready for RFQ issuance
"rejected"                       // Rejected at some stage
"completed"                      // Process complete
```

---

## Role-Based Access

| Role | Can Do |
|------|--------|
| **employee** | Create MRF |
| **supply_chain_director** | Approve/reject MRF (step 2) |
| **procurement_manager** | Approve/reject MRF (step 3) |
| **procurement** | Issue RFQs, evaluate quotations, generate PO |
| **vendor** | Submit quotations |
| **admin** | All actions |

---

## Status Transitions

```
Creation → supply_chain_director_review
           ↓ (Director Approval)
      → supply_chain_director_approved
           ↓ (Procurement Approval)
      → procurement_approved
           ↓ (RFQ Issuance)
      → rfq_issued
           ↓ (Quotations Received)
      → quotations_received
           ↓ (Vendor Selected)
      → quotations_evaluated
           ↓ (PO Created)
      → po_generated
           ↓ (PO Signed)
      → po_signed
           ↓
      → closed (PROCESS ENDS HERE)

Rejection Path:
      → supply_chain_director_rejected (Terminal)
      → supply_chain_director_rejected (Terminal)
```

---

## Common Tasks

### Check if MRF is awaiting director approval
```php
$mrf = MRF::find($id);

if ($mrf->workflow_state === 'supply_chain_director_review') {
    // Awaiting Supply Chain Director approval
}
```

### Check if MRF is ready for RFQs
```php
if ($mrf->workflow_state === 'procurement_approved' && 
    $mrf->status === 'approved_for_rfq') {
    // Ready to issue RFQs
}
```

### Get all MRFs pending director approval
```php
$pendingDirector = MRF::where('workflow_state', 'supply_chain_director_review')
                       ->get();
```

### Get all MRFs approved and ready for procurement
```php
$readyForProcurement = MRF::where('workflow_state', 'supply_chain_director_approved')
                           ->get();
```

---

## Error Handling

### Common Errors

**403 Forbidden** - User role not authorized
```json
{
  "error": "Only Supply Chain Directors can approve at this stage",
  "code": "FORBIDDEN",
  "requiredRole": "supply_chain_director"
}
```

**422 Unprocessable Entity** - MRF not in correct workflow state
```json
{
  "error": "MRF is not awaiting Supply Chain Director review",
  "code": "INVALID_WORKFLOW_STATE",
  "currentWorkflowState": "supply_chain_director_approved",
  "expectedState": "supply_chain_director_review"
}
```

**404 Not Found** - MRF doesn't exist
```json
{
  "error": "MRF not found",
  "code": "NOT_FOUND"
}
```

---

## Testing Examples

### Test 1: Full Happy Path
```bash
# 1. Employee creates MRF
curl -X POST /api/mrfs \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Test MRF",...}'
# → Returns mrf_id

# 2. Supply Chain Director approves
curl -X POST /api/mrfs/{mrf_id}/supply-chain-director-approve \
  -H "Authorization: Bearer $DIRECTOR_TOKEN" \
  -d '{"action":"approve"}'
# → workflow_state = supply_chain_director_approved

# 3. Procurement Manager approves
curl -X POST /api/mrfs/{mrf_id}/procurement-approve \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"action":"approve"}'
# → workflow_state = procurement_approved, status = approved_for_rfq

# 4. Check progress
curl -X GET /api/mrfs/{mrf_id}/progress-tracker \
  -H "Authorization: Bearer $TOKEN"
# → Should show 7 steps, current_step = 4
```

### Test 2: Director Rejection
```bash
curl -X POST /api/mrfs/{mrf_id}/supply-chain-director-approve \
  -H "Authorization: Bearer $DIRECTOR_TOKEN" \
  -d '{"action":"reject","remarks":"Does not align with current budget"}'
# → workflow_state = supply_chain_director_rejected, status = rejected
# → Process terminates
```

---

## Migration Guide (Optional)

### For Existing MRFs in Old Workflow

**Option 1: Keep in old workflow** (automatic))
- They continue with existing executives/chairman approvals
- No action needed

**Option 2: Migrate to new workflow** (manual)
```sql
-- Update MRFs stuck in old workflow
UPDATE m_r_f_s 
SET workflow_state = 'supply_chain_director_review'
WHERE workflow_state = 'executive_review' 
  AND status = 'pending';
```

---

## Performance Considerations

- Index on `workflow_state` for fast filtering: ✅ Already exists
- Index on `status` for fast filtering: ✅ Already exists
- Approval history stored in JSON: ✅ Efficient for audit
- No N+1 queries for approver lookup: ✅ Uses eager loading

---

## Support

For detailed information:
- 📖 [MRF_WORKFLOW_UPDATE_GUIDE.md](MRF_WORKFLOW_UPDATE_GUIDE.md) - Complete documentation
- 🚀 [MRF_DEPLOYMENT_SUMMARY.md](MRF_DEPLOYMENT_SUMMARY.md) - Deployment checklist
- 📚 [DOCUMENTATION_INDEX.md](../DOCUMENTATION_INDEX.md) - All backend docs

---

**Last Updated**: April 1, 2026  
**Workflow Version**: 2.0 (Simplified)  
**Status**: Production Ready
