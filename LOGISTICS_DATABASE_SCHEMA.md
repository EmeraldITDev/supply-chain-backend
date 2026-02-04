# Logistics Module - Database Schema Specification

## Overview
This document defines the complete database schema for the SCM Logistics Module, including all tables, columns, data types, relationships, indexes, and enum types required for backend implementation.

---

## Enum Types

```sql
-- Vendor classification
CREATE TYPE logistics_vendor_type AS ENUM ('internal', 'external', 'one_time');

-- Vendor lifecycle status
CREATE TYPE logistics_vendor_status AS ENUM ('active', 'inactive', 'pending', 'invited');

-- Trip scheduling status flow
CREATE TYPE trip_status AS ENUM (
  'draft',
  'scheduled',
  'vendor_assigned',
  'in_progress',
  'completed',
  'closed',
  'cancelled'
);

-- Trip classification
CREATE TYPE trip_type AS ENUM ('personnel', 'material', 'mixed');

-- Priority levels
CREATE TYPE priority_level AS ENUM ('low', 'normal', 'high', 'urgent');

-- Journey execution status flow
CREATE TYPE journey_status AS ENUM (
  'not_started',
  'departed',
  'en_route',
  'at_checkpoint',
  'arrived',
  'closed'
);

-- Incident classification
CREATE TYPE incident_type AS ENUM ('delay', 'breakdown', 'accident', 'weather', 'other');
CREATE TYPE severity_level AS ENUM ('low', 'medium', 'high', 'critical');

-- Material tracking
CREATE TYPE material_status AS ENUM ('available', 'in_transit', 'delivered', 'damaged', 'lost');
CREATE TYPE material_condition AS ENUM ('new', 'used', 'damaged');

-- Fleet management
CREATE TYPE vehicle_status AS ENUM ('available', 'in_use', 'maintenance', 'out_of_service');
CREATE TYPE vehicle_ownership AS ENUM ('owned', 'leased', 'vendor', 'rental');
CREATE TYPE approval_status AS ENUM ('pending', 'approved', 'rejected');

-- Document types
CREATE TYPE vehicle_document_type AS ENUM (
  'registration',
  'insurance',
  'roadworthiness',
  'license',
  'permit',
  'other'
);

-- Maintenance types
CREATE TYPE maintenance_type AS ENUM ('scheduled', 'unscheduled', 'repair', 'inspection');

-- Reporting
CREATE TYPE report_type AS ENUM ('trip', 'daily', 'weekly', 'monthly', 'incident', 'compliance', 'custom');
CREATE TYPE report_status AS ENUM ('draft', 'submitted', 'reviewed', 'approved', 'rejected');

-- Notification types
CREATE TYPE logistics_notification_type AS ENUM (
  'trip_assigned',
  'trip_started',
  'trip_completed',
  'journey_update',
  'document_expiring',
  'document_expired',
  'maintenance_due',
  'report_overdue',
  'vendor_assigned',
  'passenger_notification'
);
```

---

## Tables

### 1. logistics_vendors

Manages logistics-specific vendor data including one-time vendors with email-only access.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| vendor_id | VARCHAR(20) | NOT NULL | - | Display ID (e.g., LV-001) |
| name | VARCHAR(255) | NOT NULL | - | Vendor company name |
| type | logistics_vendor_type | NOT NULL | 'external' | Vendor classification |
| email | VARCHAR(255) | NOT NULL | - | Primary contact email |
| phone | VARCHAR(50) | NULL | - | Contact phone number |
| address | TEXT | NULL | - | Physical address |
| contact_person | VARCHAR(255) | NULL | - | Primary contact name |
| status | logistics_vendor_status | NOT NULL | 'pending' | Lifecycle status |
| access_token | VARCHAR(255) | NULL | - | One-time vendor access token |
| access_expires_at | TIMESTAMPTZ | NULL | - | Token expiration for one-time vendors |
| documents_valid | BOOLEAN | NULL | TRUE | Compliance document status |
| last_verified_at | TIMESTAMPTZ | NULL | - | Last compliance verification |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |
| updated_at | TIMESTAMPTZ | NULL | - | Last update timestamp |

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_logistics_vendors_vendor_id ON logistics_vendors(vendor_id);
CREATE UNIQUE INDEX idx_logistics_vendors_email ON logistics_vendors(email);
CREATE INDEX idx_logistics_vendors_type ON logistics_vendors(type);
CREATE INDEX idx_logistics_vendors_status ON logistics_vendors(status);
```

**Constraints:**
```sql
ALTER TABLE logistics_vendors ADD CONSTRAINT chk_access_token_one_time 
  CHECK (type != 'one_time' OR access_token IS NOT NULL);
```

---

### 2. vendor_invites

Tracks email invitations sent to vendors for system access.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| email | VARCHAR(255) | NOT NULL | - | Invited email address |
| vendor_type | logistics_vendor_type | NOT NULL | - | Type of vendor being invited |
| trip_id | UUID | NULL | - | Associated trip (for one-time) |
| token | VARCHAR(255) | NOT NULL | - | Unique invitation token |
| expires_at | TIMESTAMPTZ | NOT NULL | - | Invitation expiration |
| accepted_at | TIMESTAMPTZ | NULL | - | When invitation was accepted |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE vendor_invites ADD CONSTRAINT fk_vendor_invites_trip 
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_vendor_invites_token ON vendor_invites(token);
CREATE INDEX idx_vendor_invites_email ON vendor_invites(email);
CREATE INDEX idx_vendor_invites_expires_at ON vendor_invites(expires_at);
```

---

### 3. trips

Core trip scheduling table managing the planning layer.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| trip_number | VARCHAR(20) | NOT NULL | - | Display ID (e.g., TRP-2025-001) |
| type | trip_type | NOT NULL | 'personnel' | Trip classification |
| status | trip_status | NOT NULL | 'draft' | Current lifecycle status |
| origin | VARCHAR(255) | NOT NULL | - | Starting location |
| destination | VARCHAR(255) | NOT NULL | - | End location |
| route | TEXT | NULL | - | Full route description |
| distance | DECIMAL(10,2) | NULL | - | Estimated distance in km |
| estimated_duration | VARCHAR(50) | NULL | - | Duration string (e.g., "4h 30m") |
| scheduled_departure_at | TIMESTAMPTZ | NOT NULL | - | Planned departure time |
| scheduled_arrival_at | TIMESTAMPTZ | NULL | - | Planned arrival time |
| actual_departure_at | TIMESTAMPTZ | NULL | - | Actual departure time |
| actual_arrival_at | TIMESTAMPTZ | NULL | - | Actual arrival time |
| vendor_id | UUID | NULL | - | Assigned logistics vendor |
| vehicle_id | UUID | NULL | - | Assigned vehicle |
| driver_id | UUID | NULL | - | Assigned driver (staff member) |
| driver_name | VARCHAR(255) | NULL | - | Driver display name |
| driver_phone | VARCHAR(50) | NULL | - | Driver contact number |
| cargo | TEXT | NULL | - | General cargo description |
| purpose | TEXT | NULL | - | Trip purpose/reason |
| priority | priority_level | NOT NULL | 'normal' | Priority classification |
| notes | TEXT | NULL | - | Additional notes |
| scheduled_by | UUID | NOT NULL | - | User who created the trip |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |
| updated_at | TIMESTAMPTZ | NULL | - | Last update timestamp |

**Foreign Keys:**
```sql
ALTER TABLE trips ADD CONSTRAINT fk_trips_vendor 
  FOREIGN KEY (vendor_id) REFERENCES logistics_vendors(id) ON DELETE SET NULL;
ALTER TABLE trips ADD CONSTRAINT fk_trips_vehicle 
  FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id) ON DELETE SET NULL;
ALTER TABLE trips ADD CONSTRAINT fk_trips_scheduled_by 
  FOREIGN KEY (scheduled_by) REFERENCES auth.users(id) ON DELETE RESTRICT;
```

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_trips_trip_number ON trips(trip_number);
CREATE INDEX idx_trips_status ON trips(status);
CREATE INDEX idx_trips_type ON trips(type);
CREATE INDEX idx_trips_vendor_id ON trips(vendor_id);
CREATE INDEX idx_trips_vehicle_id ON trips(vehicle_id);
CREATE INDEX idx_trips_scheduled_departure_at ON trips(scheduled_departure_at);
CREATE INDEX idx_trips_scheduled_by ON trips(scheduled_by);
CREATE INDEX idx_trips_priority ON trips(priority);
```

---

### 4. trip_passengers

Links staff members to trips as passengers.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| trip_id | UUID | NOT NULL | - | Parent trip |
| staff_id | VARCHAR(50) | NOT NULL | - | Employee staff ID |
| name | VARCHAR(255) | NOT NULL | - | Passenger full name |
| email | VARCHAR(255) | NOT NULL | - | Passenger email |
| department | VARCHAR(100) | NOT NULL | - | Employee department |
| pickup_location | VARCHAR(255) | NULL | - | Custom pickup point |
| dropoff_location | VARCHAR(255) | NULL | - | Custom drop-off point |
| notified_at | TIMESTAMPTZ | NULL | - | When notification was sent |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE trip_passengers ADD CONSTRAINT fk_trip_passengers_trip 
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE;
```

**Indexes:**
```sql
CREATE INDEX idx_trip_passengers_trip_id ON trip_passengers(trip_id);
CREATE INDEX idx_trip_passengers_staff_id ON trip_passengers(staff_id);
CREATE INDEX idx_trip_passengers_email ON trip_passengers(email);
```

---

### 5. trip_materials

Links materials to trips for tracking during transport.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| trip_id | UUID | NOT NULL | - | Parent trip |
| material_id | UUID | NOT NULL | - | Reference to materials table |
| quantity | DECIMAL(10,2) | NOT NULL | - | Quantity being transported |
| unit | VARCHAR(50) | NOT NULL | - | Unit of measure |
| condition | material_condition | NOT NULL | 'new' | Condition at loading |
| notes | TEXT | NULL | - | Loading/handling notes |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE trip_materials ADD CONSTRAINT fk_trip_materials_trip 
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE;
ALTER TABLE trip_materials ADD CONSTRAINT fk_trip_materials_material 
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT;
```

**Indexes:**
```sql
CREATE INDEX idx_trip_materials_trip_id ON trip_materials(trip_id);
CREATE INDEX idx_trip_materials_material_id ON trip_materials(material_id);
```

---

### 6. journeys

Execution layer tracking actual trip progress.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| trip_id | UUID | NOT NULL | - | Associated scheduled trip |
| status | journey_status | NOT NULL | 'not_started' | Current execution status |
| departed_at | TIMESTAMPTZ | NULL | - | Actual departure time |
| departed_from | VARCHAR(255) | NULL | - | Actual departure location |
| arrived_at | TIMESTAMPTZ | NULL | - | Actual arrival time |
| arrived_to | VARCHAR(255) | NULL | - | Actual arrival location |
| current_location | VARCHAR(255) | NULL | - | Real-time location update |
| total_distance | DECIMAL(10,2) | NULL | - | Actual distance traveled (km) |
| total_duration | VARCHAR(50) | NULL | - | Actual journey duration |
| delay_minutes | INTEGER | NULL | - | Delay from schedule |
| last_updated_at | TIMESTAMPTZ | NULL | - | Last status update |
| updated_by | UUID | NULL | - | User who last updated |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE journeys ADD CONSTRAINT fk_journeys_trip 
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE;
ALTER TABLE journeys ADD CONSTRAINT fk_journeys_updated_by 
  FOREIGN KEY (updated_by) REFERENCES auth.users(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_journeys_trip_id ON journeys(trip_id);
CREATE INDEX idx_journeys_status ON journeys(status);
CREATE INDEX idx_journeys_departed_at ON journeys(departed_at);
```

---

### 7. journey_checkpoints

Records intermediate stops during journey execution.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| journey_id | UUID | NOT NULL | - | Parent journey |
| location | VARCHAR(255) | NOT NULL | - | Checkpoint location name |
| arrived_at | TIMESTAMPTZ | NOT NULL | - | Arrival time at checkpoint |
| departed_at | TIMESTAMPTZ | NULL | - | Departure time from checkpoint |
| notes | TEXT | NULL | - | Checkpoint notes |
| latitude | DECIMAL(10,8) | NULL | - | GPS latitude |
| longitude | DECIMAL(11,8) | NULL | - | GPS longitude |
| recorded_by | UUID | NULL | - | User who recorded |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE journey_checkpoints ADD CONSTRAINT fk_journey_checkpoints_journey 
  FOREIGN KEY (journey_id) REFERENCES journeys(id) ON DELETE CASCADE;
ALTER TABLE journey_checkpoints ADD CONSTRAINT fk_journey_checkpoints_recorded_by 
  FOREIGN KEY (recorded_by) REFERENCES auth.users(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE INDEX idx_journey_checkpoints_journey_id ON journey_checkpoints(journey_id);
CREATE INDEX idx_journey_checkpoints_arrived_at ON journey_checkpoints(arrived_at);
```

---

### 8. journey_incidents

Records issues/problems during journey execution.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| journey_id | UUID | NOT NULL | - | Parent journey |
| type | incident_type | NOT NULL | - | Incident classification |
| description | TEXT | NOT NULL | - | Detailed description |
| location | VARCHAR(255) | NULL | - | Incident location |
| severity | severity_level | NOT NULL | 'low' | Impact severity |
| reported_at | TIMESTAMPTZ | NOT NULL | NOW() | When incident was reported |
| resolved_at | TIMESTAMPTZ | NULL | - | When incident was resolved |
| reported_by | UUID | NULL | - | User who reported |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE journey_incidents ADD CONSTRAINT fk_journey_incidents_journey 
  FOREIGN KEY (journey_id) REFERENCES journeys(id) ON DELETE CASCADE;
ALTER TABLE journey_incidents ADD CONSTRAINT fk_journey_incidents_reported_by 
  FOREIGN KEY (reported_by) REFERENCES auth.users(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE INDEX idx_journey_incidents_journey_id ON journey_incidents(journey_id);
CREATE INDEX idx_journey_incidents_type ON journey_incidents(type);
CREATE INDEX idx_journey_incidents_severity ON journey_incidents(severity);
CREATE INDEX idx_journey_incidents_reported_at ON journey_incidents(reported_at);
```

---

### 9. materials

Material inventory registry for logistics tracking.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| material_number | VARCHAR(20) | NOT NULL | - | Display ID (e.g., MAT-2025-001) |
| name | VARCHAR(255) | NOT NULL | - | Material name |
| description | TEXT | NULL | - | Detailed description |
| category | VARCHAR(100) | NOT NULL | - | Material category |
| quantity | DECIMAL(10,2) | NOT NULL | 0 | Current quantity |
| unit | VARCHAR(50) | NOT NULL | - | Unit of measure |
| condition | material_condition | NOT NULL | 'new' | Current condition |
| status | material_status | NOT NULL | 'available' | Current status |
| current_location | VARCHAR(255) | NOT NULL | - | Current storage location |
| warehouse_id | UUID | NULL | - | Associated warehouse |
| last_moved_at | TIMESTAMPTZ | NULL | - | Last movement timestamp |
| last_trip_id | UUID | NULL | - | Last trip that moved this |
| movement_count | INTEGER | NOT NULL | 0 | Total movement count |
| weight | DECIMAL(10,2) | NULL | - | Weight in kg |
| dimensions | VARCHAR(100) | NULL | - | L x W x H dimensions |
| value | DECIMAL(12,2) | NULL | - | Monetary value |
| notes | TEXT | NULL | - | Additional notes |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |
| updated_at | TIMESTAMPTZ | NULL | - | Last update timestamp |

**Foreign Keys:**
```sql
ALTER TABLE materials ADD CONSTRAINT fk_materials_last_trip 
  FOREIGN KEY (last_trip_id) REFERENCES trips(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_materials_material_number ON materials(material_number);
CREATE INDEX idx_materials_category ON materials(category);
CREATE INDEX idx_materials_status ON materials(status);
CREATE INDEX idx_materials_condition ON materials(condition);
CREATE INDEX idx_materials_current_location ON materials(current_location);
CREATE INDEX idx_materials_warehouse_id ON materials(warehouse_id);
```

---

### 10. material_movements

Tracks material movement history.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| material_id | UUID | NOT NULL | - | Material being moved |
| trip_id | UUID | NOT NULL | - | Trip transporting material |
| from_location | VARCHAR(255) | NOT NULL | - | Origin location |
| to_location | VARCHAR(255) | NOT NULL | - | Destination location |
| quantity | DECIMAL(10,2) | NOT NULL | - | Quantity moved |
| condition_before | material_condition | NOT NULL | - | Condition at origin |
| condition_after | material_condition | NULL | - | Condition at destination |
| moved_at | TIMESTAMPTZ | NOT NULL | NOW() | When movement started |
| received_at | TIMESTAMPTZ | NULL | - | When received at destination |
| received_by | UUID | NULL | - | User who received |
| notes | TEXT | NULL | - | Movement notes |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE material_movements ADD CONSTRAINT fk_material_movements_material 
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT;
ALTER TABLE material_movements ADD CONSTRAINT fk_material_movements_trip 
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE RESTRICT;
ALTER TABLE material_movements ADD CONSTRAINT fk_material_movements_received_by 
  FOREIGN KEY (received_by) REFERENCES auth.users(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE INDEX idx_material_movements_material_id ON material_movements(material_id);
CREATE INDEX idx_material_movements_trip_id ON material_movements(trip_id);
CREATE INDEX idx_material_movements_moved_at ON material_movements(moved_at);
```

---

### 11. fleet_vehicles

Vehicle registry for fleet management.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| vehicle_number | VARCHAR(20) | NOT NULL | - | Display ID (e.g., VEH-001) |
| plate | VARCHAR(20) | NOT NULL | - | License plate number |
| name | VARCHAR(255) | NOT NULL | - | Vehicle display name |
| type | VARCHAR(50) | NOT NULL | - | Vehicle type (Sedan, Truck, etc.) |
| make | VARCHAR(100) | NULL | - | Manufacturer |
| model | VARCHAR(100) | NULL | - | Model name |
| year | INTEGER | NULL | - | Manufacturing year |
| color | VARCHAR(50) | NULL | - | Vehicle color |
| ownership | vehicle_ownership | NOT NULL | 'owned' | Ownership type |
| vendor_id | UUID | NULL | - | Vendor if leased/vendor-owned |
| status | vehicle_status | NOT NULL | 'available' | Operational status |
| approval_status | approval_status | NOT NULL | 'pending' | Admin approval status |
| approved_by | UUID | NULL | - | User who approved |
| approved_at | TIMESTAMPTZ | NULL | - | Approval timestamp |
| passenger_capacity | INTEGER | NULL | - | Max passengers |
| cargo_capacity | DECIMAL(10,2) | NULL | - | Max cargo weight (kg) |
| fuel_type | VARCHAR(50) | NULL | - | Fuel type |
| fuel_capacity | DECIMAL(10,2) | NULL | - | Fuel tank capacity (L) |
| last_maintenance_at | TIMESTAMPTZ | NULL | - | Last maintenance date |
| next_maintenance_at | TIMESTAMPTZ | NULL | - | Scheduled maintenance |
| current_driver_id | UUID | NULL | - | Currently assigned driver |
| current_trip_id | UUID | NULL | - | Current active trip |
| total_trips | INTEGER | NOT NULL | 0 | Lifetime trip count |
| total_distance | DECIMAL(12,2) | NOT NULL | 0 | Lifetime distance (km) |
| gps_enabled | BOOLEAN | NOT NULL | FALSE | GPS tracking enabled |
| gps_device_id | VARCHAR(100) | NULL | - | GPS device identifier |
| last_known_latitude | DECIMAL(10,8) | NULL | - | Last GPS latitude |
| last_known_longitude | DECIMAL(11,8) | NULL | - | Last GPS longitude |
| last_location_at | TIMESTAMPTZ | NULL | - | Last GPS update |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |
| updated_at | TIMESTAMPTZ | NULL | - | Last update timestamp |

**Foreign Keys:**
```sql
ALTER TABLE fleet_vehicles ADD CONSTRAINT fk_fleet_vehicles_vendor 
  FOREIGN KEY (vendor_id) REFERENCES logistics_vendors(id) ON DELETE SET NULL;
ALTER TABLE fleet_vehicles ADD CONSTRAINT fk_fleet_vehicles_approved_by 
  FOREIGN KEY (approved_by) REFERENCES auth.users(id) ON DELETE SET NULL;
ALTER TABLE fleet_vehicles ADD CONSTRAINT fk_fleet_vehicles_current_trip 
  FOREIGN KEY (current_trip_id) REFERENCES trips(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_fleet_vehicles_vehicle_number ON fleet_vehicles(vehicle_number);
CREATE UNIQUE INDEX idx_fleet_vehicles_plate ON fleet_vehicles(plate);
CREATE INDEX idx_fleet_vehicles_type ON fleet_vehicles(type);
CREATE INDEX idx_fleet_vehicles_ownership ON fleet_vehicles(ownership);
CREATE INDEX idx_fleet_vehicles_vendor_id ON fleet_vehicles(vendor_id);
CREATE INDEX idx_fleet_vehicles_status ON fleet_vehicles(status);
CREATE INDEX idx_fleet_vehicles_approval_status ON fleet_vehicles(approval_status);
CREATE INDEX idx_fleet_vehicles_next_maintenance_at ON fleet_vehicles(next_maintenance_at);
```

---

### 12. vehicle_documents

Tracks vehicle compliance documents with expiry.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| vehicle_id | UUID | NOT NULL | - | Parent vehicle |
| type | vehicle_document_type | NOT NULL | - | Document classification |
| name | VARCHAR(255) | NOT NULL | - | Document name/title |
| file_url | VARCHAR(500) | NULL | - | Storage URL |
| uploaded_at | TIMESTAMPTZ | NOT NULL | NOW() | Upload timestamp |
| expires_at | TIMESTAMPTZ | NULL | - | Expiration date |
| verified_at | TIMESTAMPTZ | NULL | - | Verification timestamp |
| verified_by | UUID | NULL | - | User who verified |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE vehicle_documents ADD CONSTRAINT fk_vehicle_documents_vehicle 
  FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id) ON DELETE CASCADE;
ALTER TABLE vehicle_documents ADD CONSTRAINT fk_vehicle_documents_verified_by 
  FOREIGN KEY (verified_by) REFERENCES auth.users(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE INDEX idx_vehicle_documents_vehicle_id ON vehicle_documents(vehicle_id);
CREATE INDEX idx_vehicle_documents_type ON vehicle_documents(type);
CREATE INDEX idx_vehicle_documents_expires_at ON vehicle_documents(expires_at);
```

---

### 13. maintenance_records

Vehicle maintenance history.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| vehicle_id | UUID | NOT NULL | - | Parent vehicle |
| type | maintenance_type | NOT NULL | - | Maintenance classification |
| description | TEXT | NOT NULL | - | Work description |
| performed_at | TIMESTAMPTZ | NOT NULL | - | When maintenance occurred |
| performed_by | VARCHAR(255) | NOT NULL | - | Mechanic/service provider |
| cost | DECIMAL(12,2) | NULL | - | Maintenance cost |
| odometer | INTEGER | NULL | - | Odometer reading |
| notes | TEXT | NULL | - | Additional notes |
| next_scheduled_at | TIMESTAMPTZ | NULL | - | Next scheduled maintenance |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE maintenance_records ADD CONSTRAINT fk_maintenance_records_vehicle 
  FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicles(id) ON DELETE CASCADE;
```

**Indexes:**
```sql
CREATE INDEX idx_maintenance_records_vehicle_id ON maintenance_records(vehicle_id);
CREATE INDEX idx_maintenance_records_type ON maintenance_records(type);
CREATE INDEX idx_maintenance_records_performed_at ON maintenance_records(performed_at);
CREATE INDEX idx_maintenance_records_next_scheduled_at ON maintenance_records(next_scheduled_at);
```

---

### 14. logistics_reports

Operational and compliance reports.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| report_number | VARCHAR(20) | NOT NULL | - | Display ID (e.g., RPT-2025-001) |
| type | report_type | NOT NULL | - | Report classification |
| title | VARCHAR(255) | NOT NULL | - | Report title |
| description | TEXT | NULL | - | Report description |
| status | report_status | NOT NULL | 'draft' | Submission status |
| period_start | DATE | NULL | - | Reporting period start |
| period_end | DATE | NULL | - | Reporting period end |
| trip_id | UUID | NULL | - | Associated trip (for trip reports) |
| content | TEXT | NULL | - | Report content/body |
| submitted_by | UUID | NOT NULL | - | User who created |
| submitted_at | TIMESTAMPTZ | NULL | - | Submission timestamp |
| due_at | TIMESTAMPTZ | NULL | - | Due date |
| reviewed_by | UUID | NULL | - | Reviewing manager |
| reviewed_at | TIMESTAMPTZ | NULL | - | Review timestamp |
| review_notes | TEXT | NULL | - | Review comments |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |
| updated_at | TIMESTAMPTZ | NULL | - | Last update timestamp |

**Foreign Keys:**
```sql
ALTER TABLE logistics_reports ADD CONSTRAINT fk_logistics_reports_trip 
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL;
ALTER TABLE logistics_reports ADD CONSTRAINT fk_logistics_reports_submitted_by 
  FOREIGN KEY (submitted_by) REFERENCES auth.users(id) ON DELETE RESTRICT;
ALTER TABLE logistics_reports ADD CONSTRAINT fk_logistics_reports_reviewed_by 
  FOREIGN KEY (reviewed_by) REFERENCES auth.users(id) ON DELETE SET NULL;
```

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_logistics_reports_report_number ON logistics_reports(report_number);
CREATE INDEX idx_logistics_reports_type ON logistics_reports(type);
CREATE INDEX idx_logistics_reports_status ON logistics_reports(status);
CREATE INDEX idx_logistics_reports_trip_id ON logistics_reports(trip_id);
CREATE INDEX idx_logistics_reports_submitted_by ON logistics_reports(submitted_by);
CREATE INDEX idx_logistics_reports_due_at ON logistics_reports(due_at);
```

---

### 15. report_attachments

Files attached to logistics reports.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| report_id | UUID | NOT NULL | - | Parent report |
| name | VARCHAR(255) | NOT NULL | - | File name |
| file_url | VARCHAR(500) | NOT NULL | - | Storage URL |
| file_type | VARCHAR(100) | NOT NULL | - | MIME type |
| file_size | INTEGER | NOT NULL | - | Size in bytes |
| uploaded_at | TIMESTAMPTZ | NOT NULL | NOW() | Upload timestamp |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE report_attachments ADD CONSTRAINT fk_report_attachments_report 
  FOREIGN KEY (report_id) REFERENCES logistics_reports(id) ON DELETE CASCADE;
```

**Indexes:**
```sql
CREATE INDEX idx_report_attachments_report_id ON report_attachments(report_id);
```

---

### 16. logistics_notifications

Event-driven notifications for logistics module.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | UUID | NOT NULL | gen_random_uuid() | Primary key |
| type | logistics_notification_type | NOT NULL | - | Notification classification |
| title | VARCHAR(255) | NOT NULL | - | Notification title |
| message | TEXT | NOT NULL | - | Notification body |
| recipient_id | UUID | NOT NULL | - | Target user |
| recipient_email | VARCHAR(255) | NULL | - | Email (for external vendors) |
| entity_type | VARCHAR(50) | NULL | - | Related entity type |
| entity_id | UUID | NULL | - | Related entity ID |
| is_read | BOOLEAN | NOT NULL | FALSE | Read status |
| sent_at | TIMESTAMPTZ | NOT NULL | NOW() | When notification was sent |
| read_at | TIMESTAMPTZ | NULL | - | When notification was read |
| action_url | VARCHAR(500) | NULL | - | Deep link URL |
| created_at | TIMESTAMPTZ | NOT NULL | NOW() | Record creation timestamp |

**Foreign Keys:**
```sql
ALTER TABLE logistics_notifications ADD CONSTRAINT fk_logistics_notifications_recipient 
  FOREIGN KEY (recipient_id) REFERENCES auth.users(id) ON DELETE CASCADE;
```

**Indexes:**
```sql
CREATE INDEX idx_logistics_notifications_recipient_id ON logistics_notifications(recipient_id);
CREATE INDEX idx_logistics_notifications_type ON logistics_notifications(type);
CREATE INDEX idx_logistics_notifications_is_read ON logistics_notifications(is_read);
CREATE INDEX idx_logistics_notifications_sent_at ON logistics_notifications(sent_at);
CREATE INDEX idx_logistics_notifications_entity ON logistics_notifications(entity_type, entity_id);
```

---

## Views

### 1. v_fleet_alerts

Aggregates document expiry and maintenance alerts.

```sql
CREATE VIEW v_fleet_alerts AS
SELECT 
  fv.id AS vehicle_id,
  fv.vehicle_number,
  fv.plate,
  fv.name AS vehicle_name,
  'document_expiring' AS alert_type,
  CASE 
    WHEN vd.expires_at <= NOW() THEN 'critical'
    WHEN vd.expires_at <= NOW() + INTERVAL '7 days' THEN 'high'
    WHEN vd.expires_at <= NOW() + INTERVAL '30 days' THEN 'medium'
    ELSE 'low'
  END AS severity,
  vd.type || ' expiring: ' || vd.name AS message,
  vd.expires_at AS due_at,
  EXTRACT(DAY FROM vd.expires_at - NOW())::INTEGER AS days_remaining
FROM fleet_vehicles fv
JOIN vehicle_documents vd ON fv.id = vd.vehicle_id
WHERE vd.expires_at <= NOW() + INTERVAL '30 days'

UNION ALL

SELECT 
  fv.id AS vehicle_id,
  fv.vehicle_number,
  fv.plate,
  fv.name AS vehicle_name,
  'maintenance_due' AS alert_type,
  CASE 
    WHEN fv.next_maintenance_at <= NOW() THEN 'critical'
    WHEN fv.next_maintenance_at <= NOW() + INTERVAL '7 days' THEN 'high'
    ELSE 'medium'
  END AS severity,
  'Scheduled maintenance due' AS message,
  fv.next_maintenance_at AS due_at,
  EXTRACT(DAY FROM fv.next_maintenance_at - NOW())::INTEGER AS days_remaining
FROM fleet_vehicles fv
WHERE fv.next_maintenance_at IS NOT NULL 
  AND fv.next_maintenance_at <= NOW() + INTERVAL '14 days';
```

### 2. v_pending_reports

Lists overdue and pending reports.

```sql
CREATE VIEW v_pending_reports AS
SELECT 
  lr.id,
  lr.report_number,
  lr.type,
  lr.title,
  lr.due_at,
  lr.submitted_by,
  lr.trip_id,
  t.trip_number,
  CASE 
    WHEN lr.due_at < NOW() THEN EXTRACT(DAY FROM NOW() - lr.due_at)::INTEGER
    ELSE 0
  END AS days_overdue
FROM logistics_reports lr
LEFT JOIN trips t ON lr.trip_id = t.id
WHERE lr.status IN ('draft', 'submitted')
  AND lr.due_at IS NOT NULL
ORDER BY lr.due_at ASC;
```

---

## Triggers

### 1. Auto-create journey on trip scheduling

```sql
CREATE OR REPLACE FUNCTION create_journey_for_trip()
RETURNS TRIGGER AS $$
BEGIN
  IF NEW.status = 'scheduled' AND OLD.status = 'draft' THEN
    INSERT INTO journeys (trip_id, status)
    VALUES (NEW.id, 'not_started');
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_create_journey_on_trip_schedule
AFTER UPDATE ON trips
FOR EACH ROW
WHEN (NEW.status = 'scheduled' AND OLD.status = 'draft')
EXECUTE FUNCTION create_journey_for_trip();
```

### 2. Update material movement count

```sql
CREATE OR REPLACE FUNCTION increment_material_movement()
RETURNS TRIGGER AS $$
BEGIN
  UPDATE materials 
  SET movement_count = movement_count + 1,
      last_moved_at = NEW.moved_at,
      last_trip_id = NEW.trip_id
  WHERE id = NEW.material_id;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_increment_material_movement
AFTER INSERT ON material_movements
FOR EACH ROW
EXECUTE FUNCTION increment_material_movement();
```

### 3. Update vehicle trip statistics

```sql
CREATE OR REPLACE FUNCTION update_vehicle_trip_stats()
RETURNS TRIGGER AS $$
BEGIN
  IF NEW.status = 'completed' AND OLD.status != 'completed' THEN
    UPDATE fleet_vehicles 
    SET total_trips = total_trips + 1,
        total_distance = total_distance + COALESCE(
          (SELECT total_distance FROM journeys WHERE trip_id = NEW.id), 0
        ),
        current_trip_id = NULL
    WHERE id = NEW.vehicle_id;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_update_vehicle_trip_stats
AFTER UPDATE ON trips
FOR EACH ROW
WHEN (NEW.status = 'completed' AND OLD.status != 'completed')
EXECUTE FUNCTION update_vehicle_trip_stats();
```

---

## Sequence Generators

For generating formatted display IDs:

```sql
CREATE SEQUENCE seq_trip_number START 1;
CREATE SEQUENCE seq_material_number START 1;
CREATE SEQUENCE seq_vehicle_number START 1;
CREATE SEQUENCE seq_report_number START 1;
CREATE SEQUENCE seq_logistics_vendor_id START 1;

-- Function to generate trip numbers
CREATE OR REPLACE FUNCTION generate_trip_number()
RETURNS VARCHAR AS $$
BEGIN
  RETURN 'TRP-' || TO_CHAR(NOW(), 'YYYY') || '-' || LPAD(nextval('seq_trip_number')::TEXT, 3, '0');
END;
$$ LANGUAGE plpgsql;
```

---

## RLS Policies (Examples)

```sql
-- Enable RLS
ALTER TABLE trips ENABLE ROW LEVEL SECURITY;
ALTER TABLE journeys ENABLE ROW LEVEL SECURITY;
ALTER TABLE fleet_vehicles ENABLE ROW LEVEL SECURITY;

-- Logistics staff can view all trips
CREATE POLICY "Logistics staff can view all trips"
ON trips FOR SELECT
TO authenticated
USING (
  public.has_role(auth.uid(), 'logistics_manager') OR
  public.has_role(auth.uid(), 'logistics_coordinator') OR
  public.has_role(auth.uid(), 'admin')
);

-- Vendors can view only their assigned trips
CREATE POLICY "Vendors can view assigned trips"
ON trips FOR SELECT
TO authenticated
USING (
  vendor_id IN (
    SELECT id FROM logistics_vendors 
    WHERE access_token = current_setting('app.vendor_token', true)
  )
);

-- Drivers can view trips they're assigned to
CREATE POLICY "Drivers can view their trips"
ON trips FOR SELECT
TO authenticated
USING (
  driver_id = auth.uid()
);
```

---

## Summary

| Table | Purpose | Key Relationships |
|-------|---------|-------------------|
| logistics_vendors | Vendor registry | → trips, fleet_vehicles |
| vendor_invites | Email invitations | → trips |
| trips | Trip scheduling | → vendors, vehicles, journeys |
| trip_passengers | Passenger manifest | → trips |
| trip_materials | Material cargo | → trips, materials |
| journeys | Execution tracking | → trips |
| journey_checkpoints | Route progress | → journeys |
| journey_incidents | Issue tracking | → journeys |
| materials | Material inventory | → material_movements |
| material_movements | Movement history | → materials, trips |
| fleet_vehicles | Vehicle registry | → vendors, trips |
| vehicle_documents | Compliance docs | → fleet_vehicles |
| maintenance_records | Service history | → fleet_vehicles |
| logistics_reports | Operational logs | → trips |
| report_attachments | Report files | → logistics_reports |
| logistics_notifications | Event alerts | → users |

---

## Implementation Notes

1. **Order of Creation**: Create enum types first, then tables in dependency order (vendors → vehicles → trips → journeys → materials)

2. **Seeder Data**: Populate default vehicle types, document types, and sample data for testing

3. **Backend Triggers**: Implement notification triggers for:
   - Vendor assignment to trips
   - Journey status changes
   - Document expiry warnings (30, 14, 7 days)
   - Overdue report detection

4. **API Layer**: Each table maps to CRUD endpoints as defined in LOGISTICS_MODULE_SPEC.md
