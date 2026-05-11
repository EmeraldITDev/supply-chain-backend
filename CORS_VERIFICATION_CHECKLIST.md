# CORS Fix - Verification Checklist

## ✅ Pre-Deployment Checklist

- [ ] Read `CORS_FIX_DEPLOYMENT.md` for quick overview
- [ ] Confirmed all files have been updated:
  - [ ] `bootstrap/app.php` - Middleware order and exception handler
  - [ ] `config/cors.php` - Exposed headers
  - [ ] `app/Http/Middleware/EnsureCorsHeaders.php` - Improved handling
  - [ ] `routes/api.php` - Health check endpoint (from earlier fix)

## ✅ Deployment Checklist

Run these commands in order:

```bash
# Step 1: Pull code
git pull origin main
# Expected: Latest changes fetched

# Step 2: Clear config cache (CRITICAL)
php artisan config:cache
# Expected: [✓] Configuration cached successfully

php artisan config:clear
php artisan cache:clear
# Expected: Cache cleared

# Step 3: Restart PHP
sudo systemctl restart php-fpm
# Expected: Service restarted without errors

# Step 4: Verify config loaded
php artisan config:show cors | grep "allowed_origins\|allowed_methods"
# Expected: See https://emerald-supply-chain.vercel.app in list
```

- [ ] `php artisan config:cache` executed successfully
- [ ] PHP process restarted without errors
- [ ] No errors in `php artisan config:show cors` output

## ✅ Immediate Verification

Run these curl commands to verify CORS is working:

### Test 1: Preflight Request
```bash
curl -i -X OPTIONS https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization"
```

**Verify:**
- [ ] HTTP response code is `204 No Content`
- [ ] Response has `Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app` header
- [ ] Response has `Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD` header
- [ ] Response has `Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token` header
- [ ] Response has `Access-Control-Allow-Credentials: true` header
- [ ] Response has `Access-Control-Max-Age: 86400` header
- [ ] Response has `Vary: Origin` header

### Test 2: Health Check
```bash
curl -i https://supply-chain-backend-hwh6.onrender.com/api/health \
  -H "Origin: https://emerald-supply-chain.vercel.app"
```

**Verify:**
- [ ] HTTP response code is `200 OK`
- [ ] Response has `Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app` header
- [ ] Response body contains `"status": "healthy"` or database status info

### Test 3: Vendor Registration (Valid Request)
```bash
curl -i -X POST https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "Test Company",
    "email": "test'$(date +%s)'@example.com",
    "category": "SUPPLIER",
    "phone": "+1234567890",
    "address": "123 Business St",
    "contactPerson": "John Doe"
  }'
```

**Verify:**
- [ ] HTTP response code is `201 Created`
- [ ] Response has `Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app` header
- [ ] Response body contains `"success": true`
- [ ] Response body contains registration ID

### Test 4: Vendor Registration (Invalid Request - should have CORS headers on error)
```bash
curl -i -X POST https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**Verify:**
- [ ] HTTP response code is `422 Unprocessable Entity`
- [ ] Response has `Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app` header (CRITICAL - on error response)
- [ ] Response body contains validation error details

## ✅ Browser Testing Checklist

### Firefox DevTools

1. Open DevTools: Press `F12`
2. Go to **Network** tab
3. Navigate to https://emerald-supply-chain.vercel.app/vendor-registration
4. Fill in and submit vendor registration form
5. In Network tab, find the registration request:

**For the OPTIONS preflight request:**
- [ ] Method shows `OPTIONS`
- [ ] Status shows `204` (or `200`)
- [ ] Click on request → **Headers** tab
- [ ] Scroll to **Response Headers**:
  - [ ] See `access-control-allow-origin: https://emerald-supply-chain.vercel.app`
  - [ ] See `access-control-allow-methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD`
  - [ ] See `access-control-allow-headers: ...` with Content-Type and Authorization

**For the actual POST request:**
- [ ] Method shows `POST`
- [ ] Status shows `201` (success) or `422` (validation error)
- [ ] NOT blocked/failed
- [ ] Click on request → **Headers** tab
- [ ] Scroll to **Response Headers**:
  - [ ] See `access-control-allow-origin: https://emerald-supply-chain.vercel.app`

**In Console tab:**
- [ ] NO errors starting with "Access to XMLHttpRequest from origin"
- [ ] NO errors about CORS
- [ ] NO errors about "Access-Control-Allow-Origin"

### Chrome DevTools

Same as Firefox but:
1. Press `F12` or `Ctrl+Shift+I`
2. **Network** tab already shows all requests
3. Filter by: type `fetch` or `xhr`
4. Look for `register` request

**Console tab:**
- [ ] NO red error messages
- [ ] NO "CORS" related messages
- [ ] Successful response messages from API

## ✅ Functional Testing

### Can Register from Frontend
1. Go to https://emerald-supply-chain.vercel.app
2. Navigate to vendor registration form
3. Fill in all required fields
4. Click Submit
5. **Verify:**
   - [ ] Form submits without "net::ERR_FAILED" error
   - [ ] No CORS errors in browser console
   - [ ] Receives success response (200/201)
   - [ ] Sees confirmation message
   - [ ] Backend logs show registration created (check logs for request_id)

### Error Handling Works
1. Try registering with invalid email
2. **Verify:**
   - [ ] Returns 422 Unprocessable Entity
   - [ ] Returns clear validation error message
   - [ ] Has CORS headers (not blocked)
   - [ ] Frontend can read error and show to user

### Duplicate Email Handling
1. Register with email: `test123@example.com`
2. Try register again with same email
3. **Verify:**
   - [ ] Returns 200 (idempotent for pending)
   - [ ] Shows "registration already submitted" message
   - [ ] Has CORS headers

## ✅ Logging Verification

Check backend logs for proper request tracking:

```bash
tail -f storage/logs/laravel.log | grep -i "registration"
```

**Should see:**
- [ ] `Vendor registration attempt` with request_id
- [ ] `Validation passed` (for valid requests)
- [ ] `Registration created successfully` with registration_id
- [ ] `Vendor registration completed successfully` with timing info
- [ ] Request IDs consistent across all log entries for same request

## ✅ Production Status

### Code Deployed
- [ ] All files committed and pushed to main branch
- [ ] Deployment system (GitHub Actions, etc.) ran successfully
- [ ] No deployment errors in logs

### Services Healthy  
- [ ] `GET /api/health` returns 200 with `"database": { "status": "connected" }`
- [ ] Database connection working
- [ ] No database timeout errors in logs
- [ ] Server memory/CPU usage normal

### CORS Active
- [ ] Preflight requests handled with 204 response
- [ ] All responses include proper CORS headers
- [ ] Origin validation working (blocked origins not served)

## ✅ Performance Checklist

- [ ] Vendor registration completes in < 5 seconds
- [ ] Preflight request takes < 1 second
- [ ] No slow query warnings in logs
- [ ] No memory exhaustion errors
- [ ] No timeout errors

## 🔴 If Any Checks Fail

### For Preflight Issues (Test 1)
1. Run: `php artisan config:show cors`
2. Verify `https://emerald-supply-chain.vercel.app` is in `allowed_origins`
3. Verify `allowed_methods` includes `OPTIONS`
4. Clear cache: `php artisan config:clear && php artisan cache:clear`
5. Restart PHP: `sudo systemctl restart php-fpm`
6. Re-test preflight

### For Missing CORS Headers on Errors (Test 4)
1. Check `bootstrap/app.php` exception handler (lines 48-119)
2. Verify it includes CORS header injection
3. Look for the `$addCorsHeaders = function` in exception handler
4. If missing, file was not properly updated
5. Manually apply changes from `CORS_FIX_DEPLOYMENT.md`
6. Restart PHP-FPM

### For Browser CORS Errors
1. Open DevTools Console
2. Note exact error message
3. Check Firefox/Chrome shows OPTIONS preflight request in Network tab
4. If no OPTIONS: browser issue or wrong request method
5. If OPTIONS failed: check Status code (must be 204 or 200)
6. If status 200/204 but still blocked: check for `Access-Control-Allow-Origin` header

### For Registration Not Working
1. Check `curl` tests above all pass (Tests 1-4)
2. If curl tests pass but frontend fails: frontend code issue
3. If curl tests fail: CORS not working, see above fixes
4. Check logs: `tail -f storage/logs/laravel.log | grep -i "registration\|error"`

## 📞 Support Information

**When reporting issues, provide:**
1. Output of: `php artisan config:show cors`
2. Output of: `curl -i -X OPTIONS ...` (from Test 1)
3. Browser console error (full text)
4. Network tab screenshot showing preflight & actual request
5. Last 20 lines of `storage/logs/laravel.log`
6. Server OS and PHP version: `php --version`

**Documentation for reference:**
- Read: `CORS_DEBUGGING_GUIDE.md` for detailed troubleshooting
- Read: `CORS_FIX_DEPLOYMENT.md` for summary of changes
- Read: `VENDOR_REGISTRATION_FIX_SUMMARY.md` for full context

## ✅ Final Approval

Once all checks pass:

- [ ] Preflight requests working (Test 1)
- [ ] Health endpoint working (Test 2)  
- [ ] Valid registration working (Test 3)
- [ ] Error responses have CORS headers (Test 4)
- [ ] Browser DevTools shows no CORS errors
- [ ] Frontend can register vendors successfully
- [ ] Logs show proper request tracking
- [ ] Performance is acceptable

**Status:** ✅ CORS FIXED AND VERIFIED
