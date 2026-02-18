-- Diagnostic Query for Trip Details Issue
-- Run this to check what's wrong with your trips data

-- 1. Check if new columns exist
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_name = 'logistics_trips'
ORDER BY ordinal_position;

-- 2. Check current trip data
SELECT 
    id,
    trip_code,
    title,
    trip_type,
    priority,
    purpose,
    scheduled_departure_at,
    scheduled_arrival_at,
    origin,
    destination,
    status,
    created_at
FROM logistics_trips
ORDER BY id DESC
LIMIT 5;

-- 3. Count trips with missing data
SELECT 
    COUNT(*) as total_trips,
    COUNT(trip_type) as has_trip_type,
    COUNT(priority) as has_priority,
    COUNT(purpose) as has_purpose,
    COUNT(scheduled_departure_at) as has_departure,
    COUNT(scheduled_arrival_at) as has_arrival
FROM logistics_trips;

-- 4. Check for NULL dates (these would show as "Invalid Date")
SELECT 
    id,
    trip_code,
    scheduled_departure_at,
    scheduled_arrival_at,
    CASE 
        WHEN scheduled_departure_at IS NULL THEN 'MISSING DEPARTURE DATE ❌'
        ELSE '✓ Has departure'
    END as departure_status,
    CASE 
        WHEN scheduled_arrival_at IS NULL THEN 'MISSING ARRIVAL DATE ❌'
        ELSE '✓ Has arrival'
    END as arrival_status
FROM logistics_trips
WHERE scheduled_departure_at IS NULL 
   OR scheduled_arrival_at IS NULL
LIMIT 10;

-- 5. Show recent trip with all details
SELECT *
FROM logistics_trips
WHERE id = (SELECT MAX(id) FROM logistics_trips);
