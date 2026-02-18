# Trip Fields Enhancement - Deployment Checklist

## Pre-Deployment Verification

### Code Review
- [ ] All files have been committed to version control
- [ ] No sensitive data or credentials in the code
- [ ] Code follows Laravel conventions and project standards
- [ ] Migration file is properly named with timestamp
- [ ] No syntax errors in PHP files

### Files Changed Summary
```
Modified Files:
├── app/Http/Controllers/Api/V1/Logistics/TripController.php
│   └── Added cancel() method
├── app/Http/Requests/Logistics/StoreTripRequest.php
│   └── Updated validation for new fields
├── app/Http/Requests/Logistics/UpdateTripRequest.php
│   └── Updated validation for new fields
├── app/Models/Logistics/Trip.php
│   └── Added new constants and fields
├── routes/api.php
│   └── Added cancel route (3 locations)
└── database/migrations/2026_02_18_add_missing_trip_fields.php
    └── NEW: Migration file for database changes
```

## Deployment Steps

### Step 1: Code Deployment
```bash
# On your deployment/production server
cd /path/to/supply-chain-backend

# Pull latest code
git pull origin main  # or your deployment branch

# Or if uploading directly
# Upload all files via FTP/SCP
```

### Step 2: Install Dependencies (if needed)
```bash
# If composer dependencies need updating
composer install --no-dev --optimize-autoloader

# OR in Docker
docker-compose exec app composer install --no-dev --optimize-autoloader
```

### Step 3: Run Database Migration
```bash
# For local/traditional setup:
php artisan migrate

# For Docker setup:
docker-compose exec app php artisan migrate

# Or if using Laravel Sail:
sail artisan migrate
```

**Expected Output:**
```
Migration table created successfully.
Migrating: 2026_02_18_add_missing_trip_fields
Migrated:  2026_02_18_add_missing_trip_fields (100ms)
```

### Step 4: Clear Cache
```bash
# Clear all application caches
php artisan cache:clear

# Rebuild configuration cache
php artisan config:cache

# Rebuild route cache
php artisan route:cache

# For Docker:
docker-compose exec app php artisan cache:clear && docker-compose exec app php artisan config:cache
```

### Step 5: Verify Database Changes
```bash
# Connect to your database and verify the new columns
# PostgreSQL:
psql -U user -d database_name -c "\d logistics_trips"

# MySQL:
mysql -u user -p database_name -e "DESCRIBE logistics_trips;"

# Or run a test query:
SELECT trip_type, priority, purpose, cancelled_at, cancelled_by 
FROM logistics_trips LIMIT 1;
```

**Expected columns to see:**
- `trip_type` (VARCHAR, default 'personnel')
- `priority` (VARCHAR, default 'normal')
- `purpose` (TEXT)
- `cancelled_at` (TIMESTAMP, nullable)
- `cancelled_by` (BIGINT, nullable)

### Step 6: Run Tests
```bash
# If you have automated tests
php artisan test --filter=Trip

# For Docker:
docker-compose exec app php artisan test --filter=Trip
```

### Step 7: Smoke Tests
```bash
# Test API functionality with the verification scripts
# Linux/Mac:
bash verify_trip_implementation.sh "http://your-api.com/api" "YOUR_TOKEN"

# Windows PowerShell:
.\verify_trip_implementation.ps1 -ApiUrl "http://your-api.com/api" -AuthToken "YOUR_TOKEN"
```

### Step 8: Manual Testing
1. **Create a Trip with New Fields**
   ```bash
   curl -X POST "http://your-api.com/api/trips" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "title": "Deployment Test Trip",
       "purpose": "Testing new deployment",
       "trip_type": "personnel",
       "priority": "high",
       "origin": "Test City A",
       "destination": "Test City B"
     }'
   ```

2. **Retrieve Trip and Verify All Fields**
   ```bash
   curl "http://your-api.com/api/trips/1" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```
   Verify response includes: `trip_type`, `priority`, `purpose`, `scheduled_departure_at`, `scheduled_arrival_at`

3. **Test Cancellation Endpoint**
   ```bash
   curl -X POST "http://your-api.com/api/trips/1/cancel" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```
   Expected: 200 response with status="cancelled"

4. **Test Re-cancellation Prevention**
   ```bash
   curl -X POST "http://your-api.com/api/trips/1/cancel" \
     -H "Authorization: Bearer YOUR_TOKEN"
   ```
   Expected: 422 response with error code "INVALID_STATUS"

## Post-Deployment Verification

### Check Application Logs
```bash
# Monitor application logs for errors
tail -f storage/logs/laravel.log

# For Docker:
docker-compose logs -f app
```

### Database Verification
```bash
# Count trips with the new fields populated
SELECT COUNT(*) as trips_with_type FROM logistics_trips WHERE trip_type IS NOT NULL;
SELECT COUNT(*) as trips_with_priority FROM logistics_trips WHERE priority IS NOT NULL;
SELECT COUNT(*) as cancelled_trips FROM logistics_trips WHERE status = 'cancelled';
```

### API Monitoring
- [ ] No 500 errors in trip endpoints
- [ ] Trip creation/retrieval working normally
- [ ] New fields appearing in API responses
- [ ] Cancel endpoint responding with correct status codes

## Rollback Plan (if needed)

If issues occur, you can rollback the migration:

```bash
# Rollback the last migration
php artisan migrate:rollback

# For Docker:
docker-compose exec app php artisan migrate:rollback

# Revert code changes to previous version
git revert HEAD  # or git reset to a previous commit
```

### What Gets Deleted in Rollback
- `trip_type` column
- `priority` column
- `purpose` column
- `cancelled_at` column
- `cancelled_by` column and its foreign key

**Note**: Any data stored in these columns will be lost, but existing trip records will remain intact.

## Monitoring After Deployment

### Key Metrics to Track
1. **Trips Created Per Day**: Should remain consistent with pre-deployment
2. **Cancellation Rate**: Monitor new cancel endpoint usage
3. **API Response Times**: Should not be impacted
4. **Error Rates**: Should remain low

### Performance Considerations
- The new columns don't significantly impact performance
- Add indices if querying by trip_type or priority frequently:
  ```sql
  CREATE INDEX idx_trips_type ON logistics_trips(trip_type);
  CREATE INDEX idx_trips_priority ON logistics_trips(priority);
  ```

## Communication

- [ ] Notify API users about new available fields
- [ ] Update API documentation with new fields and cancel endpoint
- [ ] Inform support team about cancellation feature
- [ ] Update frontend to use new fields if applicable

## Sign-Off

- [ ] Deployment completed successfully
- [ ] All verification tests passed
- [ ] No critical errors in logs
- [ ] Database migration verified
- [ ] API endpoints working as expected

**Deployed By**: ________________  
**Date & Time**: ________________  
**Verified By**: ________________
