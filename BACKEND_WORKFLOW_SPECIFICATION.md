# Backend Workflow Specification for SCM System

This document provides complete specifications for implementing MRF, RFQ, SRF workflows and approval chains. The frontend is built with React/TypeScript and expects RESTful JSON APIs.

## Database Connection

- **API Base URL**: `https://supply-chain-backend-hwh6.onrender.com/api`
- **Authentication**: JWT Bearer tokens (shared with HR platform)
- **Database**: PostgreSQL (shared with HR platform for user credentials)

---

## 1. DATABASE SCHEMA REQUIREMENTS

### 1.1 MRF (Material Requisition Form) Table

```sql
CREATE TABLE mrf_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    mrn_id UUID REFERENCES mrn_requests(id),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    justification TEXT,
    category VARCHAR(100),
    urgency VARCHAR(20) CHECK (urgency IN ('low', 'medium', 'high', 'critical')),
    estimated_cost DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'NGN',
    requester_id UUID REFERENCES users(id),
    requester_name VARCHAR(255),
    department VARCHAR(100),
    
    -- Workflow status
    status VARCHAR(50) DEFAULT 'pending' CHECK (status IN (
        'pending', 'executive_review', 'chairman_review', 
        'procurement', 'supply_chain', 'finance', 
        'chairman_payment', 'completed', 'rejected'
    )),
    current_stage VARCHAR(50),
    
    -- Approval tracking
    executive_approved BOOLEAN DEFAULT FALSE,
    executive_approved_by UUID REFERENCES users(id),
    executive_approved_at TIMESTAMP,
    executive_remarks TEXT,
    
    chairman_approved BOOLEAN DEFAULT FALSE,
    chairman_approved_by UUID REFERENCES users(id),
    chairman_approved_at TIMESTAMP,
    chairman_remarks TEXT,
    
    -- PO Information
    po_number VARCHAR(50),
    unsigned_po_url TEXT,
    signed_po_url TEXT,
    po_version INTEGER DEFAULT 1,
    po_generated_at TIMESTAMP,
    po_signed_at TIMESTAMP,
    
    -- Rejection tracking
    rejection_reason TEXT,
    rejection_comments TEXT,
    rejected_by UUID REFERENCES users(id),
    rejected_at TIMESTAMP,
    is_resubmission BOOLEAN DEFAULT FALSE,
    previous_submission_id UUID REFERENCES mrf_requests(id),
    
    -- Finance & Payment
    payment_status VARCHAR(50),
    payment_approved_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Index for performance
CREATE INDEX idx_mrf_status ON mrf_requests(status);
CREATE INDEX idx_mrf_requester ON mrf_requests(requester_id);
CREATE INDEX idx_mrf_current_stage ON mrf_requests(current_stage);
```

### 1.2 MRF Items Table

```sql
CREATE TABLE mrf_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    mrf_id UUID REFERENCES mrf_requests(id) ON DELETE CASCADE,
    item_name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INTEGER NOT NULL,
    unit VARCHAR(50),
    unit_price DECIMAL(15,2),
    total_price DECIMAL(15,2),
    specifications TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 1.3 MRF Approval History Table

```sql
CREATE TABLE mrf_approval_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    mrf_id UUID REFERENCES mrf_requests(id) ON DELETE CASCADE,
    action VARCHAR(50) NOT NULL, -- 'approved', 'rejected', 'returned'
    stage VARCHAR(50) NOT NULL,
    performed_by UUID REFERENCES users(id),
    performer_name VARCHAR(255),
    performer_role VARCHAR(100),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 1.4 RFQ (Request for Quotation) Table

```sql
CREATE TABLE rfq_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rfq_number VARCHAR(50) UNIQUE NOT NULL,
    mrf_id UUID REFERENCES mrf_requests(id),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    
    -- Dates
    issue_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deadline TIMESTAMP NOT NULL,
    
    -- Status
    status VARCHAR(50) DEFAULT 'open' CHECK (status IN (
        'draft', 'open', 'closed', 'awarded', 'cancelled'
    )),
    
    -- Selection
    selected_vendor_id UUID REFERENCES vendors(id),
    selected_quotation_id UUID REFERENCES quotations(id),
    
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- RFQ Items
CREATE TABLE rfq_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rfq_id UUID REFERENCES rfq_requests(id) ON DELETE CASCADE,
    item_name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INTEGER NOT NULL,
    unit VARCHAR(50),
    specifications TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- RFQ Vendor Distribution (which vendors received this RFQ)
CREATE TABLE rfq_vendor_distribution (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rfq_id UUID REFERENCES rfq_requests(id) ON DELETE CASCADE,
    vendor_id UUID REFERENCES vendors(id),
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    viewed_at TIMESTAMP,
    responded BOOLEAN DEFAULT FALSE,
    responded_at TIMESTAMP
);
```

### 1.5 Quotations Table

```sql
CREATE TABLE quotations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    rfq_id UUID REFERENCES rfq_requests(id),
    vendor_id UUID REFERENCES vendors(id),
    
    -- Quote details
    quote_number VARCHAR(50),
    total_amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'NGN',
    
    -- Terms
    delivery_days INTEGER,
    delivery_date DATE,
    payment_terms VARCHAR(255),
    validity_days INTEGER DEFAULT 30,
    warranty_period VARCHAR(100),
    
    -- Status
    status VARCHAR(50) DEFAULT 'submitted' CHECK (status IN (
        'draft', 'submitted', 'under_review', 'approved', 'rejected', 'expired'
    )),
    
    -- Attachments
    attachments JSONB DEFAULT '[]',
    
    notes TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP,
    reviewed_by UUID REFERENCES users(id),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Quotation Line Items
CREATE TABLE quotation_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    quotation_id UUID REFERENCES quotations(id) ON DELETE CASCADE,
    rfq_item_id UUID REFERENCES rfq_items(id),
    item_name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INTEGER NOT NULL,
    unit VARCHAR(50),
    unit_price DECIMAL(15,2) NOT NULL,
    total_price DECIMAL(15,2) NOT NULL,
    specifications TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 1.6 SRF (Service Requisition Form) Table

```sql
CREATE TABLE srf_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    srf_number VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    service_type VARCHAR(100),
    
    -- Requester info
    requester_id UUID REFERENCES users(id),
    requester_name VARCHAR(255),
    department VARCHAR(100),
    
    -- Service details
    start_date DATE,
    end_date DATE,
    location VARCHAR(255),
    estimated_cost DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'NGN',
    
    -- Status
    status VARCHAR(50) DEFAULT 'pending' CHECK (status IN (
        'pending', 'manager_review', 'finance_review', 
        'approved', 'in_progress', 'completed', 'rejected'
    )),
    
    -- Approvals
    manager_approved BOOLEAN DEFAULT FALSE,
    manager_approved_by UUID REFERENCES users(id),
    manager_approved_at TIMESTAMP,
    manager_remarks TEXT,
    
    finance_approved BOOLEAN DEFAULT FALSE,
    finance_approved_by UUID REFERENCES users(id),
    finance_approved_at TIMESTAMP,
    finance_remarks TEXT,
    
    -- Rejection
    rejection_reason TEXT,
    rejected_by UUID REFERENCES users(id),
    rejected_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 1.7 Notifications Table

```sql
CREATE TABLE notifications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id),
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) CHECK (type IN ('info', 'success', 'warning', 'error')),
    
    -- Related entity
    entity_type VARCHAR(50), -- 'mrf', 'rfq', 'srf', 'quotation', 'vendor'
    entity_id UUID,
    action_url TEXT,
    
    -- Status
    read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_notifications_user ON notifications(user_id, read);
```

---

## 2. API ENDPOINTS SPECIFICATION

### 2.1 MRF Endpoints

```
# List MRFs (with role-based filtering)
GET /api/mrfs
Query params:
  - status: string (filter by status)
  - stage: string (filter by current_stage)
  - requester_id: uuid (filter by requester)
  - page: number
  - limit: number
Response: { success: true, data: MRF[], total: number }

# Get single MRF with items and history
GET /api/mrfs/:id
Response: { 
  success: true, 
  data: { 
    ...mrf, 
    items: MRFItem[], 
    approvalHistory: ApprovalHistory[] 
  } 
}

# Create MRF (from MRN conversion)
POST /api/mrfs
Body: {
  mrn_id?: string,
  title: string,
  description: string,
  justification: string,
  category: string,
  urgency: 'low' | 'medium' | 'high' | 'critical',
  estimated_cost: number,
  items: Array<{
    item_name: string,
    description: string,
    quantity: number,
    unit: string,
    unit_price: number
  }>
}
Response: { success: true, data: MRF }
Side effects:
  - Create notification for Executive role users
  - Send email to Executive users

# Approve MRF (Executive/Chairman)
POST /api/mrfs/:id/approve
Body: { remarks?: string }
Headers: Authorization: Bearer <token>
Logic:
  1. Check current user role matches required stage
  2. If Executive approving:
     - Set executive_approved = true
     - If estimated_cost > 1,000,000 → status = 'chairman_review'
     - Else → status = 'procurement'
  3. If Chairman approving:
     - Set chairman_approved = true
     - status = 'procurement'
  4. Add to approval_history
  5. Create notification for next approver/procurement
  6. Send email notification
Response: { success: true, data: MRF }

# Reject MRF
POST /api/mrfs/:id/reject
Body: { reason: string, comments?: string }
Logic:
  1. Set status = 'rejected'
  2. Record rejection details
  3. Add to approval_history
  4. Notify requester
  5. Send rejection email
Response: { success: true, data: MRF }

# Generate PO (Procurement)
POST /api/mrfs/:id/generate-po
Body: { po_number: string }
Logic:
  1. Validate MRF is in 'procurement' stage
  2. Generate unsigned PO document (PDF)
  3. Store PO URL
  4. Set status = 'supply_chain'
  5. Notify Supply Chain Director
Response: { success: true, data: { mrf: MRF, po_url: string } }

# Upload Signed PO (Supply Chain Director)
POST /api/mrfs/:id/upload-signed-po
Body: FormData with signed_po file
Logic:
  1. Upload file to storage
  2. Update signed_po_url
  3. Set status = 'finance'
  4. Notify Finance team
Response: { success: true, data: MRF }

# Reject PO (Supply Chain Director)
POST /api/mrfs/:id/reject-po
Body: { reason: string, comments?: string }
Logic:
  1. Increment po_version
  2. Clear unsigned_po_url and signed_po_url
  3. Set status = 'procurement'
  4. Record rejection in history
  5. Notify Procurement
Response: { success: true, data: MRF }

# Mark as Processed for Payment (Finance)
POST /api/mrfs/:id/process-payment
Logic:
  1. Set status = 'chairman_payment'
  2. Notify Chairman
Response: { success: true, data: MRF }

# Approve Payment (Chairman)
POST /api/mrfs/:id/approve-payment
Logic:
  1. Set status = 'completed'
  2. Set payment_status = 'paid'
  3. Notify all stakeholders
Response: { success: true, data: MRF }
```

### 2.2 RFQ Endpoints

```
# List RFQs
GET /api/rfqs
Query params:
  - status: string
  - category: string
  - vendor_id: uuid (for vendor portal - only their RFQs)
Response: { success: true, data: RFQ[] }

# Get RFQ with items and quotations
GET /api/rfqs/:id
Response: { 
  success: true, 
  data: { 
    ...rfq, 
    items: RFQItem[], 
    quotations: Quotation[],
    distributedVendors: Vendor[]
  } 
}

# Create RFQ (Procurement)
POST /api/rfqs
Body: {
  mrf_id?: string,
  title: string,
  description: string,
  category: string,
  deadline: string (ISO date),
  items: Array<{
    item_name: string,
    description: string,
    quantity: number,
    unit: string,
    specifications?: string
  }>,
  vendor_ids: string[] // Vendors to send RFQ to
}
Logic:
  1. Create RFQ record
  2. Create RFQ items
  3. Create rfq_vendor_distribution records
  4. FOR EACH vendor in vendor_ids:
     - Create notification for vendor
     - Send email to vendor with RFQ details
  5. Set status = 'open'
Response: { success: true, data: RFQ }

# Get RFQs for Vendor (Vendor Portal)
GET /api/vendors/rfqs
Headers: Authorization: Bearer <vendor_token>
Logic:
  1. Get vendor_id from token
  2. Return all RFQs where vendor_id in rfq_vendor_distribution
Response: { success: true, data: RFQ[] }

# Submit Quotation (Vendor)
POST /api/quotations
Headers: Authorization: Bearer <vendor_token>
Body: {
  rfq_id: string,
  items: Array<{
    rfq_item_id: string,
    item_name: string,
    quantity: number,
    unit_price: number
  }>,
  delivery_days: number,
  payment_terms: string,
  validity_days: number,
  warranty_period?: string,
  notes?: string,
  attachments?: File[]
}
Logic:
  1. Validate RFQ is still open
  2. Validate vendor is in distribution list
  3. Calculate total_amount from items
  4. Create quotation and items
  5. Update rfq_vendor_distribution.responded = true
  6. Notify Procurement Manager
  7. Send confirmation email to vendor
Response: { success: true, data: Quotation }

# Get Quotations for RFQ (Procurement comparison)
GET /api/rfqs/:id/quotations
Response: { 
  success: true, 
  data: Array<{
    quotation: Quotation,
    vendor: Vendor,
    items: QuotationItem[]
  }>
}

# Select Winning Quotation (Procurement)
POST /api/rfqs/:id/select-vendor
Body: { quotation_id: string }
Logic:
  1. Update RFQ status = 'awarded'
  2. Set selected_vendor_id and selected_quotation_id
  3. Update selected quotation status = 'approved'
  4. Update other quotations status = 'rejected'
  5. Notify winning vendor
  6. Send award email to winning vendor
  7. Send rejection emails to other vendors
Response: { success: true, data: RFQ }

# Close RFQ without selection
POST /api/rfqs/:id/close
Logic:
  1. Set status = 'closed'
  2. Notify all participating vendors
Response: { success: true, data: RFQ }
```

### 2.3 SRF Endpoints

```
# List SRFs
GET /api/srfs
Query params:
  - status: string
  - requester_id: uuid
  - department: string
Response: { success: true, data: SRF[] }

# Get single SRF
GET /api/srfs/:id
Response: { success: true, data: SRF }

# Create SRF
POST /api/srfs
Body: {
  title: string,
  description: string,
  service_type: string,
  start_date: string,
  end_date: string,
  location: string,
  estimated_cost: number
}
Logic:
  1. Create SRF record
  2. Generate srf_number
  3. Notify Department Manager
Response: { success: true, data: SRF }

# Approve SRF (Manager)
POST /api/srfs/:id/manager-approve
Body: { remarks?: string }
Logic:
  1. Set manager_approved = true
  2. Set status = 'finance_review'
  3. Notify Finance
Response: { success: true, data: SRF }

# Approve SRF (Finance)
POST /api/srfs/:id/finance-approve
Body: { remarks?: string }
Logic:
  1. Set finance_approved = true
  2. Set status = 'approved'
  3. Notify requester
Response: { success: true, data: SRF }

# Reject SRF
POST /api/srfs/:id/reject
Body: { reason: string }
Logic:
  1. Set status = 'rejected'
  2. Record rejection
  3. Notify requester
Response: { success: true, data: SRF }

# Update SRF Status
PUT /api/srfs/:id/status
Body: { status: 'in_progress' | 'completed' }
Response: { success: true, data: SRF }
```

### 2.4 Notification Endpoints

```
# Get user notifications
GET /api/notifications
Query params:
  - unread_only: boolean
  - limit: number
Response: { success: true, data: Notification[], unread_count: number }

# Mark notification as read
PUT /api/notifications/:id/read
Response: { success: true }

# Mark all as read
PUT /api/notifications/read-all
Response: { success: true }

# Get notification preferences
GET /api/notifications/preferences
Response: { 
  success: true, 
  data: {
    email_enabled: boolean,
    in_app_enabled: boolean,
    sound_enabled: boolean,
    categories: { [key: string]: boolean }
  }
}

# Update notification preferences
PUT /api/notifications/preferences
Body: { ...preferences }
Response: { success: true }
```

### 2.5 Vendor Endpoints (Additional)

```
# Invite vendor (send registration email)
POST /api/vendors/invite
Body: { email: string, company_name?: string }
Logic:
  1. Generate invitation token
  2. Store invitation record
  3. Send email with registration link:
     https://yourapp.com/vendor-registration?token={token}&email={email}
Response: { success: true, message: 'Invitation sent' }

# Get vendor stats (computed, not fake)
GET /api/vendors/:id/stats
Logic:
  1. Calculate from actual data:
     - total_quotations: COUNT from quotations table
     - accepted_quotations: COUNT where status = 'approved'
     - success_rate: (accepted / total) * 100
     - avg_response_time: AVG time between RFQ sent and quotation submitted
     - total_orders: COUNT of POs with this vendor
     - rating: AVG from vendor_ratings table or manual rating
Response: {
  success: true,
  data: {
    total_quotations: number,
    accepted_quotations: number,
    success_rate: number,
    avg_response_time_hours: number,
    total_orders: number,
    rating: number
  }
}

# Update vendor profile (from vendor portal)
PUT /api/vendors/auth/profile
Headers: Authorization: Bearer <vendor_token>
Body: {
  contact_person?: string,
  phone?: string,
  address?: string
}
Response: { success: true, data: Vendor }
```

---

## 3. EMAIL INTEGRATION

### 3.1 Email Service Setup

Use a transactional email service (Resend, SendGrid, AWS SES, or Mailgun).

```php
// Laravel example - config/mail.php
'mailers' => [
    'resend' => [
        'transport' => 'resend',
    ],
],

// .env
MAIL_MAILER=resend
RESEND_API_KEY=re_xxxxxxxxx
MAIL_FROM_ADDRESS=noreply@yourcompany.com
MAIL_FROM_NAME="SCM System"
```

### 3.2 Email Templates Required

```
1. vendor_invitation
   Subject: "You're invited to register as a vendor"
   Variables: {company_name}, {registration_link}

2. vendor_approved
   Subject: "Your vendor registration has been approved"
   Variables: {company_name}, {username}, {temporary_password}, {login_link}

3. vendor_rejected
   Subject: "Vendor registration update"
   Variables: {company_name}, {rejection_reason}

4. rfq_received
   Subject: "New RFQ: {rfq_title}"
   Variables: {vendor_name}, {rfq_number}, {rfq_title}, {deadline}, {portal_link}

5. quotation_submitted_confirmation
   Subject: "Quotation submitted successfully"
   Variables: {vendor_name}, {rfq_number}, {quote_amount}

6. quotation_awarded
   Subject: "Congratulations! Your quotation has been selected"
   Variables: {vendor_name}, {rfq_number}, {po_details}

7. quotation_not_selected
   Subject: "RFQ {rfq_number} - Vendor Selection Complete"
   Variables: {vendor_name}, {rfq_number}

8. mrf_pending_approval
   Subject: "MRF Pending Your Approval: {mrf_title}"
   Variables: {approver_name}, {mrf_title}, {requester}, {amount}, {approval_link}

9. mrf_approved
   Subject: "Your MRF has been approved"
   Variables: {requester_name}, {mrf_title}, {approved_by}

10. mrf_rejected
    Subject: "MRF Rejected: {mrf_title}"
    Variables: {requester_name}, {mrf_title}, {rejection_reason}

11. po_ready_for_signature
    Subject: "PO Ready for Signature: {po_number}"
    Variables: {scd_name}, {po_number}, {mrf_title}, {review_link}

12. po_signed
    Subject: "PO Signed and Sent to Finance: {po_number}"
    Variables: {procurement_name}, {po_number}

13. payment_pending_approval
    Subject: "Payment Pending Chairman Approval: {po_number}"
    Variables: {chairman_name}, {po_number}, {amount}, {approval_link}

14. payment_completed
    Subject: "Payment Completed: {po_number}"
    Variables: {requester_name}, {po_number}, {amount}

15. document_expiry_reminder
    Subject: "Document Expiring Soon: {document_name}"
    Variables: {vendor_name}, {document_name}, {expiry_date}, {days_remaining}
    Send at: 30, 14, 7 days before expiry

16. srf_pending_approval
    Subject: "SRF Pending Your Approval: {srf_title}"
    Variables: {approver_name}, {srf_title}, {requester}

17. password_reset
    Subject: "Password Reset for SCM Portal"
    Variables: {name}, {reset_link}
```

### 3.3 Email Trigger Points

```php
// Laravel Controller example

class MRFController extends Controller
{
    public function approve(Request $request, $id)
    {
        $mrf = MRF::findOrFail($id);
        
        // ... approval logic ...
        
        // Send notification
        Notification::create([
            'user_id' => $nextApproverId,
            'title' => 'MRF Pending Approval',
            'message' => "MRF '{$mrf->title}' requires your approval",
            'type' => 'info',
            'entity_type' => 'mrf',
            'entity_id' => $mrf->id,
            'action_url' => "/procurement?mrf={$mrf->id}"
        ]);
        
        // Send email
        Mail::to($nextApprover->email)->send(new MRFPendingApprovalMail($mrf, $nextApprover));
        
        return response()->json(['success' => true, 'data' => $mrf]);
    }
}

class RFQController extends Controller
{
    public function create(Request $request)
    {
        $rfq = RFQ::create($request->validated());
        
        // Distribute to vendors
        foreach ($request->vendor_ids as $vendorId) {
            RFQVendorDistribution::create([
                'rfq_id' => $rfq->id,
                'vendor_id' => $vendorId
            ]);
            
            $vendor = Vendor::find($vendorId);
            
            // Create notification
            Notification::create([
                'user_id' => $vendor->user_id,
                'title' => 'New RFQ Received',
                'message' => "You have received RFQ: {$rfq->title}",
                'type' => 'info',
                'entity_type' => 'rfq',
                'entity_id' => $rfq->id,
                'action_url' => '/vendor-portal?tab=rfqs'
            ]);
            
            // Send email to vendor
            Mail::to($vendor->email)->send(new RFQReceivedMail($rfq, $vendor));
        }
        
        return response()->json(['success' => true, 'data' => $rfq]);
    }
}
```

---

## 4. REAL-TIME NOTIFICATIONS (WebSocket)

### 4.1 WebSocket Server Setup (Laravel Reverb/Pusher)

```php
// config/broadcasting.php
'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST'),
            'port' => env('REVERB_PORT', 443),
            'scheme' => env('REVERB_SCHEME', 'https'),
        ],
    ],
],
```

### 4.2 Broadcast Events

```php
// app/Events/MRFStatusChanged.php
class MRFStatusChanged implements ShouldBroadcast
{
    public function __construct(
        public MRF $mrf,
        public string $action,
        public ?User $performer = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->mrf->requester_id),
            new PrivateChannel('role.procurement'),
            new PrivateChannel('role.executive'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mrf.' . $this->action; // mrf.approved, mrf.rejected, etc.
    }
}

// app/Events/RFQCreated.php
class RFQCreated implements ShouldBroadcast
{
    public function __construct(public RFQ $rfq, public array $vendorIds) {}

    public function broadcastOn(): array
    {
        return array_map(
            fn($id) => new PrivateChannel('vendor.' . $id),
            $this->vendorIds
        );
    }

    public function broadcastAs(): string
    {
        return 'rfq.created';
    }
}

// app/Events/QuotationSubmitted.php
class QuotationSubmitted implements ShouldBroadcast
{
    public function __construct(public Quotation $quotation) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('role.procurement')];
    }

    public function broadcastAs(): string
    {
        return 'quotation.submitted';
    }
}
```

### 4.3 Frontend WebSocket Connection

The frontend expects these WebSocket events:

```typescript
// Events the frontend listens for:
'mrf_created' → payload: MRF object
'mrf_updated' → payload: MRF object
'mrf_approved' → payload: MRF object
'mrf_rejected' → payload: MRF object

'rfq_created' → payload: RFQ object
'rfq_updated' → payload: RFQ object

'quotation_submitted' → payload: Quotation object
'quotation_approved' → payload: Quotation object

'srf_created' → payload: SRF object
'srf_approved' → payload: SRF object
'srf_rejected' → payload: SRF object

'notification' → payload: {
  id: string,
  title: string,
  message: string,
  type: 'info' | 'success' | 'warning' | 'error',
  timestamp: string
}

'vendor_registration_updated' → payload: VendorRegistration object
```

---

## 5. WORKFLOW STATE MACHINE

### 5.1 MRF State Transitions

```
pending → executive_review (on submission)
executive_review → chairman_review (if amount > 1M, on executive approval)
executive_review → procurement (if amount <= 1M, on executive approval)
executive_review → rejected (on executive rejection)
chairman_review → procurement (on chairman approval)
chairman_review → rejected (on chairman rejection)
procurement → supply_chain (on PO generation)
supply_chain → procurement (on PO rejection - for revision)
supply_chain → finance (on signed PO upload)
finance → chairman_payment (on payment processing)
chairman_payment → completed (on chairman payment approval)
```

### 5.2 RFQ State Transitions

```
draft → open (on publish)
open → closed (on deadline or manual close)
open → awarded (on vendor selection)
closed → awarded (on vendor selection after deadline)
awarded → (terminal state)
cancelled → (terminal state)
```

### 5.3 SRF State Transitions

```
pending → manager_review (on submission)
manager_review → finance_review (on manager approval)
manager_review → rejected (on manager rejection)
finance_review → approved (on finance approval)
finance_review → rejected (on finance rejection)
approved → in_progress (on work start)
in_progress → completed (on completion)
```

---

## 6. ROLE-BASED ACCESS CONTROL

### 6.1 Roles and Permissions

```
employee:
  - Create MRF, SRF
  - View own requests
  - View notifications

procurement:
  - All employee permissions
  - View all MRFs in procurement stage
  - Generate POs
  - Create and manage RFQs
  - Compare quotations
  - Select vendors
  - Manage vendor registrations

executive:
  - All employee permissions
  - Approve/reject MRFs (first level)
  - View procurement dashboard

chairman:
  - All executive permissions
  - Approve high-value MRFs (>1M)
  - Final payment approval

supply_chain:
  - View all MRFs in supply_chain stage
  - Sign/reject POs
  - View procurement progress

finance:
  - View MRFs in finance stage
  - Process payments
  - Match invoices
  - Approve SRFs (finance level)

admin:
  - All permissions
  - User management
  - System configuration
```

### 6.2 Middleware Implementation

```php
// app/Http/Middleware/CheckRole.php
class CheckRole
{
    public function handle($request, Closure $next, ...$roles)
    {
        if (!$request->user() || !in_array($request->user()->role, $roles)) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Required role: ' . implode(' or ', $roles)
            ], 403);
        }
        return $next($request);
    }
}

// routes/api.php
Route::middleware(['auth:sanctum', 'role:procurement'])->group(function () {
    Route::post('/rfqs', [RFQController::class, 'create']);
    Route::post('/mrfs/{id}/generate-po', [MRFController::class, 'generatePO']);
});

Route::middleware(['auth:sanctum', 'role:executive,chairman'])->group(function () {
    Route::post('/mrfs/{id}/approve', [MRFController::class, 'approve']);
});
```

---

## 7. SCHEDULED TASKS

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Send document expiry reminders daily
    $schedule->command('vendors:send-expiry-reminders')->dailyAt('09:00');
    
    // Close expired RFQs
    $schedule->command('rfqs:close-expired')->hourly();
    
    // Send 48-hour MRF approval reminder
    $schedule->command('mrfs:send-approval-reminders')->everyFourHours();
}

// app/Console/Commands/SendExpiryReminders.php
class SendExpiryReminders extends Command
{
    protected $signature = 'vendors:send-expiry-reminders';
    
    public function handle()
    {
        $reminders = [30, 14, 7]; // Days before expiry
        
        foreach ($reminders as $days) {
            $expiryDate = now()->addDays($days)->toDateString();
            
            $vendors = Vendor::whereHas('documents', function ($q) use ($expiryDate) {
                $q->whereDate('expiry_date', $expiryDate);
            })->get();
            
            foreach ($vendors as $vendor) {
                $expiringDocs = $vendor->documents()
                    ->whereDate('expiry_date', $expiryDate)
                    ->get();
                
                foreach ($expiringDocs as $doc) {
                    Mail::to($vendor->email)->send(
                        new DocumentExpiryReminderMail($vendor, $doc, $days)
                    );
                }
            }
        }
    }
}
```

---

## 8. TESTING CHECKLIST

### 8.1 MRF Workflow Tests

```
[ ] Create MRF → status = pending
[ ] Executive approves (< 1M) → status = procurement
[ ] Executive approves (> 1M) → status = chairman_review
[ ] Chairman approves → status = procurement
[ ] Procurement generates PO → status = supply_chain
[ ] SCD signs PO → status = finance
[ ] SCD rejects PO → status = procurement, version++
[ ] Finance processes → status = chairman_payment
[ ] Chairman approves payment → status = completed
[ ] Notifications sent at each step
[ ] Emails sent at each step
```

### 8.2 RFQ Workflow Tests

```
[ ] Create RFQ with vendor selection
[ ] Vendors receive notifications
[ ] Vendors receive emails
[ ] Vendor submits quotation
[ ] Quotation appears in comparison view
[ ] Select winning vendor
[ ] Winner gets approval notification/email
[ ] Others get rejection notification/email
[ ] RFQ status = awarded
```

### 8.3 Vendor Tests

```
[ ] Invite vendor → email sent
[ ] Vendor registers → admin notified
[ ] Admin approves → credentials generated, email sent
[ ] Admin rejects → rejection email sent
[ ] Vendor logs in → sees RFQs
[ ] Vendor submits quote → confirmation email
[ ] Document expiry reminders sent at 30/14/7 days
```

---

## 9. RESPONSE FORMAT

All API responses must follow this format:

```json
// Success
{
  "success": true,
  "data": { ... },
  "message": "Optional success message"
}

// Error
{
  "success": false,
  "error": "Error message",
  "errors": {
    "field_name": ["Validation error message"]
  }
}

// Paginated
{
  "success": true,
  "data": [...],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 100,
    "last_page": 5
  }
}
```

---

## 10. CORS CONFIGURATION

```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://your-frontend-domain.lovable.app',
        'http://localhost:8080'
    ],
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-Client-Info',
        'apikey'
    ],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

---

This specification provides everything needed to implement the complete SCM workflow system with real database persistence, email notifications, and real-time updates.
