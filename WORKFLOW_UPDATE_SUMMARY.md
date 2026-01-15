# MRF Workflow Update Summary

## Overview

This document summarizes the backend implementation of the new MRF workflow with strict role-based access control.

## Completed Changes

### 1. Frontend Implementation Guide
- Created comprehensive guide at `FRONTEND_IMPLEMENTATION_GUIDE.md`
- Includes role-based UI patterns, API integration examples, and security best practices

### 2. Workflow State Service Updates
- Updated `WorkflowStateService` with new workflow states:
  - `mrf_created` → `executive_review` → `executive_approved` → `procurement_review` → `vendor_selected` → `invoice_received` → `invoice_approved` → `po_generated` → `po_signed` → `payment_processed` → `grn_requested` → `grn_completed` → `closed`
- Updated state transitions and role permissions

### 3. Permission Service Updates
- Added strict role-based permission checks:
  - `canEditMRF()` - Only staff can edit their own MRF before submission
  - `canSelectVendors()` - Only procurement can select vendors
  - `canApproveInvoice()` - Only supply chain director can approve invoices
  - `canViewInvoices()` - Procurement and Finance can view invoices
  - `canUploadGRN()` - Only procurement can upload GRN
  - `canViewGRN()` - All roles can view GRN after upload
- Added `getAvailableActions()` method for frontend integration

### 4. Database Migration
- Created migration `2026_01_15_161921_add_invoice_fields_to_m_r_f_s_table.php`
- Added fields:
  - `selected_vendor_id` - Foreign key to vendors table
  - `invoice_url` - URL to vendor invoice
  - `invoice_share_url` - Shareable link to invoice
  - `invoice_approved_by` - User who approved invoice
  - `invoice_approved_at` - Timestamp of approval
  - `invoice_remarks` - Approval remarks
  - `expected_delivery_date` - Expected delivery date from PO

### 5. MRF Model Updates
- Added invoice-related fields to fillable array
- Added relationships:
  - `selectedVendor()` - BelongsTo Vendor
  - `invoiceApprover()` - BelongsTo User
- Added casts for new datetime/date fields

### 6. MRF Controller Updates
- Added `getAvailableActions()` endpoint: `GET /api/mrfs/{id}/available-actions`
- Updated `store()` to set workflow_state to `executive_review` immediately
- Updated `update()` to prevent editing after submission using PermissionService

### 7. API Routes
- Added route: `GET /api/mrfs/{id}/available-actions`

## Remaining Tasks

### 1. Vendor Selection Endpoint
**Endpoint:** `POST /api/mrfs/{id}/select-vendors`
- Procurement selects one or multiple vendors
- Creates RFQ and sends to vendors
- Updates MRF workflow_state to `vendor_selected`

### 2. Invoice Approval Endpoint (Supply Chain Director)
**Endpoint:** `POST /api/mrfs/{id}/approve-invoice`
- Supply Chain Director approves selected vendor invoice
- Updates MRF with invoice approval details
- Updates workflow_state to `invoice_approved`

### 3. Update MRFWorkflowController
- Update `executiveApprove()` to set workflow_state to `executive_approved` and move to `procurement_review`
- Update `generatePO()` to require `invoice_approved` state
- Update workflow transitions to match new states

### 4. Vendor Invoice Submission
- Vendors submit invoices via vendor portal (existing RFQ/Quotation system)
- Procurement reviews invoices and selects preferred vendor
- Selected vendor invoice is linked to MRF

## Workflow Flow

1. **Staff creates MRF** → `workflow_state = executive_review`
2. **Executive approves** → `workflow_state = executive_approved` → `procurement_review`
3. **Procurement selects vendors** → Creates RFQ → Sends to vendors → `workflow_state = vendor_selected`
4. **Vendors submit invoices** → Procurement reviews → Selects preferred vendor
5. **Supply Chain Director approves invoice** → `workflow_state = invoice_approved`
6. **Procurement generates PO** → `workflow_state = po_generated` → `po_signed`
7. **Finance processes payment** → `workflow_state = payment_processed`
8. **Finance requests GRN** → `workflow_state = grn_requested`
9. **Procurement uploads GRN** → `workflow_state = grn_completed`
10. **Finance reviews GRN** → `workflow_state = closed`

## Access Control Matrix

| Action | Staff | Executive | Procurement | Supply Chain Director | Finance |
|--------|-------|-----------|-------------|----------------------|---------|
| Create MRF | ✅ | ❌ | ❌ | ❌ | ❌ |
| Edit MRF (after submission) | ❌ | ❌ | ❌ | ❌ | ❌ |
| Approve/Reject MRF | ❌ | ✅ | ❌ | ❌ | ❌ |
| Select Vendors | ❌ | ❌ | ✅ | ❌ | ❌ |
| View Invoices | ❌ | ❌ | ✅ | ❌ | ✅ (read-only) |
| Approve Invoice | ❌ | ❌ | ❌ | ✅ | ❌ |
| Generate PO | ❌ | ❌ | ✅ | ❌ | ❌ |
| Process Payment | ❌ | ❌ | ❌ | ❌ | ✅ |
| Request GRN | ❌ | ❌ | ❌ | ❌ | ✅ |
| Upload GRN | ❌ | ❌ | ✅ | ❌ | ❌ |
| View GRN | ✅ (own) | ✅ | ✅ | ✅ | ✅ |

## Next Steps

1. Implement vendor selection endpoint
2. Implement invoice approval endpoint
3. Update MRFWorkflowController methods
4. Test workflow transitions
5. Update frontend to use new endpoints
