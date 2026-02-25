-- Add missing fields to logistics_vehicles table
-- Run this if you cannot run Laravel migrations

ALTER TABLE logistics_vehicles 
ADD COLUMN IF NOT EXISTS name VARCHAR(255),
ADD COLUMN IF NOT EXISTS make_model VARCHAR(255),
ADD COLUMN IF NOT EXISTS year INTEGER,
ADD COLUMN IF NOT EXISTS color VARCHAR(50),
ADD COLUMN IF NOT EXISTS fuel_type VARCHAR(50);

-- Verify the columns were added
SELECT column_name, data_type, character_maximum_length 
FROM information_schema.columns 
WHERE table_name = 'logistics_vehicles' 
ORDER BY ordinal_position;
