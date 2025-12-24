# Database Migrations Summary

All migrations have been completed for the Supply Chain Management system.

## Completed Migrations

### 1. Users Table Update
**File**: `2025_12_23_215015_add_role_and_department_to_users_table.php`
- Added `role` field (default: 'employee')
- Added `department` field (nullable)

### 2. Material Requisition Forms (MRFs)
**File**: `2025_12_23_215044_create_m_r_f_s_table.php`

**Fields**:
- `mrf_id` (unique) - Format: MRF-2025-001
- `title`, `category`, `urgency` (Low/Medium/High/Critical)
- `description`, `quantity`, `estimated_cost`
- `justification`, `requester_id`, `requester_name`
- `date`, `status` (Pending/Approved/Rejected/In Progress/Completed)
- `current_stage`, `approval_history` (JSON)
- `rejection_reason`, `is_resubmission`, `remarks`

**Indexes**: status+date, requester_id

### 3. Service Requisition Forms (SRFs)
**File**: `2025_12_23_215044_create_s_r_f_s_table.php`

**Fields**:
- `srf_id` (unique) - Format: SRF-2025-001
- `title`, `service_type`, `urgency`
- `description`, `duration`, `estimated_cost`
- `justification`, `requester_id`, `requester_name`
- `date`, `status`, `current_stage`
- `approval_history` (JSON), `rejection_reason`, `remarks`

**Indexes**: status+date, requester_id

### 4. Request for Quotations (RFQs)
**File**: `2025_12_23_215044_create_r_f_q_s_table.php`

**Fields**:
- `rfq_id` (unique) - Format: RFQ-2025-001
- `mrf_id` (foreign key to MRFs)
- `mrf_title`, `description`, `quantity`
- `estimated_cost`, `deadline`
- `status` (Open/Closed/Awarded/Cancelled)
- `created_by` (foreign key to users)

**Indexes**: status+deadline, mrf_id

### 5. RFQ Vendors (Pivot Table)
**File**: `2025_12_23_220106_create_rfq_vendors_table.php`

**Fields**:
- `rfq_id` (foreign key to RFQs)
- `vendor_id` (foreign key to Vendors)

**Unique Constraint**: rfq_id + vendor_id combination

### 6. Quotations
**File**: `2025_12_23_215044_create_quotations_table.php`

**Fields**:
- `quotation_id` (unique) - Format: QUO-2025-001
- `rfq_id` (foreign key to RFQs)
- `vendor_id` (foreign key to Vendors)
- `vendor_name`, `price`, `delivery_date`
- `notes`, `status` (Pending/Approved/Rejected)
- `rejection_reason`, `approval_remarks`
- `approved_by`, `approved_at`

**Indexes**: rfq_id+status, vendor_id

### 7. Vendors
**File**: `2025_12_23_215045_create_vendors_table.php`

**Fields**:
- `vendor_id` (unique) - Format: V001
- `name`, `category`, `rating` (0.00-5.00)
- `total_orders`, `status` (Active/Inactive/Pending/Suspended)
- `email` (unique), `phone`, `address`
- `tax_id`, `contact_person`, `notes`

**Indexes**: status+category, vendor_id

### 8. Vendor Registrations
**File**: `2025_12_23_215045_create_vendor_registrations_table.php`

**Fields**:
- `company_name`, `category`, `email` (unique)
- `phone`, `address`, `tax_id`, `contact_person`
- `status` (Pending/Approved/Rejected)
- `rejection_reason`, `approval_remarks`
- `approved_by`, `approved_at`
- `vendor_id` (foreign key to Vendors, nullable - set when approved)

**Indexes**: status, email

## Relationships

1. **MRF** → User (requester_id)
2. **SRF** → User (requester_id)
3. **RFQ** → MRF (mrf_id), User (created_by)
4. **RFQ** ↔ **Vendor** (many-to-many via rfq_vendors)
5. **Quotation** → RFQ (rfq_id), Vendor (vendor_id), User (approved_by)
6. **Vendor Registration** → Vendor (vendor_id, when approved), User (approved_by)

## Next Steps

1. Run migrations: `php artisan migrate`
2. Update models with relationships and fillable fields
3. Implement controller methods
4. Add validation rules
5. Test API endpoints

## Notes

- All ID fields use string format (MRF-2025-001) for better readability
- Approval history stored as JSON for flexibility
- Foreign keys use `onDelete('cascade')` or `onDelete('set null')` appropriately
- Indexes added for common query patterns (status, dates, foreign keys)

