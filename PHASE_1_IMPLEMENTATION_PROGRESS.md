# Phase 1: MRF Multi-Stage Approval Workflow - Implementation Progress

**Started:** January 12, 2026  
**Status:** 🟡 In Progress - 70% Complete

---

## ✅ Completed Tasks

### 1. Database Migrations ✅
- ✅ `2026_01_12_000001_add_approval_workflow_to_mrfs_table.php`
  - Added executive approval fields
  - Added chairman approval fields
  - Added PO workflow fields (po_number, URLs, version, timestamps)
  - Added enhanced rejection tracking
  - Added payment status and approval fields
  - Added currency field
  - Updated status enum with all workflow stages

- ✅ `2026_01_12_000002_create_mrf_items_table.php`
  - Created table for MRF line items
  - Supports itemized requisitions

- ✅ `2026_01_12_000003_create_mrf_approval_history_table.php`
  - Created audit trail table
  - Tracks all actions (approved, rejected, generated_po, signed_po, etc.)
  - Records performer details and timestamps

### 2. Models ✅
- ✅ `MRFItem.php` - Model for MRF items
- ✅ `MRFApprovalHistory.php` - Model for approval tracking
- ✅ Updated `MRF.php` model with:
  - All new fillable fields
  - Proper casting for dates and booleans
  - New relationships:
    - `items()` - hasMany MRFItem
    - `approvalHistory()` - hasMany MRFApprovalHistory
    - `executiveApprover()` - belongsTo User
    - `chairmanApprover()` - belongsTo User
    - `rejector()` - belongsTo User
    - `previousSubmission()` - belongsTo MRF (for resubmissions)
    - `paymentApprover()` - belongsTo User

### 3. Controllers ✅
- ✅ Created `MRFWorkflowController.php` with complete approval chain:
  - `executiveApprove()` - Executive approval with >1M threshold check
  - `chairmanApprove()` - Chairman approval for high-value MRFs
  - `generatePO()` - PO generation by procurement
  - `uploadSignedPO()` - Supply Chain Director signs PO
  - `rejectPO()` - Supply Chain Director rejects PO (returns to procurement)
  - `processPayment()` - Finance marks for payment
  - `approvePayment()` - Chairman final payment approval
  - `rejectMRF()` - Rejection at any stage

### 4. Routes ✅
- ✅ Added 8 new workflow routes to `routes/api.php`:
  - `POST /api/mrfs/{id}/executive-approve`
  - `POST /api/mrfs/{id}/chairman-approve`
  - `POST /api/mrfs/{id}/generate-po`
  - `POST /api/mrfs/{id}/upload-signed-po`
  - `POST /api/mrfs/{id}/reject-po`
  - `POST /api/mrfs/{id}/process-payment`
  - `POST /api/mrfs/{id}/approve-payment`
  - `POST /api/mrfs/{id}/workflow-reject`

### 5. Notification Service ✅
- ✅ Added 7 new notification methods:
  - `notifyMRFPendingChairmanApproval()` - When >1M MRF needs chairman
  - `notifyMRFPendingProcurement()` - When MRF approved, ready for PO
  - `notifyPOReadyForSignature()` - When PO generated
  - `notifyPOSignedToFinance()` - When PO signed
  - `notifyPORejectedToProcurement()` - When PO rejected
  - `notifyPaymentPendingChairman()` - When payment needs approval
  - `notifyMRFCompleted()` - When workflow completed

---

## 🟡 In Progress / Remaining Tasks

### 1. Email Templates (0% Complete) 🔴
**Priority: HIGH**

Need to create Blade email templates:
- ❌ `mrf-pending-executive-approval.blade.php`
- ❌ `mrf-pending-chairman-approval.blade.php`
- ❌ `mrf-executive-approved.blade.php`
- ❌ `mrf-chairman-approved.blade.php`
- ❌ `po-ready-for-signature.blade.php`
- ❌ `po-signed.blade.php`
- ❌ `po-rejected.blade.php`
- ❌ `payment-pending-chairman.blade.php`
- ❌ `payment-approved.blade.php`
- ❌ `mrf-workflow-completed.blade.php`

### 2. EmailService Integration (0% Complete) 🔴
**Priority: HIGH**

Need to integrate email sending in workflow:
- ❌ Add methods to `EmailService.php` for each workflow stage
- ❌ Call email service methods in `MRFWorkflowController`
- ❌ Test email delivery

### 3. PO PDF Generation (0% Complete) 🟡
**Priority: MEDIUM**

Current implementation has placeholder:
- ❌ Install PDF generation library (dompdf or snappy)
- ❌ Create PO template
- ❌ Implement `generatePODocument()` method properly
- ❌ Include MRF items, company details, terms, etc.

### 4. Update MRFController (Not Started) 🟡
**Priority: MEDIUM**

Need to update existing controller:
- ❌ Update `store()` to handle items array
- ❌ Update `show()` to include items and approval history
- ❌ Update `index()` response format to include new fields
- ❌ Deprecate old `approve()` and `reject()` methods (or keep as simple approval)

### 5. Testing (Not Started) 🔵
**Priority: LOW** (but important!)

- ❌ Unit tests for models
- ❌ Integration tests for workflow
- ❌ Test complete MRF lifecycle
- ❌ Test cost threshold logic (>1M vs <=1M)
- ❌ Test PO rejection flow
- ❌ Test payment approval flow

---

## 🎯 MRF Workflow State Machine (Implemented)

```
┌─────────┐
│ pending │
└────┬────┘
     │
     ▼
┌──────────────────┐
│executive_review  │ ◄─── User submits MRF
└────┬─────────────┘
     │
     ├─── (cost <= 1M) ───┐
     │                    │
     └─── (cost > 1M) ────┼──► ┌──────────────────┐
                          │    │ chairman_review  │
                          │    └────┬─────────────┘
                          │         │
                          ▼         ▼
                     ┌──────────────┐
                     │ procurement  │ ◄─── Generate PO
                     └──────┬───────┘
                            │
                            ▼
                     ┌──────────────┐
                     │supply_chain  │ ◄─── Sign PO or Reject (return to procurement)
                     └──────┬───────┘
                            │
                            ▼
                     ┌──────────────┐
                     │   finance    │ ◄─── Process payment
                     └──────┬───────┘
                            │
                            ▼
                     ┌──────────────────┐
                     │chairman_payment  │ ◄─── Approve payment
                     └──────┬───────────┘
                            │
                            ▼
                     ┌──────────────┐
                     │  completed   │ ◄─── MRF workflow done
                     └──────────────┘
                     
                     At any approval stage:
                     └──► ┌──────────┐
                          │ rejected │
                          └──────────┘
```

---

## 📋 API Endpoint Usage Examples

### 1. Executive Approve MRF
```bash
POST /api/mrfs/MRF-2026-001/executive-approve
Authorization: Bearer <token>
Content-Type: application/json

{
  "remarks": "Approved for procurement"
}

# Response:
{
  "success": true,
  "message": "MRF approved by executive",
  "data": {
    "mrf_id": "MRF-2026-001",
    "status": "procurement",  // or "chairman_review" if >1M
    "current_stage": "procurement",
    "next_approver": "Procurement Manager"  // or "Chairman"
  }
}
```

### 2. Chairman Approve MRF (High-Value)
```bash
POST /api/mrfs/MRF-2026-001/chairman-approve
Authorization: Bearer <token>
Content-Type: application/json

{
  "remarks": "Approved for high-value procurement"
}
```

### 3. Generate PO (Procurement)
```bash
POST /api/mrfs/MRF-2026-001/generate-po
Authorization: Bearer <token>
Content-Type: application/json

{
  "po_number": "PO-2026-001"
}

# Response:
{
  "success": true,
  "message": "PO generated successfully",
  "data": {
    "mrf_id": "MRF-2026-001",
    "po_number": "PO-2026-001",
    "unsigned_po_url": "https://s3.amazonaws.com/.../po_PO-2026-001_1234567890.pdf",
    "status": "supply_chain"
  }
}
```

### 4. Upload Signed PO (Supply Chain Director)
```bash
POST /api/mrfs/MRF-2026-001/upload-signed-po
Authorization: Bearer <token>
Content-Type: multipart/form-data

signed_po: <PDF file>

# Response:
{
  "success": true,
  "message": "Signed PO uploaded successfully",
  "data": {
    "mrf_id": "MRF-2026-001",
    "signed_po_url": "https://s3.amazonaws.com/.../po_signed_PO-2026-001_1234567890.pdf",
    "status": "finance"
  }
}
```

### 5. Reject PO (Supply Chain Director)
```bash
POST /api/mrfs/MRF-2026-001/reject-po
Authorization: Bearer <token>
Content-Type: application/json

{
  "reason": "Vendor information incomplete",
  "comments": "Please add vendor contact details and delivery terms"
}

# Response:
{
  "success": true,
  "message": "PO rejected and returned to procurement",
  "data": {
    "mrf_id": "MRF-2026-001",
    "status": "procurement",
    "po_version": 2  // Incremented
  }
}
```

### 6. Process Payment (Finance)
```bash
POST /api/mrfs/MRF-2026-001/process-payment
Authorization: Bearer <token>

# Response:
{
  "success": true,
  "message": "Payment sent for chairman approval",
  "data": {
    "mrf_id": "MRF-2026-001",
    "status": "chairman_payment",
    "payment_status": "processing"
  }
}
```

### 7. Approve Payment (Chairman)
```bash
POST /api/mrfs/MRF-2026-001/approve-payment
Authorization: Bearer <token>

# Response:
{
  "success": true,
  "message": "Payment approved - MRF workflow completed",
  "data": {
    "mrf_id": "MRF-2026-001",
    "status": "completed",
    "payment_status": "approved",
    "completed_at": "2026-01-12T14:30:00Z"
  }
}
```

### 8. Reject MRF (Any Approval Stage)
```bash
POST /api/mrfs/MRF-2026-001/workflow-reject
Authorization: Bearer <token>
Content-Type: application/json

{
  "reason": "Budget not available",
  "comments": "Please resubmit next quarter"
}

# Response:
{
  "success": true,
  "message": "MRF rejected",
  "data": {
    "mrf_id": "MRF-2026-001",
    "status": "rejected",
    "rejection_reason": "Budget not available"
  }
}
```

---

## 🔧 Database Schema Changes

### MRF Table (m_r_f_s) - New Fields

| Field | Type | Purpose |
|-------|------|---------|
| `status` | ENUM | Updated to include all workflow stages |
| `currency` | VARCHAR(3) | Currency code (e.g., 'NGN', 'USD') |
| `executive_approved` | BOOLEAN | Executive approval flag |
| `executive_approved_by` | FK to users | Who approved |
| `executive_approved_at` | TIMESTAMP | When approved |
| `executive_remarks` | TEXT | Executive remarks |
| `chairman_approved` | BOOLEAN | Chairman approval flag |
| `chairman_approved_by` | FK to users | Who approved |
| `chairman_approved_at` | TIMESTAMP | When approved |
| `chairman_remarks` | TEXT | Chairman remarks |
| `po_number` | VARCHAR(50) | Purchase order number |
| `unsigned_po_url` | TEXT | S3 URL of unsigned PO |
| `signed_po_url` | TEXT | S3 URL of signed PO |
| `po_version` | INTEGER | PO version (increments on rejection) |
| `po_generated_at` | TIMESTAMP | When PO was generated |
| `po_signed_at` | TIMESTAMP | When PO was signed |
| `rejection_comments` | TEXT | Detailed rejection comments |
| `rejected_by` | FK to users | Who rejected |
| `rejected_at` | TIMESTAMP | When rejected |
| `previous_submission_id` | FK to m_r_f_s | Link to previous submission (if resubmitted) |
| `payment_status` | ENUM | Payment status (pending, processing, approved, paid, rejected) |
| `payment_approved_at` | TIMESTAMP | When payment approved |
| `payment_approved_by` | FK to users | Who approved payment |

---

## 🚀 Next Steps

### Immediate (Next 1-2 Days)
1. ✅ Create email templates for workflow notifications
2. ✅ Integrate EmailService into workflow controller
3. ✅ Test workflow on local/staging environment

### Short Term (Next Week)
1. Implement proper PO PDF generation
2. Update existing MRFController to support items
3. Add comprehensive error handling

### Before Production Deployment
1. Write unit and integration tests
2. Test complete lifecycle multiple times
3. Load test with multiple concurrent approvals
4. Update API documentation
5. Train users on new workflow

---

## 📝 Notes

- **Backward Compatibility:** Old `approve()` and `reject()` methods in MRFController are kept for legacy support. New workflow uses dedicated endpoints.
- **Role Requirements:** Ensure users have proper roles assigned (executive, chairman, procurement_manager, supply_chain_director, finance).
- **S3 Configuration:** Ensure AWS S3 credentials are configured in `.env` for PO document storage.
- **Cost Threshold:** The >1M threshold for chairman approval is hardcoded. Consider making this configurable.

---

**Last Updated:** January 12, 2026  
**Implementation By:** AI Assistant  
**Review Status:** Pending team review
