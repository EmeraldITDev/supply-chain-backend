# Backend Remaining Tasks - RFQ & PO Workflow Updates

This document outlines all remaining backend tasks required to complete the RFQ and PO workflow implementation based on the frontend changes made.

---

## Table of Contents

1. [Database Schema Updates](#1-database-schema-updates)
2. [MRF Contract Type Support](#2-mrf-contract-type-support)
3. [RFQ API Enhancements](#3-rfq-api-enhancements)
4. [Quotation Review & Iteration](#4-quotation-review--iteration)
5. [Supply Chain Director Workflow](#5-supply-chain-director-workflow)
6. [PO Generation After RFQ Approval](#6-po-generation-after-rfq-approval)
7. [Document Persistence & Accessibility](#7-document-persistence--accessibility)
8. [Permission Service Updates](#8-permission-service-updates)
9. [Workflow State Transitions](#9-workflow-state-transitions)
10. [Vendor Portal Enhancements](#10-vendor-portal-enhancements)

---

## 1. Database Schema Updates

### 1.1 Add Contract Type to MRF Table

**File**: Database migration or `mrf_requests` table schema

```sql
ALTER TABLE mrf_requests 
ADD COLUMN contract_type VARCHAR(50) CHECK (contract_type IN ('emerald', 'oando', 'dangote', 'heritage'));

-- Update MRF ID generation to include contract type prefix
-- Example: MRF-EMERALD-2025-001, MRF-OANDO-2025-001, etc.
```

**Action Required**:
- Add `contract_type` column to `mrf_requests` table
- Update MRF ID generation logic to include contract type prefix
- Update existing MRF creation endpoints to accept and store `contractType`

### 1.2 RFQ Table Enhancements

**File**: `rfqs` table schema

```sql
-- Ensure these columns exist in rfqs table:
ALTER TABLE rfqs 
ADD COLUMN IF NOT EXISTS title VARCHAR(255),
ADD COLUMN IF NOT EXISTS payment_terms TEXT,
ADD COLUMN IF NOT EXISTS notes TEXT,
ADD COLUMN IF NOT EXISTS estimated_cost DECIMAL(15,2);

-- Add workflow state tracking
ALTER TABLE rfqs
ADD COLUMN IF NOT EXISTS workflow_state VARCHAR(50) DEFAULT 'draft' 
  CHECK (workflow_state IN ('draft', 'open', 'quotation_received', 'procurement_review', 'supply_chain_review', 'approved', 'rejected', 'closed'));
```

**Action Required**:
- Verify/Add `title`, `payment_terms`, `notes`, `estimated_cost` columns
- Add `workflow_state` column for tracking RFQ progression
- Update RFQ creation endpoint to accept these fields

### 1.3 Quotation Status Tracking

**File**: `quotations` table schema

```sql
-- Ensure quotation status supports review workflow
ALTER TABLE quotations
ADD COLUMN IF NOT EXISTS review_status VARCHAR(50) DEFAULT 'pending'
  CHECK (review_status IN ('pending', 'under_review', 'approved', 'rejected', 'revision_requested'));

ALTER TABLE quotations
ADD COLUMN IF NOT EXISTS rejection_reason TEXT,
ADD COLUMN IF NOT EXISTS revision_notes TEXT,
ADD COLUMN IF NOT EXISTS reviewed_by UUID REFERENCES users(id),
ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP;
```

**Action Required**:
- Add review status tracking columns
- Support rejection and revision request workflows

---

## 2. MRF Contract Type Support

### 2.1 Update MRF Creation Endpoint

**File**: `app/Http/Controllers/Api/MRFController.php` or equivalent

**Endpoint**: `POST /api/mrfs`

**Changes Required**:
```php
// Accept contractType in request
$request->validate([
    'title' => 'required|string',
    'description' => 'required|string',
    'category' => 'required|string',
    'quantity' => 'required|string',
    'estimatedCost' => 'required|numeric',
    'urgency' => 'required|string|in:low,medium,high,critical',
    'justification' => 'required|string',
    'contractType' => 'required|string|in:emerald,oando,dangote,heritage', // NEW
]);

// Store contractType
$mrf = MRF::create([
    // ... existing fields
    'contract_type' => $request->contractType,
]);

// Update MRF ID generation to include contract type
// Example: MRF-EMERALD-2025-001
$mrfId = 'MRF-' . strtoupper($request->contractType) . '-' . date('Y') . '-' . str_pad($mrf->id, 3, '0', STR_PAD_LEFT);
```

**Action Required**:
- Update MRF creation validation to require `contractType`
- Update MRF ID generation logic to include contract type prefix
- Update MRF update endpoint to allow `contractType` changes (if needed)

### 2.2 Update MRF Response

**File**: MRF model/resource

**Changes Required**:
```php
// Ensure contractType is included in API responses
return [
    'id' => $mrf->id,
    'title' => $mrf->title,
    // ... other fields
    'contractType' => $mrf->contract_type, // NEW
];
```

---

## 3. RFQ API Enhancements

### 3.1 Update RFQ Creation Endpoint

**File**: `app/Http/Controllers/Api/RFQController.php`

**Endpoint**: `POST /api/rfqs`

**Current Expected Request** (from frontend):
```json
{
  "mrfId": "MRF-2025-001",
  "title": "Supply of Computers",
  "description": "Detailed description...",
  "quantity": "50",
  "estimatedCost": "5000000",
  "deadline": "2025-02-15",
  "vendorIds": ["vendor-id-1", "vendor-id-2"],
  "paymentTerms": "Net 30",
  "notes": "Additional notes..."
}
```

**Changes Required**:
```php
$request->validate([
    'mrfId' => 'required|string|exists:mrf_requests,id',
    'title' => 'required|string', // NEW - ensure this is stored
    'description' => 'required|string',
    'quantity' => 'required|string',
    'estimatedCost' => 'required|string|numeric', // NEW - ensure this is stored
    'deadline' => 'required|date',
    'vendorIds' => 'required|array|min:1',
    'vendorIds.*' => 'required|string|exists:vendors,id',
    'paymentTerms' => 'nullable|string', // NEW
    'notes' => 'nullable|string', // NEW
]);

// Create RFQ with all fields
$rfq = RFQ::create([
    'mrf_id' => $request->mrfId,
    'title' => $request->title, // NEW
    'description' => $request->description,
    'quantity' => $request->quantity,
    'estimated_cost' => $request->estimatedCost, // NEW
    'deadline' => $request->deadline,
    'payment_terms' => $request->paymentTerms, // NEW
    'notes' => $request->notes, // NEW
    'status' => 'open',
    'workflow_state' => 'open',
]);

// Create vendor distributions
foreach ($request->vendorIds as $vendorId) {
    RFQVendorDistribution::create([
        'rfq_id' => $rfq->id,
        'vendor_id' => $vendorId,
        'responded' => false,
    ]);
    
    // Send notification to vendor
    // Send email to vendor with full RFQ details
}
```

**Action Required**:
- Update RFQ creation to accept and store `title`, `paymentTerms`, `notes`, `estimatedCost`
- Ensure all RFQ details are included in vendor notifications/emails
- Update RFQ response to include all these fields

### 3.2 Update RFQ Response for Vendor Portal

**Endpoint**: `GET /api/vendors/rfqs` or `GET /api/rfqs/:id`

**Changes Required**:
```php
// Ensure vendor portal receives ALL RFQ details
return [
    'id' => $rfq->id,
    'mrfId' => $rfq->mrf_id,
    'title' => $rfq->title, // NEW - must be visible to vendor
    'description' => $rfq->description,
    'quantity' => $rfq->quantity,
    'estimatedCost' => $rfq->estimated_cost, // NEW - must be visible
    'paymentTerms' => $rfq->payment_terms, // NEW - must be visible
    'deadline' => $rfq->deadline,
    'notes' => $rfq->notes, // NEW - must be visible
    'items' => $rfq->items, // RFQ items
    'status' => $rfq->status,
];
```

**Action Required**:
- Verify vendor portal endpoints return all RFQ details
- Ensure vendors can see title, description, quantity, budget, payment terms, notes

---

## 4. Quotation Review & Iteration

### 4.1 Add Quotation Rejection Endpoint

**File**: `app/Http/Controllers/Api/QuotationController.php`

**Endpoint**: `POST /api/quotations/:id/reject`

**Request Body**:
```json
{
  "reason": "Price too high",
  "comments": "Please revise your quotation"
}
```

**Implementation**:
```php
public function reject(Request $request, $id)
{
    $quotation = Quotation::findOrFail($id);
    
    // Only Procurement Manager can reject
    if (auth()->user()->role !== 'procurement_manager') {
        return response()->json([
            'success' => false,
            'error' => 'Unauthorized'
        ], 403);
    }
    
    $request->validate([
        'reason' => 'required|string',
        'comments' => 'nullable|string',
    ]);
    
    $quotation->update([
        'review_status' => 'rejected',
        'rejection_reason' => $request->reason,
        'revision_notes' => $request->comments,
        'reviewed_by' => auth()->id(),
        'reviewed_at' => now(),
    ]);
    
    // Notify vendor
    // Send email to vendor with rejection reason
    
    return response()->json([
        'success' => true,
        'data' => $quotation
    ]);
}
```

**Action Required**:
- Create quotation rejection endpoint
- Add permission check (Procurement Manager only)
- Send notification/email to vendor
- Update quotation status

### 4.2 Add Revision Request Endpoint

**Endpoint**: `POST /api/quotations/:id/request-revision`

**Request Body**:
```json
{
  "revisionNotes": "Please provide breakdown of costs",
  "deadline": "2025-02-20"
}
```

**Implementation**:
```php
public function requestRevision(Request $request, $id)
{
    $quotation = Quotation::findOrFail($id);
    
    // Only Procurement Manager can request revision
    if (auth()->user()->role !== 'procurement_manager') {
        return response()->json([
            'success' => false,
            'error' => 'Unauthorized'
        ], 403);
    }
    
    $request->validate([
        'revisionNotes' => 'required|string',
        'deadline' => 'nullable|date',
    ]);
    
    $quotation->update([
        'review_status' => 'revision_requested',
        'revision_notes' => $request->revisionNotes,
        'reviewed_by' => auth()->id(),
        'reviewed_at' => now(),
    ]);
    
    // Notify vendor
    // Send email to vendor with revision request
    
    return response()->json([
        'success' => true,
        'data' => $quotation
    ]);
}
```

**Action Required**:
- Create revision request endpoint
- Add permission check
- Send notification/email to vendor
- Allow vendor to resubmit quotation

### 4.3 Update Quotation Submission (Vendor)

**Endpoint**: `POST /api/quotations`

**Changes Required**:
- Allow vendors to resubmit quotations after revision request
- Track quotation version/history
- Ensure documents are persisted and attached to RFQ

```php
// When vendor submits quotation
$quotation = Quotation::create([
    'rfq_id' => $request->rfqId,
    'vendor_id' => auth()->user()->vendor_id,
    // ... other fields
    'review_status' => 'pending', // Reset to pending on resubmission
    'version' => $this->getNextVersion($request->rfqId, auth()->user()->vendor_id),
]);

// Store quotation documents
if ($request->hasFile('attachments')) {
    foreach ($request->file('attachments') as $file) {
        // Upload to storage (OneDrive/S3)
        // Store URL in quotation_documents table
    }
}
```

---

## 5. Supply Chain Director Workflow

### 5.1 Update Vendor Selection Approval Endpoint

**File**: `app/Http/Controllers/Api/MRFWorkflowController.php`

**Endpoint**: `POST /api/mrfs/:id/approve-vendor-selection`

**Current Implementation** (verify and update):
```php
public function approveVendorSelection(Request $request, $id)
{
    $mrf = MRF::findOrFail($id);
    
    // Only Supply Chain Director can approve
    if (auth()->user()->role !== 'supply_chain_director') {
        return response()->json([
            'success' => false,
            'error' => 'Unauthorized'
        ], 403);
    }
    
    $request->validate([
        'remarks' => 'nullable|string',
    ]);
    
    // Update MRF workflow state
    $mrf->update([
        'workflow_state' => 'invoice_approved', // This enables PO generation
        'status' => 'procurement', // Back to procurement for PO generation
    ]);
    
    // Update RFQ status
    $rfq = RFQ::where('mrf_id', $id)->first();
    if ($rfq) {
        $rfq->update([
            'workflow_state' => 'approved',
            'status' => 'awarded',
        ]);
    }
    
    // Notify Procurement Manager
    // Send notification that RFQ is approved, can generate PO
    
    return response()->json([
        'success' => true,
        'data' => $mrf
    ]);
}
```

**Action Required**:
- Verify endpoint exists and works correctly
- Ensure workflow state transitions to `invoice_approved` (not `invoice_received`)
- Update RFQ status to `approved`
- Send notification to Procurement Manager

### 5.2 Update Vendor Selection Rejection Endpoint

**Endpoint**: `POST /api/mrfs/:id/reject-vendor-selection`

**Implementation**:
```php
public function rejectVendorSelection(Request $request, $id)
{
    $mrf = MRF::findOrFail($id);
    
    // Only Supply Chain Director can reject
    if (auth()->user()->role !== 'supply_chain_director') {
        return response()->json([
            'success' => false,
            'error' => 'Unauthorized'
        ], 403);
    }
    
    $request->validate([
        'reason' => 'required|string',
        'remarks' => 'nullable|string',
    ]);
    
    // Update MRF workflow state
    $mrf->update([
        'workflow_state' => 'procurement_review', // Back to procurement
        'status' => 'procurement',
    });
    
    // Update RFQ status
    $rfq = RFQ::where('mrf_id', $id)->first();
    if ($rfq) {
        $rfq->update([
            'workflow_state' => 'procurement_review',
        ]);
    }
    
    // Notify Procurement Manager
    // Send notification with rejection reason
    
    return response()->json([
        'success' => true,
        'data' => $mrf
    ]);
}
```

**Action Required**:
- Verify/Update rejection endpoint
- Ensure proper workflow state transitions
- Send notification to Procurement Manager

---

## 6. PO Generation After RFQ Approval

### 6.1 Update Permission Service

**File**: `app/Services/PermissionService.php`

**Method**: `canGeneratePO($mrf, $user)`

**Current Logic** (verify and update):
```php
public function canGeneratePO($mrf, $user)
{
    // Only Procurement Manager can generate PO
    if ($user->role !== 'procurement_manager') {
        return false;
    }
    
    // Check if MRF is in correct state
    $allowedStates = [
        'executive_approved',
        'procurement_review',
        'vendor_selected',
        'invoice_received',
        'invoice_approved', // NEW - After Supply Chain Director approval
    ];
    
    if (!in_array($mrf->workflow_state, $allowedStates)) {
        return false;
    }
    
    // NEW: Check if RFQ exists and is approved
    $rfq = RFQ::where('mrf_id', $mrf->id)->first();
    if ($rfq && $rfq->workflow_state !== 'approved') {
        return false; // Cannot generate PO until RFQ is approved
    }
    
    return true;
}
```

**Action Required**:
- Update `canGeneratePO` to check RFQ approval status
- Ensure PO generation is only allowed after Supply Chain Director approves RFQ
- Update available actions endpoint to reflect this

### 6.2 Update PO Generation Endpoint

**File**: `app/Http/Controllers/Api/MRFWorkflowController.php`

**Endpoint**: `POST /api/mrfs/:id/generate-po`

**Changes Required**:
```php
public function generatePO(Request $request, $id)
{
    $mrf = MRF::findOrFail($id);
    
    // Check permissions
    if (!PermissionService::canGeneratePO($mrf, auth()->user())) {
        return response()->json([
            'success' => false,
            'error' => 'PO generation not allowed at this stage'
        ], 403);
    }
    
    // NEW: Verify RFQ is approved
    $rfq = RFQ::where('mrf_id', $id)->first();
    if (!$rfq || $rfq->workflow_state !== 'approved') {
        return response()->json([
            'success' => false,
            'error' => 'RFQ must be approved by Supply Chain Director before generating PO'
        ], 400);
    }
    
    // Get selected vendor and quotation
    $selectedQuotation = Quotation::find($rfq->selected_quotation_id);
    $selectedVendor = Vendor::find($rfq->selected_vendor_id);
    
    // Generate PO with vendor quotation details
    // ... existing PO generation logic
    
    // Update MRF workflow state
    $mrf->update([
        'workflow_state' => 'po_generated',
        'status' => 'supply_chain', // Move to Supply Chain for signing
    ]);
    
    // Notify Supply Chain Director
    // Send notification that PO is ready for signing
    
    return response()->json([
        'success' => true,
        'data' => $mrf
    ]);
}
```

**Action Required**:
- Add RFQ approval check before PO generation
- Ensure selected vendor and quotation are attached to PO
- Update workflow state correctly

---

## 7. Document Persistence & Accessibility

### 7.1 Ensure Document Storage

**Action Required**:
- Verify all document uploads (MRF PFI, RFQ attachments, Quotation documents, PO files, GRN) are stored persistently
- Documents should never be deleted during workflow progression
- Use permanent storage (OneDrive/S3) not temporary storage

### 7.2 Document Access Control

**File**: Document access endpoints

**Implementation**:
```php
// Ensure documents are accessible based on user role and workflow stage
public function getMRFDocuments($mrfId)
{
    $mrf = MRF::findOrFail($mrfId);
    $user = auth()->user();
    
    // Check if user has access to this MRF
    if (!PermissionService::canViewMRF($mrf, $user)) {
        return response()->json([
            'success' => false,
            'error' => 'Unauthorized'
        ], 403);
    }
    
    // Return all documents associated with MRF
    return response()->json([
        'success' => true,
        'data' => [
            'mrf_documents' => $mrf->documents, // PFI, etc.
            'rfq_documents' => $mrf->rfq->documents ?? [], // RFQ attachments
            'quotation_documents' => $mrf->rfq->quotations->pluck('documents')->flatten(), // Quotation attachments
            'po_documents' => [
                'unsigned_po_url' => $mrf->unsigned_po_url,
                'signed_po_url' => $mrf->signed_po_url,
            ],
            'grn_documents' => [
                'grn_url' => $mrf->grn_url,
            ],
        ]
    ]);
}
```

**Action Required**:
- Create/Update document access endpoints
- Ensure role-based access control
- Return all documents at every workflow stage

### 7.3 Update MRF Response to Include Document URLs

**File**: MRF model/resource

**Changes Required**:
```php
// Ensure all document URLs are included in MRF response
return [
    'id' => $mrf->id,
    // ... other fields
    'pfiShareUrl' => $mrf->pfi_share_url,
    'unsignedPOUrl' => $mrf->unsigned_po_url,
    'signedPOUrl' => $mrf->signed_po_url,
    'grnShareUrl' => $mrf->grn_share_url,
    // Include RFQ and quotation documents
    'rfq' => $mrf->rfq ? [
        'id' => $mrf->rfq->id,
        'documents' => $mrf->rfq->documents,
        'quotations' => $mrf->rfq->quotations->map(function($q) {
            return [
                'id' => $q->id,
                'vendorName' => $q->vendor->name,
                'documents' => $q->documents,
            ];
        }),
    ] : null,
];
```

---

## 8. Permission Service Updates

### 8.1 Update Available Actions Endpoint

**File**: `app/Http/Controllers/Api/MRFWorkflowController.php`

**Endpoint**: `GET /api/mrfs/:id/available-actions`

**Changes Required**:
```php
public function getAvailableActions($id)
{
    $mrf = MRF::findOrFail($id);
    $user = auth()->user();
    
    $actions = PermissionService::getAvailableActions($mrf, $user);
    
    // NEW: Check RFQ status for PO generation
    $rfq = RFQ::where('mrf_id', $id)->first();
    if ($rfq) {
        // Only allow PO generation if RFQ is approved
        if ($rfq->workflow_state !== 'approved') {
            $actions['canGeneratePO'] = false;
            $actions['availableActions'] = array_filter(
                $actions['availableActions'],
                fn($action) => $action !== 'generate_po'
            );
        }
    }
    
    return response()->json([
        'success' => true,
        'data' => $actions
    ]);
}
```

**Action Required**:
- Update available actions to check RFQ approval status
- Ensure `canGeneratePO` is false until RFQ is approved

### 8.2 Update Permission Checks

**File**: `app/Services/PermissionService.php`

**Methods to Update**:
- `canGeneratePO()` - Check RFQ approval
- `canSelectVendors()` - Verify RFQ exists
- `canApproveInvoice()` - Supply Chain Director only
- `canViewInvoices()` - Based on role and workflow stage

---

## 9. Workflow State Transitions

### 9.1 MRF Workflow States

**Expected State Flow**:
```
mrf_created → executive_review → executive_approved → 
procurement_review → vendor_selected → 
supply_chain_review → invoice_approved → 
po_generated → po_signed → 
payment_processed → grn_requested → grn_completed → closed
```

**Action Required**:
- Verify all state transitions are correct
- Ensure RFQ approval sets state to `invoice_approved`
- Ensure PO generation sets state to `po_generated`

### 9.2 RFQ Workflow States

**Expected State Flow**:
```
draft → open → quotation_received → 
procurement_review → supply_chain_review → 
approved → (PO generated) → closed
```

**Action Required**:
- Implement RFQ workflow state tracking
- Update state on quotation submission
- Update state on Supply Chain Director approval

---

## 10. Vendor Portal Enhancements

### 10.1 RFQ Display

**Endpoint**: `GET /api/vendors/rfqs`

**Action Required**:
- Ensure all RFQ details are returned:
  - Title
  - Description
  - Quantity
  - Estimated budget
  - Payment terms
  - Notes
  - Deadline
  - Items/specifications

### 10.2 Quotation Submission

**Endpoint**: `POST /api/quotations`

**Action Required**:
- Ensure vendors can upload quotation documents
- Documents must be persisted and attached to RFQ
- Support resubmission after revision request

### 10.3 Quotation Status

**Endpoint**: `GET /api/vendors/quotations`

**Action Required**:
- Return quotation status (pending, approved, rejected, revision_requested)
- Include rejection reason and revision notes
- Allow vendors to view their quotation history

---

## Summary Checklist

### Database
- [ ] Add `contract_type` to `mrf_requests` table
- [ ] Update MRF ID generation to include contract type
- [ ] Add `title`, `payment_terms`, `notes`, `estimated_cost` to `rfqs` table
- [ ] Add `workflow_state` to `rfqs` table
- [ ] Add `review_status`, `rejection_reason`, `revision_notes` to `quotations` table

### API Endpoints
- [ ] Update `POST /api/mrfs` to accept `contractType`
- [ ] Update `POST /api/rfqs` to accept `title`, `paymentTerms`, `notes`, `estimatedCost`
- [ ] Create `POST /api/quotations/:id/reject`
- [ ] Create `POST /api/quotations/:id/request-revision`
- [ ] Update `POST /api/mrfs/:id/approve-vendor-selection` to set `invoice_approved` state
- [ ] Update `POST /api/mrfs/:id/generate-po` to check RFQ approval
- [ ] Create/Update document access endpoints

### Permission Service
- [ ] Update `canGeneratePO()` to check RFQ approval
- [ ] Update available actions endpoint
- [ ] Verify role-based access controls

### Workflow
- [ ] Verify MRF workflow state transitions
- [ ] Implement RFQ workflow state tracking
- [ ] Ensure proper state transitions on approvals/rejections

### Notifications
- [ ] Send notification when RFQ is created
- [ ] Send notification when quotation is rejected
- [ ] Send notification when revision is requested
- [ ] Send notification when RFQ is approved by Supply Chain Director
- [ ] Send notification when PO can be generated

### Testing
- [ ] Test MRF creation with contract type
- [ ] Test RFQ creation with all fields
- [ ] Test quotation rejection workflow
- [ ] Test revision request workflow
- [ ] Test Supply Chain Director approval
- [ ] Test PO generation after RFQ approval
- [ ] Test document accessibility at all stages

---

## Notes

- All endpoints should return consistent JSON responses: `{ success: boolean, data?: any, error?: string }`
- All endpoints should validate user permissions
- All state transitions should send appropriate notifications
- All documents should be stored permanently and remain accessible
- All workflow states should be tracked and auditable

---

**Last Updated**: Based on frontend changes completed on [Current Date]
**Status**: Ready for backend implementation
