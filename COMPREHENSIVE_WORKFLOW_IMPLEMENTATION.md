# Comprehensive Role-Based State-Driven Procurement Workflow

## Implementation Plan

This document outlines the complete implementation of a role-based, state-driven procurement workflow system.

## 1. Workflow States

### State Machine Definition

```
MRF_CREATED → MRF_APPROVED → PO_GENERATED → PO_REVIEWED → PO_SIGNED → PAYMENT_PROCESSED → GRN_REQUESTED → GRN_COMPLETED
     ↓              ↓
MRF_REJECTED   PO_REJECTED (can regenerate)
```

### Detailed States

1. **MRF_CREATED** - Regular Staff creates MRF with optional PFI
2. **MRF_APPROVED** - Executive approves MRF
3. **MRF_REJECTED** - Executive rejects MRF (can resubmit)
4. **PO_GENERATED** - Procurement Manager generates PO
5. **PO_REVIEWED** - Supply Chain Director reviews PO
6. **PO_SIGNED** - Supply Chain Director uploads signed PO
7. **PO_REJECTED** - Supply Chain Director rejects PO (can regenerate)
8. **PAYMENT_PROCESSED** - Finance Officer marks payment as processed
9. **GRN_REQUESTED** - Finance Officer requests GRN after payment
10. **GRN_COMPLETED** - Procurement Manager completes GRN

## 2. Role Permissions Matrix

| Role | Create MRF | Approve MRF | Generate PO | Review PO | Sign PO | Process Payment | Request GRN | Manage Users |
|------|-----------|-------------|-------------|-----------|---------|------------------|-------------|--------------|
| Regular Staff | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Executive | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Procurement Manager | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Supply Chain Director | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ❌ | ✅ |
| Finance Officer | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ |

## 3. Database Schema Updates

### MRF Table Additions
- `workflow_state` (enum) - Current workflow state
- `pfi_url` (text) - PFI document URL
- `pfi_share_url` (text) - PFI sharing link
- `grn_requested` (boolean) - GRN request flag
- `grn_requested_at` (timestamp) - When GRN was requested
- `grn_requested_by` (foreignId) - Who requested GRN
- `grn_completed` (boolean) - GRN completion flag
- `grn_completed_at` (timestamp) - When GRN was completed
- `grn_url` (text) - GRN document URL
- `grn_share_url` (text) - GRN sharing link

### Users Table Additions
- `is_admin` (boolean) - Admin flag
- `can_manage_users` (boolean) - User management permission

### GRN Table (if separate)
- `mrf_id` (foreignId) - Related MRF
- `po_number` (string) - Related PO
- `requested_by` (foreignId) - Finance Officer
- `completed_by` (foreignId) - Procurement Manager
- `status` (enum) - pending, completed
- `grn_url` (text) - GRN document URL
- `grn_share_url` (text) - GRN sharing link

## 4. Implementation Components

### Backend Services
1. **WorkflowStateService** - Manages state transitions
2. **PermissionService** - Role-based permission checks
3. **OneDriveService** - Document storage (already exists, extend for PFI/GRN)
4. **NotificationService** - Real-time notifications (already exists, extend)

### Backend Controllers
1. **MRFController** - Create MRF with PFI upload
2. **MRFWorkflowController** - State transitions (extend existing)
3. **GRNController** - GRN request and completion
4. **UserManagementController** - Admin user management

### Frontend Components
1. **NewMRF** - Add PFI upload field
2. **RoleDashboards** - Role-specific dashboards
3. **WorkflowTracker** - Visual state machine
4. **GRNRequestDialog** - Finance Officer GRN request
5. **UserManagement** - Admin user management

## 5. OneDrive Folder Structure

```
SupplyChainDocs/
├── MRFs/
│   └── {year}/
│       └── {mrf_id}/
│           ├── PFI_{mrf_id}.pdf
│           └── MRF_{mrf_id}.pdf
├── PurchaseOrders/
│   └── {year}/{month}/
│       ├── PO_{po_number}_{mrf_id}.pdf
│       └── PO_Signed_{po_number}_{mrf_id}.pdf
└── GRNs/
    └── {year}/{month}/
        └── GRN_{grn_number}_{mrf_id}.pdf
```

## 6. Notification Events

1. MRF_CREATED → Notify Executive
2. MRF_APPROVED → Notify Procurement Manager
3. MRF_REJECTED → Notify Requester
4. PO_GENERATED → Notify Supply Chain Director
5. PO_SIGNED → Notify Finance Officer
6. PAYMENT_PROCESSED → Notify Procurement Manager
7. GRN_REQUESTED → Notify Procurement Manager
8. GRN_COMPLETED → Notify Finance Officer

## 7. Test User Accounts

### Regular Staff
- Email: staff@emeraldcfze.com
- Password: Staff@2026
- Role: employee

### Executive
- Email: executive@emeraldcfze.com
- Password: Executive@2026
- Role: executive
- Admin: true

### Procurement Manager
- Email: procurement@emeraldcfze.com
- Password: Procurement@2026
- Role: procurement
- Admin: true

### Supply Chain Director
- Email: supplychain@emeraldcfze.com
- Password: SupplyChain@2026
- Role: supply_chain_director
- Admin: true

### Finance Officer
- Email: finance@emeraldcfze.com
- Password: Finance@2026
- Role: finance

## 8. Implementation Order

1. Database migrations (workflow_state, PFI, GRN fields)
2. WorkflowStateService
3. PermissionService
4. Update MRF creation with PFI upload
5. Update workflow controllers with state machine
6. GRN request/completion endpoints
7. User management endpoints
8. Frontend: PFI upload in NewMRF
9. Frontend: Role-specific dashboards
10. Frontend: GRN request UI
11. Frontend: User management UI
12. Notification integration
13. Testing and validation
