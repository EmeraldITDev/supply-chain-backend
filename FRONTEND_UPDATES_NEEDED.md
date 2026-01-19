# Frontend Updates Needed for RFQ & Quotation Features

This document outlines the frontend changes needed to support the recent backend improvements for RFQ naming, quotation visibility, and workflow tracking.

## 1. RFQ Naming Fix

### Issue
RFQs were showing as "Unknown RFQ" in vendor quotations.

### Backend Changes
- RFQ titles are now auto-generated in format: `"{Product/Service} – {Contract Type} RFQ"`
- Example: "Laptop Supply – Heritage RFQ"

### Frontend Updates Needed

#### Vendor Quotations View (`/vendors/quotations`)
**Current API Response:**
```json
{
  "rfqTitle": "Laptop Supply – Heritage RFQ"  // Now properly formatted
}
```

**Action Required:**
- Update the vendor quotations list to display `rfqTitle` instead of showing "Unknown RFQ"
- The field is already in the response, just needs to be used in the UI

**API Endpoint:** `GET /api/vendors/quotations`

---

## 2. Full Quotation Details View (Procurement Managers)

### Issue
Procurement managers could only select quotations but not view full details.

### Backend Changes
Enhanced `GET /api/rfqs/{rfqId}/quotations` endpoint now returns:
- Complete RFQ details
- Full MRF information with executive approval status
- All quotation details (pricing, terms, attachments, items)
- Vendor information
- Statistics (lowest/highest/average bids)

### Frontend Updates Needed

#### Quotation Comparison View
**API Endpoint:** `GET /api/rfqs/{rfqId}/quotations`

**New Response Structure:**
```json
{
  "success": true,
  "data": {
    "rfq": {
      "id": "RFQ-2026-001",
      "title": "Laptop Supply – Heritage RFQ",
      "description": "...",
      "category": "IT Equipment",
      "deadline": "2026-02-15",
      "estimatedCost": 500000,
      "paymentTerms": "Net 30",
      "supportingDocuments": [...],
      "items": [...]
    },
    "mrf": {
      "id": "MRF-2026-001",
      "title": "Laptop Supply Request",
      "contractType": "Heritage",
      "executiveApproved": true,
      "executiveApprovedAt": "2026-01-15T10:00:00Z",
      "executiveApprovedBy": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
      }
    },
    "quotations": [
      {
        "quotation": {
          "id": "QUO-2026-001",
          "total_amount": 450000,
          "currency": "NGN",
          "delivery_days": 14,
          "payment_terms": "Net 30",
          "attachments": [...],
          "notes": "..."
        },
        "vendor": {
          "id": "V001",
          "name": "Vendor A",
          "email": "vendor@example.com",
          "rating": 4.5
        },
        "items": [...]
      }
    ],
    "statistics": {
      "total_quotations": 3,
      "lowest_bid": 450000,
      "highest_bid": 520000,
      "average_bid": 485000
    }
  }
}
```

**Action Required:**
1. Update the quotation comparison view to display:
   - Full quotation details (not just selection dropdown)
   - MRF information and executive approval status
   - All quotation items with pricing breakdown
   - Vendor ratings and contact information
   - Statistics for comparison
   - Attachments/documents for each quotation

2. Add a "View Details" button/modal for each quotation showing:
   - Complete pricing breakdown
   - Delivery terms
   - Payment terms
   - Warranty information
   - Attached documents
   - Vendor contact details

---

## 3. End-to-End MRF Visibility

### Issue
Procurement managers needed to see full MRF details with all quotations in one view.

### Backend Changes
New endpoint: `GET /api/mrfs/{mrfId}/full-details`

### Frontend Updates Needed

**API Endpoint:** `GET /api/mrfs/{mrfId}/full-details`

**Response Structure:**
```json
{
  "success": true,
  "data": {
    "mrf": {
      "id": "MRF-2026-001",
      "title": "Laptop Supply Request",
      "category": "IT Equipment",
      "contractType": "Heritage",
      "executiveApproved": true,
      "executiveApprovedAt": "2026-01-15T10:00:00Z",
      "executiveApprovedBy": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
      },
      "workflowState": "procurement_review",
      "status": "In Progress"
    },
    "rfqs": [
      {
        "id": "RFQ-2026-001",
        "title": "Laptop Supply – Heritage RFQ",
        "status": "Open",
        "vendors": [...]
      }
    ],
    "quotations": [
      {
        "id": "QUO-2026-001",
        "rfqId": "RFQ-2026-001",
        "rfqTitle": "Laptop Supply – Heritage RFQ",
        "vendor": {...},
        "totalAmount": 450000,
        "status": "Pending",
        "attachments": [...]
      }
    ],
    "statistics": {
      "totalQuotations": 3,
      "totalRfqs": 1,
      "lowestBid": 450000,
      "highestBid": 520000,
      "averageBid": 485000
    }
  }
}
```

**Action Required:**
1. Create a new view/page: "MRF Full Details" or enhance existing MRF detail view
2. Display:
   - Complete MRF information
   - Executive approval status (highlighted in green if approved)
   - All RFQs associated with the MRF
   - All quotations from all RFQs
   - Statistics and comparison data
3. Add navigation to this view from:
   - MRF list page
   - Quotation review page
   - Dashboard

---

## 4. Executive Approval Visibility

### Issue
Executive approval status was not clearly visible.

### Backend Changes
- Added executive approval fields to `GET /api/mrfs/{id}` response
- Executive approval is now included in all relevant endpoints

### Frontend Updates Needed

**API Endpoint:** `GET /api/mrfs/{id}` (Updated)

**New Fields in Response:**
```json
{
  "id": "MRF-2026-001",
  "executiveApproved": true,
  "executiveApprovedAt": "2026-01-15T10:00:00Z",
  "executiveApprovedBy": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "executiveRemarks": "Approved for procurement",
  "chairmanApproved": false,
  "chairmanApprovedAt": null
}
```

**Action Required:**
1. **MRF List View:**
   - Add a visual indicator (green checkmark/badge) when `executiveApproved === true`
   - Show approval date and approver name

2. **MRF Detail View:**
   - Add an "Executive Approval" section
   - Highlight in green when approved
   - Display:
     - Approval status (with green background/border if approved)
     - Approval date and time
     - Approver name and email
     - Approval remarks

3. **Quotation Review Page:**
   - Show executive approval status at the top
   - Disable vendor selection if executive approval is pending (optional)

---

## 5. Progress Tracker

### Issue
Workflow progress was not clearly tracked.

### Backend Changes
New endpoint: `GET /api/mrfs/{mrfId}/progress-tracker`

### Frontend Updates Needed

**API Endpoint:** `GET /api/mrfs/{mrfId}/progress-tracker`

**Response Structure:**
```json
{
  "success": true,
  "data": {
    "mrfId": "MRF-2026-001",
    "title": "Laptop Supply Request",
    "currentStep": 4,
    "steps": [
      {
        "step": 1,
        "name": "MRF Created",
        "status": "completed",
        "completedAt": "2026-01-10T09:00:00Z",
        "completedBy": {
          "id": 5,
          "name": "Jane Smith"
        }
      },
      {
        "step": 2,
        "name": "Executive Approval",
        "status": "completed",
        "completedAt": "2026-01-15T10:00:00Z",
        "completedBy": {
          "id": 1,
          "name": "John Doe"
        },
        "remarks": "Approved for procurement"
      },
      {
        "step": 3,
        "name": "RFQ Issued",
        "status": "completed",
        "completedAt": "2026-01-16T11:00:00Z"
      },
      {
        "step": 4,
        "name": "Supply Chain Director Approval",
        "status": "pending"
      },
      {
        "step": 5,
        "name": "Procurement Generates PO",
        "status": "not_started"
      },
      {
        "step": 6,
        "name": "Finance Review & Processing",
        "status": "not_started"
      },
      {
        "step": 7,
        "name": "Goods Received Note (GRN)",
        "status": "not_started"
      },
      {
        "step": 8,
        "name": "Mark as Paid / Closed",
        "status": "not_started"
      }
    ]
  }
}
```

**Action Required:**
1. Create a progress tracker component/widget
2. Display as a horizontal timeline or vertical stepper
3. Visual states:
   - **Completed**: Green checkmark, completed styling
   - **Pending**: Yellow/orange indicator, "In Progress" label
   - **Not Started**: Gray, disabled styling
4. Show completion details (date, approver) on hover/click
5. Integrate into:
   - MRF detail page
   - Dashboard
   - MRF list (as a compact view)

**Step Names:**
1. MRF Created
2. Executive Approval
3. RFQ Issued
4. Supply Chain Director Approval
5. Procurement Generates PO
6. Finance Review & Processing
7. Goods Received Note (GRN)
8. Mark as Paid / Closed

---

## 6. Multiple Vendor Quotations Preservation

### Issue
When RFQ sent to new vendors, existing quotations were being deleted.

### Backend Changes
- Fixed vendor attachment to preserve existing quotations
- Using `syncWithoutDetaching()` instead of `attach()`

### Frontend Updates Needed

**No frontend changes required** - this is a backend fix. However, you may want to:

1. **Add Vendor to Existing RFQ:**
   - Add UI to add more vendors to an existing RFQ
   - Show existing quotations count before adding vendors
   - Confirm that existing quotations will be preserved

2. **Display All Quotations:**
   - Ensure the UI shows all quotations from all vendors
   - Don't filter out quotations when new vendors are added

---

## 7. Quotation Selection & Approval Workflow

### Issue
Errors when selecting quotations: "MRF is not in procurement review stage"

### Backend Changes
- Fixed workflow state validation to allow vendor selection after executive approval
- More flexible state checking

### Frontend Updates Needed

**Action Required:**
1. **Error Handling:**
   - Update error messages to handle the new validation
   - Show helpful messages if workflow state is invalid

2. **Vendor Selection UI:**
   - Enable "Select and Send for Approval" button when:
     - Executive approval is granted, OR
     - MRF is in procurement_review state
   - Show tooltip/help text explaining when selection is allowed

3. **Workflow State Display:**
   - Show current workflow state clearly
   - Indicate when vendor selection is available

---

## Summary of New/Updated Endpoints

### New Endpoints:
1. `GET /api/mrfs/{id}/full-details` - Full MRF with all quotations
2. `GET /api/mrfs/{id}/progress-tracker` - Workflow progress tracker

### Updated Endpoints:
1. `GET /api/mrfs/{id}` - Now includes executive approval fields
2. `GET /api/rfqs/{rfqId}/quotations` - Enhanced with MRF details and full quotation info
3. `GET /api/vendors/quotations` - RFQ titles now properly formatted

### No Changes Needed:
- `POST /api/mrfs/{id}/send-vendor-for-approval` - Works with updated validation
- `GET /api/vendors/rfqs` - Already returns proper RFQ titles

---

## Implementation Priority

1. **High Priority:**
   - Fix RFQ naming display (use `rfqTitle` field)
   - Add executive approval visibility (green highlighting)
   - Enhance quotation details view (show full details, not just selection)

2. **Medium Priority:**
   - Add progress tracker component
   - Create full MRF details view

3. **Low Priority:**
   - Add vendor to existing RFQ functionality
   - Enhanced error messages for workflow states

---

## Testing Checklist

- [ ] Vendor quotations show proper RFQ names (not "Unknown RFQ")
- [ ] Executive approval is clearly visible and highlighted in green
- [ ] Procurement managers can view full quotation details
- [ ] Progress tracker displays correctly for all workflow states
- [ ] Full MRF details view shows all quotations
- [ ] Vendor selection works after executive approval
- [ ] Multiple quotations are preserved when adding vendors

---

## Questions or Issues?

If you encounter any issues implementing these changes or need clarification on the API responses, please refer to the API documentation or test the endpoints directly using Postman/curl.
