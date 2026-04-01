# MRF (Material Request Form) Implementation Analysis

**Complete Supply Chain Backend Integration Report**

---

## 1. MRF MODEL AND TABLE

### Model Location
**File:** [app/Models/MRF.php](app/Models/MRF.php)

### Database Table
**Table Name:** `m_r_f_s`

### Table Columns (Current Schema)
The MRF table includes the following key columns:

#### Core MRF Information
- `id` (int, auto-increment)
- `mrf_id` (string, unique) - Format: "MRF-{ContractType}-{Year}-{Sequence}"
- `title` (string)
- `category` (string)
- `contract_type` (enum: 'emerald', 'oando', 'dangote', 'heritage')
- `urgency` (enum: 'Low', 'Medium', 'High', 'Critical')
- `description` (text)
- `quantity` (string)
- `estimated_cost` (decimal:15,2)
- `currency` (string, default: 'NGN')
- `justification` (text)
- `department` (string)
- `date` (date)
- `remarks` (text, nullable)

#### Requester Information
- `requester_id` (bigint, FK to users)
- `requester_name` (string)

#### Status & Workflow Tracking
- `status` (enum: 'pending', 'executive_review', 'chairman_review', 'procurement', 'supply_chain', 'finance', 'chairman_payment', 'completed', 'rejected')
- `current_stage` (string) - Tracks: 'executive', 'procurement', 'supply_chain', etc.
- `workflow_state` (string) - Detailed workflow state tracking
- `approval_history` (json) - Array of approval records

#### Executive Approval Level
- `executive_approved` (boolean, default: false)
- `executive_approved_by` (bigint, FK to users, nullable)
- `executive_approved_at` (timestamp, nullable)
- `executive_remarks` (text, nullable)

#### Chairman Approval Level
- `chairman_approved` (boolean, default: false)
- `chairman_approved_by` (bigint, FK to users, nullable)
- `chairman_approved_at` (timestamp, nullable)
- `chairman_remarks` (text, nullable)

#### Rejection Tracking
- `rejection_reason` (text, nullable)
- `rejection_comments` (text, nullable)
- `rejected_by` (bigint, FK to users, nullable)
- `rejected_at` (timestamp, nullable)
- `is_resubmission` (boolean, default: false)
- `previous_submission_id` (bigint, FK to m_r_f_s, nullable)

#### PO (Purchase Order) Information
- `po_number` (string, nullable)
- `unsigned_po_url` (text, nullable)
- `unsigned_po_share_url` (text, nullable)
- `signed_po_url` (text, nullable)
- `signed_po_share_url` (text, nullable)
- `po_version` (int, default: 1)
- `po_generated_at` (timestamp, nullable)
- `po_signed_at` (timestamp, nullable)

#### PO Details
- `ship_to_address` (text, nullable)
- `tax_rate` (decimal, nullable)
- `tax_amount` (decimal, nullable)
- `po_special_terms` (text, nullable)
- `invoice_submission_email` (string, nullable)
- `invoice_submission_cc` (string, nullable)

#### Payment & Finance
- `payment_status` (enum: 'pending', 'processing', 'approved', 'paid', 'rejected', nullable)
- `payment_processed_at` (timestamp, nullable)
- `payment_approved_at` (timestamp, nullable)
- `payment_approved_by` (bigint, FK to users, nullable)

#### GRN (Goods Received Note)
- `grn_requested` (boolean)
- `grn_requested_at` (timestamp, nullable)
- `grn_requested_by` (bigint, FK to users, nullable)
- `grn_completed` (boolean)
- `grn_completed_at` (timestamp, nullable)
- `grn_completed_by` (bigint, FK to users, nullable)
- `grn_url` (text, nullable)
- `grn_share_url` (text, nullable)

#### Vendor & Invoice Information
- `selected_vendor_id` (bigint, FK to vendors, nullable)
- `invoice_url` (text, nullable)
- `invoice_share_url` (text, nullable)
- `invoice_approved_by` (bigint, FK to users, nullable)
- `invoice_approved_at` (timestamp, nullable)
- `invoice_remarks` (text, nullable)
- `expected_delivery_date` (date, nullable)

#### Document Links
- `pfi_url` (text, nullable) - Proforma Invoice URL
- `pfi_share_url` (text, nullable)

#### Timestamps
- `created_at` (timestamp)
- `updated_at` (timestamp)

### Key Indexes
```sql
INDEX idx_status_date ('status', 'date')
INDEX idx_requester_id ('requester_id')
INDEX idx_status ('status')
INDEX idx_current_stage ('current_stage')
INDEX idx_executive_approved ('executive_approved')
INDEX idx_chairman_approved ('chairman_approved')
INDEX idx_payment_status ('payment_status')
```

### Model Relationships
**File:** [app/Models/MRF.php](app/Models/MRF.php#L120-L150)

```php
// Get the user who requested this MRF
public function requester(): BelongsTo
{
    return $this->belongsTo(User::class, 'requester_id');
}

// Get RFQs created from this MRF
public function rfqs(): HasMany
{
    return $this->hasMany(RFQ::class, 'mrf_id');
}

// Get MRF items
public function items(): HasMany
{
    return $this->hasMany(MRFItem::class, 'mrf_id');
}

// Get approval history
public function approvalHistory(): HasMany
{
    return $this->hasMany(MRFApprovalHistory::class, 'mrf_id')
        ->orderBy('created_at', 'asc');
}
```

---

## 2. RELATED MODELS

### 2.1 MRFItem Model
**File:** [app/Models/MRFItem.php](app/Models/MRFItem.php)

**Table:** `mrf_items`

**Purpose:** Individual line items/products within an MRF

**Columns:**
- `id` (int, auto-increment)
- `mrf_id` (bigint, FK to m_r_f_s)
- `item_name` (string)
- `description` (text, nullable)
- `quantity` (int)
- `unit` (string)
- `unit_price` (decimal:15,2, nullable)
- `total_price` (decimal:15,2, nullable)
- `specifications` (text, nullable)
- `created_at`, `updated_at` (timestamps)

**Relationship:**
```php
public function mrf(): BelongsTo
{
    return $this->belongsTo(MRF::class, 'mrf_id');
}
```

### 2.2 MRFApprovalHistory Model
**File:** [app/Models/MRFApprovalHistory.php](app/Models/MRFApprovalHistory.php)

**Table:** `mrf_approval_history`

**Purpose:** Complete audit trail of all approvals/rejections at each workflow stage

**Columns:**
- `id` (int)
- `mrf_id` (bigint, FK to m_r_f_s)
- `action` (enum: 'approved', 'rejected', 'returned', 'generated_po', 'signed_po', 'rejected_po', 'payment_processed', 'payment_approved')
- `stage` (string) - Which stage: 'executive_review', 'chairman_review', 'procurement', 'supply_chain', etc.
- `performed_by` (bigint, FK to users)
- `performer_name` (string)
- `performer_role` (string)
- `remarks` (text, nullable)
- `created_at`, `updated_at` (timestamps)

**Helper Method:**
```php
public static function record(MRF $mrf, string $action, string $stage, 
                            User $user, ?string $remarks = null): self
{
    return self::create([
        'mrf_id' => $mrf->id,
        'action' => $action,
        'stage' => $stage,
        'performed_by' => $user->id,
        'performer_name' => $user->name,
        'performer_role' => $user->role,
        'remarks' => $remarks,
    ]);
}
```

### 2.3 RFQ Model (Request For Quotation)
**File:** [app/Models/RFQ.php](app/Models/RFQ.php)

**Table:** `r_f_q_s`

**Purpose:** Request for Quotation - created from approved MRF to source vendors

**Key Columns:**
- `rfq_id` (string, unique) - Format: "RFQ-{Year}-{Sequence}"
- `mrf_id` (bigint, FK to m_r_f_s)
- `mrf_title` (string)
- `title` (string)
- `category` (string)
- `description` (text)
- `quantity` (string)
- `estimated_cost` (decimal:15,2)
- `deadline` (date)
- `payment_terms` (string)
- `notes` (text)
- `supporting_documents` (json array)
- `status` (enum: 'Open', 'Closed', 'Awarded', 'Cancelled')
- `workflow_state` (string)
- `created_by` (bigint, FK to users)
- `selected_vendor_id` (bigint, FK to vendors, nullable)
- `selected_quotation_id` (bigint, FK to quotations, nullable)

**Key Relationships:**
```php
public function mrf(): BelongsTo
{
    return $this->belongsTo(MRF::class, 'mrf_id');
}

public function vendors(): BelongsToMany
{
    return $this->belongsToMany(Vendor::class, 'rfq_vendors', 'rfq_id', 'vendor_id')
        ->withPivot(['sent_at', 'viewed_at', 'responded', 'responded_at'])
        ->withTimestamps();
}

public function quotations(): HasMany
{
    return $this->hasMany(Quotation::class, 'rfq_id');
}

public function items(): HasMany
{
    return $this->hasMany(RFQItem::class, 'rfq_id');
}
```

### 2.4 RFQItem Model
**File:** [app/Models/RFQItem.php](app/Models/RFQItem.php)

**Table:** `rfq_items`

**Purpose:** Individual items/specifications within an RFQ

**Columns:**
- `id` (int)
- `rfq_id` (bigint, FK to r_f_q_s)
- `item_name` (string)
- `description` (text, nullable)
- `quantity` (int)
- `unit` (string)
- `specifications` (text, nullable)

### 2.5 Quotation Model (Vendor Quotation/Bid)
**File:** [app/Models/Quotation.php](app/Models/Quotation.php)

**Table:** `quotations`

**Purpose:** Vendor's response/bid to an RFQ

**Key Columns:**
- `quotation_id` (string, unique) - Format: "QUO-{Year}-{Sequence}"
- `rfq_id` (bigint, FK to r_f_q_s)
- `vendor_id` (bigint, FK to vendors, nullable)
- `vendor_name` (string)
- `quote_number` (string)
- `total_amount` (decimal:15,2)
- `currency` (string)
- `price` (decimal:15,2) - Legacy field
- `delivery_days` (int, nullable)
- `delivery_date` (date)
- `payment_terms` (string)
- `validity_days` (int)
- `warranty_period` (string, nullable)
- `attachments` (json array)
- `notes` (text)
- `status` (enum: 'Pending', 'Approved', 'Rejected')
- `review_status` (string) - Additional review tracking
- `rejection_reason` (text, nullable)
- `revision_notes` (text, nullable)
- `approval_remarks` (text, nullable)
- `approved_by` (bigint, FK to users, nullable)
- `approved_at` (timestamp, nullable)
- `submitted_at` (timestamp)
- `reviewed_at` (timestamp, nullable)
- `reviewed_by` (bigint, FK to users, nullable)

**Relationships:**
```php
public function rfq(): BelongsTo
{
    return $this->belongsTo(RFQ::class, 'rfq_id');
}

public function vendor(): BelongsTo
{
    return $this->belongsTo(Vendor::class, 'vendor_id');
}

public function items(): HasMany
{
    return $this->hasMany(QuotationItem::class, 'quotation_id');
}
```

### 2.6 QuotationItem Model
**File:** [app/Models/QuotationItem.php](app/Models/QuotationItem.php)

**Table:** `quotation_items`

**Purpose:** Individual line items in a vendor's quotation

**Columns:**
- `id` (int)
- `quotation_id` (bigint, FK to quotations)
- `item_name` (string)
- `description` (text, nullable)
- `quantity` (int)
- `unit` (string)
- `unit_price` (decimal:15,2)
- `total_price` (decimal:15,2)
- `specifications` (text, nullable)

---

## 3. MRF CONTROLLERS AND ROUTES

### 3.1 MRFController
**File:** [app/Http/Controllers/Api/MRFController.php](app/Http/Controllers/Api/MRFController.php)

**Main Responsibilities:**
- CRUD operations for MRFs
- Viewing and filtering MRFs
- Retrieving full MRF details with quotations
- Managing MRF progress tracking
- Available actions determination

#### Key Methods:

**index()** - Line 60
- Lists all MRFs with filters
- Filters by status, search text, date range
- Role-based filtering: employees see only their own, managers see all
- Returns paginated results

**show($id)** - Line 234
- Retrieve single MRF by ID
- Returns core MRF data with approval information

**getFullDetails(Request $request, $id)** - Line 279
- Returns complete MRF with all RFQs and quotations
- Access: procurement_manager, supply_chain_director, admin only
- Includes quotation comparison data and statistics

**getProgressTracker(Request $request, $id)** - Line 412
- Returns 8-step workflow progress:
  1. MRF Created
  2. Executive Approval
  3. RFQ Issued
  4. Supply Chain Director Approval
  5. Procurement Generates PO
  6. Finance Review & Processing
  7. GRN (Goods Received Note)
  8. Mark as Paid/Closed

**getAvailableActions(Request $request, $id)** - Line 213
- Returns list of actions user can perform on MRF
- Uses PermissionService to determine role-based actions

**store(Request $request)** - Line 1041
- Create new MRF
- Only employees can create MRFs
- Validates contract type (emerald, oando, dangote, heritage)
- Handles optional PFI file upload
- Auto-generates MRF ID with contract type
- Sets initial status to 'pending'
- Sends notification to Executive

**update(Request $request, $id)** - Line ~1200
- Update existing MRF (for same requester or admin)
- Validates fields before update

**approve(Request $request, $id)** - Legacy endpoint
- Deprecated in favor of new workflow routes

**reject(Request $request, $id)** - Legacy endpoint
- Deprecated in favor of new workflow routes

**destroy($id)** - Legacy method
- Delete MRF (admin only)

### 3.2 MRFWorkflowController
**File:** [app/Http/Controllers/Api/MRFWorkflowController.php](app/Http/Controllers/Api/MRFWorkflowController.php)

**Main Responsibilities:**
- Multi-stage approval workflow management
- Executive, Chairman, Procurement approvals
- Vendor selection and PO generation
- Payment processing and GRN management

#### Key Methods:

**procurementApprove(Request $request, $id)** - Line 65
- **Role:** Procurement Manager/Admin
- **Current Status Check:** Must be 'Pending'
- **Action:** Approves MRF and forwards to Executive
- **Status Change:** Pending → Executive Approval
- **Records:** MRFApprovalHistory with stage='procurement'
- **Notification:** Notifies Executives
- **Output:** Returns MRF with updated status and stage

**executiveApprove(Request $request, $id)** - ~Line 130
- **Role:** Executive
- **Approves:** MRF for procurement to proceed
- **Records:** Executive approval timestamp and remarks
- **Flow:** Executive → Chairman (next)

**chairmanApprove(Request $request, $id)** - ~Line 180
- **Role:** Chairman/C-Level
- **Final approval** before procurement can issue RFQ
- **Records:** Chairman approval timestamp
- **Flow:** Chairman → Procurement Team

**sendVendorForApproval(Request $request, $id)** - Line 189
- **Role:** Procurement Manager
- **Precondition:** Executive must have approved first
- **Action:** Selects vendor from quotations and sends to Supply Chain Director
- **Updates:** MRF workflow_state to 'vendor_selected'
- **Notification:** Notifies Supply Chain Director for approval
- **Records:** Complete quotation data in notification

**approveVendorSelection(Request $request, $id)** - ~Line 400
- **Role:** Supply Chain Director/Admin
- **Action:** Approves vendor selection and proceeds with PO generation
- **Triggers:** PO document generation and workflow progression

**rejectVendorSelection(Request $request, $id)** - ~Line 450
- **Role:** Supply Chain Director
- **Action:** Rejects vendor; sends back to procurement for re-evaluation
- **Records:** Reason for rejection

**generatePO(Request $request, $id)** - ~Line 500
- **Role:** Procurement Manager
- **Action:** Generates Purchase Order document (PDF)
- **Inputs:** Vendor info, quotation details, MRF info
- **Output:** S3/Local storage URL for unsigned PO
- **Records:** PO number, generation timestamp

**uploadSignedPO(Request $request, $id)** - ~Line 600
- **Role:** Finance/Procurement
- **Action:** Upload signed PO from vendor
- **Stores:** Both unsigned and signed URLs

**rejectPO(Request $request, $id)** - ~Line 650
- **Role:** Finance/Procurement
- **Action:** Reject PO (vendor signature issues, etc.)
- **Route:** Back to PO regeneration

**processPayment(Request $request, $id)** - ~Line 700
- **Role:** Finance Team
- **Precondition:** Signed PO received
- **Action:** Initiates payment processing
- **Status Change:** payment_status = 'processing'

**approvePayment(Request $request, $id)** - ~Line 750
- **Role:** Finance Manager/Chairman
- **Action:** Approves and finalizes payment
- **Status Change:** payment_status = 'approved'
- **Triggers:** Signals delivery/GRN workflow

**rejectMRF(Request $request, $id)** - ~Line 800
- **Role:** Any approver in workflow
- **Action:** Reject MRF with reason
- **Option:** Mark as resubmission or complete rejection
- **Records:** Rejection details in MRFApprovalHistory

**deletePO()** - ~Line 750 (routes)
- **Role:** Admin/Procurement
- **Action:** Clear/delete PO if not yet signed
- **Effect:** Reverts to pre-PO state for regeneration

---

## 4. API ROUTES

### 4.1 MRF Endpoints
**File:** [routes/api.php](routes/api.php#L171-L201)

#### Basic CRUD Routes (Protected by auth:sanctum)

| Method | Route | Controller | Purpose |
|--------|-------|-----------|---------|
| GET | `/api/mrfs` | MRFController@index | List all MRFs (filtered by role) |
| GET | `/api/mrfs/{id}` | MRFController@show | Get single MRF details |
| GET | `/api/mrfs/{id}/full-details` | MRFController@getFullDetails | Get MRF with all quotations/RFQs |
| GET | `/api/mrfs/{id}/progress-tracker` | MRFController@getProgressTracker | Get 8-step workflow progress |
| GET | `/api/mrfs/{id}/available-actions` | MRFController@getAvailableActions | Get user's available actions |
| POST | `/api/mrfs` | MRFController@store | Create new MRF |
| PUT | `/api/mrfs/{id}` | MRFController@update | Update existing MRF |
| DELETE | `/api/mrfs/{id}` | MRFController@destroy | Delete MRF (admin only) |
| POST | `/api/mrfs/{id}/approve` | MRFController@approve | **LEGACY** - Use workflow routes |
| POST | `/api/mrfs/{id}/reject` | MRFController@reject | **LEGACY** - Use workflow routes |

#### Workflow Routes (Multi-Stage Approval)

| Method | Route | Controller | Purpose | Role |
|--------|-------|-----------|---------|------|
| POST | `/api/mrfs/{id}/procurement-approve` | MRFWorkflowController@procurementApprove | Approve MRF at procurement stage | Procurement Manager |
| POST | `/api/mrfs/{id}/executive-approve` | MRFWorkflowController@executiveApprove | Executive approval | Executive |
| POST | `/api/mrfs/{id}/chairman-approve` | MRFWorkflowController@chairmanApprove | Chairman final approval | Chairman |
| POST | `/api/mrfs/{id}/workflow-reject` | MRFWorkflowController@rejectMRF | Reject at any stage | Any approver |

#### Vendor Selection Routes

| Method | Route | Controller | Purpose | Role |
|--------|-------|-----------|---------|------|
| POST | `/api/mrfs/{id}/send-vendor-for-approval` | MRFWorkflowController@sendVendorForApproval | Send selected vendor to SCD | Procurement Manager |
| POST | `/api/mrfs/{id}/approve-vendor-selection` | MRFWorkflowController@approveVendorSelection | Approve vendor selection | Supply Chain Director |
| POST | `/api/mrfs/{id}/reject-vendor-selection` | MRFWorkflowController@rejectVendorSelection | Reject vendor (re-evaluate) | Supply Chain Director |

#### PO Management Routes

| Method | Route | Controller | Purpose | Role |
|--------|-------|-----------|---------|------|
| POST | `/api/mrfs/{id}/generate-po` | MRFWorkflowController@generatePO | Generate PO PDF | Procurement Manager |
| GET | `/api/mrfs/{id}/download-po` | MRFController@downloadPO | Download unsigned PO | Procurement/Finance |
| GET | `/api/mrfs/{id}/download-signed-po` | MRFController@downloadSignedPO | Download signed PO | All roles |
| DELETE | `/api/mrfs/{id}/po` | MRFWorkflowController@deletePO | Clear/delete PO | Admin |
| POST | `/api/mrfs/{id}/upload-signed-po` | MRFWorkflowController@uploadSignedPO | Upload signed PO from vendor | Finance |
| POST | `/api/mrfs/{id}/reject-po` | MRFWorkflowController@rejectPO | Reject PO (regenerate) | Finance |

#### Payment Routes

| Method | Route | Controller | Purpose | Role |
|--------|-------|-----------|---------|------|
| POST | `/api/mrfs/{id}/process-payment` | MRFWorkflowController@processPayment | Process payment (start) | Finance Team |
| POST | `/api/mrfs/{id}/approve-payment` | MRFWorkflowController@approvePayment | Approve payment (finalize) | Finance Manager |

#### GRN (Goods Received Note) Routes

| Method | Route | Controller | Purpose | Role |
|--------|-------|-----------|---------|------|
| POST | `/api/mrfs/{id}/request-grn` | GRNController@requestGRN | Request GRN from warehouse | Finance/Procurement |
| POST | `/api/mrfs/{id}/complete-grn` | GRNController@completeGRN | Mark goods as received | Warehouse Manager |

---

## 5. CURRENT WORKFLOW & STATUS FLOW

### 5.1 Complete MRF Lifecycle

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         MRF WORKFLOW PIPELINE                                │
└─────────────────────────────────────────────────────────────────────────────┘

STEP 1: CREATION (Employee)
  └─→ Employee creates MRF via POST /api/mrfs
  └─→ MRF enters 'pending' status
  └─→ Notification sent to Executive

STEP 2: EXECUTIVE REVIEW (Executive)
  Status: 'executive_review'
  Workflow State: 'executive_review'
  └─→ Executive reviews MRF details
  └─→ POST /api/mrfs/{id}/executive-approve
  └─→ Records: executive_approved=true, timestamp, remarks
  └─→ Next: Chairman Review or if Executive rejects → Rejection

STEP 3: CHAIRMAN APPROVAL (Chairman/C-Level)
  Status: 'chairman_review'
  Workflow State: 'chairman_review'
  └─→ Chairman final approval
  └─→ POST /api/mrfs/{id}/chairman-approve
  └─→ Records: chairman_approved=true, timestamp, remarks
  └─→ If Rejected: workflow_state='rejected', is_resubmission=true
  └─→ Next: Procurement Review

STEP 4: PROCUREMENT REVIEW (Procurement Manager)
  Status: 'procurement'
  Workflow State: 'procurement_review'
  └─→ Procurement analyzes requirements
  └─→ POST /api/mrfs/{id}/procurement-approve
  └─→ Creates RFQ and vendor outreach
  └─→ Status becomes: 'RFQ Issued' or 'In Progress'
  └─→ Next: Vendor Quotation Phase

STEP 5: VENDOR QUOTATION COLLECTION
  └─→ RFQ sent to selected vendors via rfq_vendors table
  └─→ Vendors submit quotations (Quotation model)
  └─→ Procurement compares bids (via /api/mrfs/{id}/full-details)
  └─→ Next: Vendor Selection

STEP 6: VENDOR SELECTION (Procurement Manager)
  Status: 'vendor_selected'
  Workflow State: 'vendor_selected'
  └─→ Procurement selects best vendor+quotation
  └─→ POST /api/mrfs/{id}/send-vendor-for-approval
  └─→ Sends to Supply Chain Director for approval
  └─→ Next: SCD Approval

STEP 7: SUPPLY CHAIN DIRECTOR APPROVAL
  Status: 'supply_chain'
  Workflow State: 'supply_chain_review'
  └─→ Supply Chain reviews vendor and proposal
  └─→ POST /api/mrfs/{id}/approve-vendor-selection
  └─→ Can reject (POST /api/mrfs/{id}/reject-vendor-selection)
  └─→ Next: PO Generation

STEP 8: PURCHASE ORDER GENERATION (Procurement)
  Status: 'Finance' or 'PO_Generated'
  Workflow State: 'po_created'
  └─→ Procurement generates PO document
  └─→ POST /api/mrfs/{id}/generate-po
  └─→ Stores: po_number, unsigned_po_url, po_generated_at
  └─→ PO sent to vendor for signature
  └─→ Next: PO Signing

STEP 9: PO SIGNATURE & UPLOAD (Finance/Procurement)
  Status: 'PO_Signed' or 'Finance'
  Workflow State: 'po_signed'
  └─→ Vendor signs and returns PO
  └─→ Procurement uploads signed copy
  └─→ POST /api/mrfs/{id}/upload-signed-po
  └─→ Stores: signed_po_url, po_signed_at
  └─→ Can reject PO if issues: POST /api/mrfs/{id}/reject-po
  └─→ Next: Payment Processing

STEP 10: PAYMENT PROCESSING (Finance Team)
  Status: 'Finance'
  Payment Status: 'processing'
  Workflow State: 'payment_processing'
  └─→ Finance initiates payment
  └─→ POST /api/mrfs/{id}/process-payment
  └─→ Next: Payment Approval

STEP 11: PAYMENT APPROVAL (Finance Manager/Chairman)
  Status: 'Finance'
  Payment Status: 'approved'
  Workflow State: 'payment_approved'
  └─→ Final payment approval
  └─→ POST /api/mrfs/{id}/approve-payment
  └─→ Records: payment_approved_at, payment_approved_by
  └─→ Next: GRN Submission

STEP 12: GOODS RECEIVED NOTE (Warehouse)
  Status: 'In Progress' → 'Completed'
  └─→ Goods received from vendor
  └─→ POST /api/mrfs/{id}/request-grn (Finance requests)
  └─→ POST /api/mrfs/{id}/complete-grn (Warehouse confirms)
  └─→ Records: grn_completed=true, grn_url, grn_completed_at
  └─→ Next: Final Completion

STEP 13: COMPLETION & CLOSURE
  Status: 'Completed'
  Workflow State: 'completed'
  └─→ All stages complete
  └─→ MRF archived in system
  └─→ Marked as 'Completed'
```

### 5.2 Status Values
**Current statuses in database enum:**
```
pending
executive_review
chairman_review
procurement
supply_chain
finance
chairman_payment
completed
rejected
```

### 5.3 Workflow States
**Detailed internal state tracking (separate from status):**
```
'initial' / 'created'
'executive_review'
'executive_approved'
'chairman_review'
'chairman_approved'
'procurement_review'
'rfq_issued'
'vendor_selection'
'vendor_selected'
'supply_chain_review'
'vendor_approved'
'po_created'
'po_signed'
'payment_processing'
'payment_approved'
'grn_requested'
'grn_completed'
'completed'
'rejected'
'resubmitted'
```

### 5.4 Rejection & Resubmission Flow
```
At ANY stage, approver can:
├─→ POST /api/mrfs/{id}/workflow-reject
├─→ Specify rejection_reason and rejection_comments
├─→ MRF marked as rejected
├─→ Requester notified
│
If Resubmission:
├─→ Employee corrects MRF
├─→ Creates NEW MRF (mrf_id incremented)
├─→ Sets is_resubmission=true
├─→ Links previous_submission_id to original MRF
├─→ Restarts workflow from Executive Review
```

### 5.5 Role-Based Access in Workflow

| Role | Can Do | Stage |
|------|--------|-------|
| `employee` | Create MRF | Step 1 |
| `executive` | Approve/Reject | Step 2 |
| `chairman` | Approve/Reject | Step 3 |
| `procurement_manager` | Approve, Create RFQ, Select Vendor, Generate PO | Steps 4-8 |
| `supply_chain_director` | Approve Vendor Selection | Step 7 |
| `finance` / `finance_manager` | Process/Approve Payment, Upload PO, Request GRN | Steps 9-12 |
| `logistics_manager` | Complete GRN | Step 12 |
| `admin` | Override any step | All |

---

## 6. NOTIFICATIONS & ROUTING

### 6.1 Notification Service Integration
**Location:** `app/Services/NotificationService.php`

**Direct MRF Notifications:**
1. `notifyMRFSubmitted()` - Sent to Executive when MRF created
2. `notifyMRFForwardedToExecutive()` - When Procurement approves
3. Vendor selection approval notifications sent to Supply Chain Director
4. GRN completion notifications to relevant stakeholders

### 6.2 Notification Triggers by Workflow Stage

| Event | Recipients | Method |
|-------|-----------|--------|
| MRF Created | Executive | `notifyMRFSubmitted()` |
| Executive Approved | Procurement Manager | Auto-notification |
| Chairman Approved | Procurement | Auto-notification |
| Vendor Selected | Supply Chain Director | `sendVendorForApproval()` |
| PO Generated | Finance Team | `generatePO()` |
| PO Signed | Finance Manager | `uploadSignedPO()` |
| Payment Approved | Vendor + Procurement | `approvePayment()` |
| GRN Requested | Warehouse/Logistics | `requestGRN()` |
| GRN Completed | All Stakeholders | `completeGRN()` |

---

## 7. MIGRATIONS & DATABASE SCHEMA EVOLUTION

### 7.1 MRF-Related Migrations
**Location:** [database/migrations/](database/migrations/)

| Migration | Date | Purpose |
|-----------|------|---------|
| [2025_12_23_215044_create_m_r_f_s_table.php](database/migrations/2025_12_23_215044_create_m_r_f_s_table.php) | 12/23/2025 | Initial MRF table creation |
| [2026_01_12_000001_add_approval_workflow_to_mrfs_table.php](database/migrations/2026_01_12_000001_add_approval_workflow_to_mrfs_table.php) | 01/12/2026 | Add multi-stage approval fields (executive, chairman, payment) |
| [2026_01_12_000002_create_mrf_items_table.php](database/migrations/2026_01_12_000002_create_mrf_items_table.php) | 01/12/2026 | Create line items table for MRF |
| [2026_01_12_000003_create_mrf_approval_history_table.php](database/migrations/2026_01_12_000003_create_mrf_approval_history_table.php) | 01/12/2026 | Audit trail for all approvals |
| [2026_01_15_000001_add_sharing_urls_to_mrf_requests.php](database/migrations/2026_01_15_000001_add_sharing_urls_to_mrf_requests.php) | 01/15/2026 | Add PFI share URLs |
| [2026_01_15_161921_add_invoice_fields_to_m_r_f_s_table.php](database/migrations/2026_01_15_161921_add_invoice_fields_to_m_r_f_s_table.php) | 01/15/2026 | Add invoice tracking fields |
| [2026_01_16_000001_add_workflow_state_and_pfi_to_mrfs.php](database/migrations/2026_01_16_000001_add_workflow_state_and_pfi_to_mrfs.php) | 01/16/2026 | Add PFI URLs, workflow_state tracking |
| [2026_01_16_123758_add_contract_type_to_m_r_f_s_table.php](database/migrations/2026_01_16_123758_add_contract_type_to_m_r_f_s_table.php) | 01/16/2026 | Add contract_type enum |
| [2026_01_24_180004_add_po_details_to_m_r_f_s_table.php](database/migrations/2026_01_24_180004_add_po_details_to_m_r_f_s_table.php) | 01/24/2026 | Add PO fields (ship_to, tax, terms) |
| [2026_01_21_135213_add_department_to_m_r_f_s_table.php](database/migrations/2026_01_21_135213_add_department_to_m_r_f_s_table.php) | 01/21/2026 | Add department field |
| [2026_01_19_143900_update_mrf_approval_history_action_enum.php](database/migrations/2026_01_19_143900_update_mrf_approval_history_action_enum.php) | 01/19/2026 | Expand action types in approval history |

### Related Migrations (RFQ, Quotation, Items)

| Migration | Purpose |
|-----------|---------|
| [2025_12_23_215044_create_r_f_q_s_table.php](database/migrations/2025_12_23_215044_create_r_f_q_s_table.php) | RFQ table |
| [2025_12_23_215044_create_quotations_table.php](database/migrations/2025_12_23_215044_create_quotations_table.php) | Quotation table |
| [2026_01_12_000004_create_rfq_items_table.php](database/migrations/2026_01_12_000004_create_rfq_items_table.php) | RFQ line items |
| [2026_01_12_000005_create_quotation_items_table.php](database/migrations/2026_01_12_000005_create_quotation_items_table.php) | Quotation line items |

---

## 8. SUPPORTING SERVICES

### 8.1 WorkflowStateService
**Location:** `app/Services/WorkflowStateService.php`

**Constants:** Workflow state enum values
```php
const STATE_MRF_CREATED = 'mrf_created';
const STATE_EXECUTIVE_REVIEW = 'executive_review';
const STATE_EXECUTIVE_APPROVED = 'executive_approved';
const STATE_PROCUREMENT_REVIEW = 'procurement_review';
const STATE_RFQ_ISSUED = 'rfq_issued';
const STATE_VENDOR_SELECTION = 'vendor_selection';
const STATE_VENDOR_SELECTED = 'vendor_selected';
const STATE_SUPPLY_CHAIN_REVIEW = 'supply_chain_review';
const STATE_VENDOR_APPROVED = 'vendor_approved';
const STATE_PO_CREATED = 'po_created';
const STATE_PO_SIGNED = 'po_signed';
const STATE_PAYMENT_PROCESSING = 'payment_processing';
const STATE_PAYMENT_APPROVED = 'payment_approved';
const STATE_GRN_COMPLETED = 'grn_completed';
const STATE_COMPLETED = 'completed';
const STATE_REJECTED = 'rejected';
```

### 8.2 PermissionService
**Location:** `app/Services/PermissionService.php`

**Responsibility:** Determine role-based actions available to user
- `getAvailableActions(User $user, MRF $mrf)` - Returns list of actions user can perform

### 8.3 NotificationService
**Location:** `app/Services/NotificationService.php`

**Key Methods:**
- `notifyMRFSubmitted(MRF $mrf)` - Alert executives
- `notifyMRFForwardedToExecutive(MRF $mrf, User $approver)` - Chains workflow
- Custom workflow notifications for each stage

### 8.4 EmailService
**Location:** `app/Services/EmailService.php`

**Integration:** Sends emails alongside in-app notifications for critical workflow events

---

## 9. KEY FEATURES & VALIDATIONS

### 9.1 MRF ID Generation
**Method:** `MRF::generateMRFId($contractType)`

**Format:** `MRF-{ContractType}-{Year}-{Sequence}`

Example: `MRF-Emerald-2026-001`

**Contract Types:**
- `emerald`
- `oando`
- `dangote`
- `heritage`

### 9.2 File Storage & S3 Integration
**Disks:** Configurable via `DOCUMENTS_DISK` env variable (default: 's3')

**Uploaded Documents:**
- **PFI (Proforma Invoice):** `pfi_url`, `pfi_share_url`
- **Unsigned PO:** `unsigned_po_url`, `unsigned_po_share_url`
- **Signed PO:** `signed_po_url`, `signed_po_share_url`
- **GRN:** `grn_url`, `grn_share_url`
- **Invoice:** `invoice_url`, `invoice_share_url`

**Temporary URLs:** S3 generates 24-hour signed temporary URLs for secure document access

### 9.3 Currencies
**Default:** NGN (Nigerian Naira)

**Support:** Any 3-character currency code stored in `currency` field

### 9.4 Urgencies
**Levels:**
```
Low
Medium
High
Critical
```

### 9.5 Payment Status Tracking
```
pending (initial)
processing (payment initiated)
approved (authorized)
paid (funds disbursed)
rejected (payment failed)
```

---

## 10. AUDITING & HISTORY TRACKING

### 10.1 MRF Approval History
**Table:** `mrf_approval_history`

**Complete Audit Trail Includes:**
- Who performed action
- What action (approved, rejected, generated_po, etc.)
- When (timestamp)
- Which stage
- Remarks/comments

**Recorded via:** `MRFApprovalHistory::record()` method

### 10.2 Activity Logging
**Model:** Activity (for recent activities dashboard)

**Records:**
- MRF creation
- MRF approvals
- Status changes
- Major workflow transitions

### 10.3 Audit Logs
**Table:** `audit_logs`

**Tracks:** Low-level database changes for compliance

---

## 11. RELATED SUPPORTING MODELS

### 11.1 User Model
**Roles handling MRF workflow:**
- `employee` - Creates MRF
- `executive` - Executive review
- `chairman` - Chairman approval
- `procurement_manager` - Manages procurement stage
-  `procurement` - Alias for procurement_manager
- `supply_chain_director` - Supply chain approval
- `finance` / `finance_manager` - Payment processing
- `admin` - System administrator

### 11.2 Vendor Model
**Integration with MRF:**
- Selected as `selected_vendor_id` in MRF
- Submits quotations for RFQs
- Receives PO and invoice details
- Rating tracked in VendorRating

### 11.3 Quotation Model
**Bidding system for MRF:**
- Multiple quotations per RFQ
- Procurement selects best bid
- Selected quotation ID stored in MRF
- Full item breakdown in QuotationItem

---

## 12. SUMMARY TABLE

| Component | Location | Type |
|-----------|----------|------|
| **MRF Model** | [app/Models/MRF.php](app/Models/MRF.php) | Eloquent Model |
| **MRFItem Model** | [app/Models/MRFItem.php](app/Models/MRFItem.php) | Eloquent Model |
| **MRFApprovalHistory Model** | [app/Models/MRFApprovalHistory.php](app/Models/MRFApprovalHistory.php) | Eloquent Model |
| **RFQ Model** | [app/Models/RFQ.php](app/Models/RFQ.php) | Eloquent Model |
| **Quotation Model** | [app/Models/Quotation.php](app/Models/Quotation.php) | Eloquent Model |
| **MRFController** | [app/Http/Controllers/Api/MRFController.php](app/Http/Controllers/Api/MRFController.php) | Controller |
| **MRFWorkflowController** | [app/Http/Controllers/Api/MRFWorkflowController.php](app/Http/Controllers/Api/MRFWorkflowController.php) | Controller |
| **Routes** | [routes/api.php](routes/api.php#L171-L201) | Route Definition |
| **MRF Initial Migration** | [database/migrations/2025_12_23_215044_create_m_r_f_s_table.php](database/migrations/2025_12_23_215044_create_m_r_f_s_table.php) | Migration |
| **Workflow Migration** | [database/migrations/2026_01_12_000001_add_approval_workflow_to_mrfs_table.php](database/migrations/2026_01_12_000001_add_approval_workflow_to_mrfs_table.php) | Migration |

---

## 13. QUICK REFERENCE: API WORKFLOW SEQUENCE

```bash
# 1. Employee creates MRF
POST /api/mrfs
{
  "title": "Laptops for Office",
  "category": "IT Equipment",
  "contractType": "emerald",
  "urgency": "High",
  "description": "50 laptops for new team",
  "quantity": "50",
  "estimatedCost": 2500000,
  "justification": "Team expansion Q1 2026",
  "department": "Operations"
}
# Response: MRF-Emerald-2026-001

# 2. Executive approves
POST /api/mrfs/MRF-Emerald-2026-001/executive-approve
{
  "remarks": "Approved for procurement"
}

# 3. Chairman approves
POST /api/mrfs/MRF-Emerald-2026-001/chairman-approve
{
  "remarks": "Approved to proceed"
}

# 4. Procurement approves and creates RFQ
POST /api/mrfs/MRF-Emerald-2026-001/procurement-approve
{
  "remarks": "RFQ will be issued"
}

# 5. Get MRF full details with quotations (after vendors bid)
GET /api/mrfs/MRF-Emerald-2026-001/full-details

# 6. Procurement sends vendor for appoval
POST /api/mrfs/MRF-Emerald-2026-001/send-vendor-for-approval
{
  "vendor_id": 15,
  "quotation_id": 42,
  "remarks": "Best bid selected"
}

# 7. Supply Chain Director approves vendor
POST /api/mrfs/MRF-Emerald-2026-001/approve-vendor-selection
{
  "remarks": "Vendor approved"
}

# 8. Procurement generates PO
POST /api/mrfs/MRF-Emerald-2026-001/generate-po

# 9. Finance uploads signed PO
POST /api/mrfs/MRF-Emerald-2026-001/upload-signed-po
{
  "signed_po_file": <file>
}

# 10. Finance processes payment
POST /api/mrfs/MRF-Emerald-2026-001/process-payment

# 11. Finance approves payment
POST /api/mrfs/MRF-Emerald-2026-001/approve-payment

# 12. Request GRN
POST /api/mrfs/MRF-Emerald-2026-001/request-grn

# 13. Complete GRN
POST /api/mrfs/MRF-Emerald-2026-001/complete-grn
```

---

**Document Generated:** 2026-04-01
**Supply Chain Backend Version:** 1.0.1
**Last Updated:** Latest migrations reviewed through 2026-03-31

