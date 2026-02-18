-- SQL Script to Add Missing Trip Fields to logistics_trips Table
-- Run this on your PostgreSQL production database if you cannot run Laravel migrations

-- Add trip_type column
ALTER TABLE logistics_trips 
ADD COLUMN IF NOT EXISTS trip_type VARCHAR(50) DEFAULT 'personnel';

-- Add priority column  
ALTER TABLE logistics_trips 
ADD COLUMN IF NOT EXISTS priority VARCHAR(50) DEFAULT 'normal';

-- Add purpose column
ALTER TABLE logistics_trips 
ADD COLUMN IF NOT EXISTS purpose TEXT;

-- Add cancelled_at column
ALTER TABLE logistics_trips 
ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP;

-- Add cancelled_by column
ALTER TABLE logistics_trips 
ADD COLUMN IF NOT EXISTS cancelled_by BIGINT;

-- Add foreign key constraint for cancelled_by
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'logistics_trips_cancelled_by_foreign'
    ) THEN
        ALTER TABLE logistics_trips
        ADD CONSTRAINT logistics_trips_cancelled_by_foreign 
        FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;
    END IF;
END $$;

-- Verify the columns were added
SELECT column_name, data_type, column_default 
FROM information_schema.columns 
WHERE table_name = 'logistics_trips' 
AND column_name IN ('trip_type', 'priority', 'purpose', 'cancelled_at', 'cancelled_by')
ORDER BY column_name;

-- Show sample of the table structure
\d logistics_trips;
