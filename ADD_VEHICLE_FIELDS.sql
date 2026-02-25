-- Step 1: Add missing columns to logistics_vehicles table
ALTER TABLE logistics_vehicles 
ADD COLUMN IF NOT EXISTS name VARCHAR(255),
ADD COLUMN IF NOT EXISTS make_model VARCHAR(255),
ADD COLUMN IF NOT EXISTS year INTEGER,
ADD COLUMN IF NOT EXISTS color VARCHAR(50),
ADD COLUMN IF NOT EXISTS fuel_type VARCHAR(50);

-- Step 2: Populate make_model from type for existing records
UPDATE logistics_vehicles 
SET make_model = type 
WHERE make_model IS NULL AND type IS NOT NULL;

-- Step 3: Populate fields from metadata JSON if they exist there
UPDATE logistics_vehicles 
SET 
    year = CASE 
        WHEN year IS NULL AND metadata->>'year' IS NOT NULL 
        THEN (metadata->>'year')::integer 
        ELSE year 
    END,
    fuel_type = CASE 
        WHEN fuel_type IS NULL AND metadata->>'fuel_type' IS NOT NULL 
        THEN metadata->>'fuel_type' 
        ELSE fuel_type 
    END,
    make_model = CASE 
        WHEN make_model IS NULL AND metadata->>'model' IS NOT NULL 
        THEN metadata->>'model' 
        ELSE make_model 
    END
WHERE metadata IS NOT NULL;

-- Step 4: Set default values for name if empty
UPDATE logistics_vehicles 
SET name = CONCAT(COALESCE(make_model, type, 'Vehicle'), ' ', COALESCE(plate_number, vehicle_code))
WHERE name IS NULL;

-- Step 5: Verify the changes
SELECT 
    id,
    vehicle_code,
    name,
    plate_number,
    type,
    make_model,
    year,
    color,
    fuel_type,
    capacity,
    status
FROM logistics_vehicles 
ORDER BY id DESC 
LIMIT 10;
