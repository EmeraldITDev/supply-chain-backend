# Fix: Logistics Trips Table Missing

## Problem
The error `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "logistics_trips" does not exist` indicates that the logistics migrations have not been run on the production database.

## Solution

### Option 1: Redeploy on Render (Recommended)
The build command in `render.yaml` includes `php artisan migrate --force`, so a fresh deployment will run all pending migrations.

**Steps:**
1. Go to your Render dashboard: https://dashboard.render.com
2. Find the `supply-chain-backend` service
3. Click **Manual Deploy** → **Deploy latest commit**
4. Wait for the build to complete (migrations will run automatically)
5. Check the build logs to confirm migrations ran successfully

### Option 2: Run Migrations via Render Shell
If you need to run migrations without redeploying:

**Steps:**
1. Go to Render dashboard → `supply-chain-backend` service
2. Click **Shell** tab (top right)
3. Run the migration command:
   ```bash
   php artisan migrate --force
   ```
4. Verify the output shows migrations ran successfully

### Option 3: Connect to Database Directly (Advanced)
If you have database access:

**Steps:**
1. Get your database credentials from Render:
   - Dashboard → PostgreSQL database → Connection Details
2. Connect using psql or a database client
3. Verify the migrations table:
   ```sql
   SELECT * FROM migrations ORDER BY batch DESC LIMIT 10;
   ```
4. Check if logistics tables exist:
   ```sql
   SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'logistics%';
   ```

## Expected Logistics Tables
After migrations run successfully, these tables should exist:
- `logistics_vendor_invites`
- `logistics_trips` ← **This is missing**
- `logistics_journeys`
- `logistics_materials`
- `logistics_material_condition_histories`
- `logistics_vehicles`
- `logistics_vehicle_maintenances`
- `logistics_documents`
- `logistics_reports`
- `logistics_idempotency_keys`
- `logistics_notification_events`
- `logistics_vendor_compliances`

## Verification
After running migrations, test the trip creation:

```bash
curl -X POST https://supply-chain-backend-hwh6.onrender.com/api/v1/logistics/trips \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "purpose": "Board Meeting",
    "origin": "Lagos",
    "destination": "Abuja",
    "scheduled_departure_at": "2026-02-18 10:00:00",
    "scheduled_arrival_at": "2026-02-18 16:00:00"
  }'
```

Expected: `201 Created` with trip data

## Migration Files Location
All logistics migrations are in:
```
database/migrations/2026_02_03_000001_create_logistics_vendor_invites_table.php
database/migrations/2026_02_03_000002_create_logistics_trips_table.php
database/migrations/2026_02_03_000003_create_logistics_journeys_table.php
... (12 files total)
```

## Notes
- The migrations are ordered by timestamp (2026_02_03_000001 to 000012)
- They must run in order due to foreign key dependencies
- The `render.yaml` build command should handle this automatically
- If migrations have been partially run, use `php artisan migrate:status` to check which are pending
