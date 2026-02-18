# Trip Fields & Cancellation Implementation Summary

## Overview
This document outlines the complete implementation of missing trip fields and the trip cancellation endpoint to address the issue where trip information wasn't being fully captured or displayed.

## Problems Addressed

### 1. Missing Fields in Trip Records
- **Departure Date**: The `scheduled_departure_at` field existed but wasn't being properly handled
- **Estimated Arrival**: The `scheduled_arrival_at` field existed but wasn't being properly handled
- **Trip Type**: Personnel vs Material trips weren't being distinguished
- **Priority**: Trip priority levels weren't captured
- **Purpose**: Purpose was being merged into title instead of stored separately
- **Cancellation Information**: No tracking of when/why trips were cancelled

### 2. Missing API Endpoint
- No route existed at `POST /api/trips/{id}/cancel` to cancel trips

## Changes Made

### 1. Database Migration
**File**: `database/migrations/2026_02_18_add_missing_trip_fields.php`

New fields added to `logistics_trips` table:
- `trip_type` (VARCHAR, default 'personnel'): Distinguishes between 'personnel', 'material', or 'mixed' trips
- `priority` (VARCHAR, default 'normal'): Trip priority level - 'low', 'normal', 'high', or 'urgent'
- `purpose` (TEXT, nullable): Explicit purpose field (separate from title/description)
- `cancelled_at` (TIMESTAMP, nullable): When the trip was cancelled
- `cancelled_by` (BIGINT, nullable): User ID of who cancelled the trip (foreign key to users table)

```sql
ALTER TABLE logistics_trips ADD COLUMN trip_type VARCHAR(50) DEFAULT 'personnel';
ALTER TABLE logistics_trips ADD COLUMN priority VARCHAR(50) DEFAULT 'normal';
ALTER TABLE logistics_trips ADD COLUMN purpose TEXT NULL;
ALTER TABLE logistics_trips ADD COLUMN cancelled_at TIMESTAMP NULL;
ALTER TABLE logistics_trips ADD COLUMN cancelled_by BIGINT UNSIGNED NULL;
```

### 2. Trip Model Updates
**File**: `app/Models/Logistics/Trip.php`

#### New Status Constant
```php
public const STATUS_CANCELLED = 'cancelled';
```

#### New Type Constants
```php
public const TYPE_PERSONNEL = 'personnel';
public const TYPE_MATERIAL = 'material';
public const TYPE_MIXED = 'mixed';
```

#### New Priority Constants
```php
public const PRIORITY_LOW = 'low';
public const PRIORITY_NORMAL = 'normal';
public const PRIORITY_HIGH = 'high';
public const PRIORITY_URGENT = 'urgent';
```

#### Updated Fillable Array
Added to `$fillable`:
- `purpose`
- `trip_type`
- `priority`
- `cancelled_by`
- `cancelled_at`

#### Updated Casts
Added datetime casting for `cancelled_at`:
```php
protected $casts = [
    // ... existing casts
    'cancelled_at' => 'datetime',
];
```

### 3. Request Validation Updates

#### StoreTripRequest.php
**Changes**:
- **Fixed purpose handling**: Purpose is now stored as a separate field, NOT merged into title
- **Added validation rules**:
  - `trip_type`: Optional, must be 'personnel', 'material', or 'mixed'
  - `priority`: Optional, must be 'low', 'normal', 'high', or 'urgent'
  - `purpose`: Optional string field
- **Added 'cancelled' status**: Now valid in status validation
- **Improved title generation**: Generates title from origin/destination when title not provided, preserves purpose as separate field

#### UpdateTripRequest.php
**Changes**:
- Added validation for `trip_type`, `priority`, and `purpose`
- Added 'cancelled' to valid status values

### 4. TripController New Method
**File**: `app/Http/Controllers/Api/V1/Logistics/TripController.php`

#### New cancel() Method
```php
public function cancel(int $id, Request $request)
{
    $trip = Trip::find($id);

    if (!$trip) {
        return $this->error('Trip not found', 'NOT_FOUND', 404);
    }

    // Cannot cancel completed, closed, or already cancelled trips
    if (in_array($trip->status, [Trip::STATUS_COMPLETED, Trip::STATUS_CLOSED, Trip::STATUS_CANCELLED])) {
        return $this->error(
            'Cannot cancel a trip with status: ' . $trip->status,
            'INVALID_STATUS',
            422
        );
    }

    // Update trip to cancelled status and record cancellation metadata
    $trip->status = Trip::STATUS_CANCELLED;
    $trip->cancelled_at = now();
    $trip->cancelled_by = $request->user()?->id;
    $trip->save();

    // Log the cancellation
    $this->auditLogger->log(
        'trip_cancelled',
        $request->user(),
        'trip',
        (string) $trip->id,
        'Trip cancelled',
        [
            'previous_status' => $trip->getOriginal('status'),
            'new_status' => $trip->status,
            'cancelled_by' => $trip->cancelled_by,
            'cancelled_at' => $trip->cancelled_at,
        ],
        $request
    );

    return $this->success([
        'trip' => $trip,
        'message' => 'Trip cancelled successfully',
    ]);
}
```

**Behavior**:
- Validates trip exists (404 if not found)
- Prevents cancelling trips that are already completed, closed, or cancelled (422 status)
- Updates trip status, sets cancellation timestamp, and records who cancelled
- Logs cancellation event for audit trail
- Returns updated trip data

### 5. Route Configuration
**File**: `routes/api.php`

Added cancellation endpoint to all three trip route groups:

```php
Route::post('/trips/{id}/cancel', [LogisticsTripController::class, 'cancel'])->middleware($logisticsInternalRoles);
```

**Route added in three locations**:
1. Line ~78: Simple `/api/v1/logistics/trips/{id}/cancel` routes
2. Line ~306: Versioned v1/logistics routes
3. Line ~388: Group-defined trip routes

## API Usage

### Creating a Trip with All Fields

```bash
POST /api/trips
Content-Type: application/json
Authorization: Bearer YOUR_TOKEN

{
    "title": "Board Meeting Transport",
    "purpose": "Executive transport for quarterly board meeting",
    "description": "Members transport from Lagos office to Abuja conference venue",
    "trip_type": "personnel",
    "priority": "high",
    "origin": "Lagos",
    "destination": "Abuja",
    "scheduled_departure_at": "2026-02-20 08:00:00",
    "scheduled_arrival_at": "2026-02-20 14:00:00",
    "notes": "Ensure air-conditioned vehicles with refreshments"
}
```

**Response (201 Created)**:
```json
{
    "trip": {
        "id": 1,
        "trip_code": "TRIP-20260218-ABC123",
        "title": "Board Meeting Transport",
        "purpose": "Executive transport for quarterly board meeting",
        "description": "Members transport from Lagos office to Abuja conference venue",
        "trip_type": "personnel",
        "priority": "high",
        "status": "draft",
        "origin": "Lagos",
        "destination": "Abuja",
        "scheduled_departure_at": "2026-02-20T08:00:00Z",
        "scheduled_arrival_at": "2026-02-20T14:00:00Z",
        "vendor_id": null,
        "created_by": 5,
        "updated_by": null,
        "cancelled_at": null,
        "cancelled_by": null,
        "notes": "Ensure air-conditioned vehicles with refreshments",
        "metadata": null,
        "created_at": "2026-02-18T10:30:00Z",
        "updated_at": "2026-02-18T10:30:00Z"
    }
}
```

### Retrieving a Trip with All Fields

```bash
GET /api/trips/1
Authorization: Bearer YOUR_TOKEN
```

**Response (200 OK)**: Same structure as create response, showing all captured fields including trip_type, priority, and purpose.

### Cancelling a Trip

```bash
POST /api/trips/{id}/cancel
Authorization: Bearer YOUR_TOKEN
Content-Type: application/json
```

**Success Response (200 OK)**:
```json
{
    "trip": {
        "id": 1,
        "status": "cancelled",
        "cancelled_at": "2026-02-18T10:35:00Z",
        "cancelled_by": 5,
        "...": "other trip fields"
    },
    "message": "Trip cancelled successfully"
}
```

**Error Response (404 Not Found)**:
```json
{
    "error": "Trip not found",
    "code": "NOT_FOUND"
}
```

**Error Response (422 Unprocessable Entity)**:
```json
{
    "error": "Cannot cancel a trip with status: completed",
    "code": "INVALID_STATUS"
}
```

## Deployment Steps

1. **Deploy Code Files**: Push all modified PHP files to production
2. **Run Migration**: Execute `php artisan migrate` in production environment
   ```bash
   docker-compose exec app php artisan migrate
   ```
3. **Clear Cache** (if applicable):
   ```bash
   docker-compose exec app php artisan cache:clear
   docker-compose exec app php artisan config:cache
   ```
4. **Test Endpoints**: Use the curl examples above to verify functionality

## Backward Compatibility

- Existing trips continue to work; all new fields default to NULL or sensible defaults
- `trip_type` defaults to 'personnel'
- `priority` defaults to 'normal'
- Partial updates still work; new fields are optional

## Testing Checklist

- [ ] Migration runs without errors
- [ ] New trip can be created with all fields populated
- [ ] Trip retrieved shows all captured fields including purpose, trip_type, and priority
- [ ] Trip can be cancelled via `POST /api/trips/{id}/cancel`
- [ ] Cancellation prevents re-cancellation (422 error)
- [ ] Audit logs show trip_cancelled event with cancellation details
- [ ] Pre-existing trips still load correctly
- [ ] Title generation still works when explicit title not provided
- [ ] Sorting/filtering by trip_type and priority works if needed

## Files Modified

1. `database/migrations/2026_02_18_add_missing_trip_fields.php` - NEW
2. `app/Models/Logistics/Trip.php` - Updated
3. `app/Http/Requests/Logistics/StoreTripRequest.php` - Updated
4. `app/Http\Requests\Logistics\UpdateTripRequest.php` - Updated
5. `app/Http/Controllers/Api/V1/Logistics/TripController.php` - Updated
6. `routes/api.php` - Updated

## Notes

- The cancellation feature includes audit logging for compliance tracking
- Cancelled trips cannot be re-cancelled or completed
- The cancelled_by field tracks which user cancelled the trip
- The cancelled_at timestamp records exactly when the cancellation occurred
- All trip data is preserved even after cancellation for audit purposes
