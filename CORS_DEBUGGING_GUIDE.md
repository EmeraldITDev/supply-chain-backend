# CORS Configuration Debugging & Verification Guide

## Quick Status Check

Run this command to verify CORS is working:

```bash
curl -i -X OPTIONS https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization"
```

**Expected Response (HTTP 204):**
```
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD
Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token
Access-Control-Allow-Credentials: true
Access-Control-Max-Age: 86400
Vary: Origin
```

## What Was Fixed

### 1. Middleware Execution Order (CRITICAL)
**Problem:** TrackUserActivity middleware was executing before CORS middleware, potentially interfering with CORS headers.

**Fix in bootstrap/app.php:**
```php
// CRITICAL: Apply CORS to all requests - MUST be absolutely first
$middleware->prepend(\App\Http\Middleware\EnsureCorsHeaders::class);
$middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

// This comes AFTER CORS, not prepended
$middleware->use(\App\Http\Middleware\TrackUserActivity::class);
```

**Result:** CORS middleware now executes FIRST, before all other middleware.

### 2. CORS Headers on Error Responses (CRITICAL)
**Problem:** Error responses (400, 500, etc.) were missing CORS headers, causing browser to reject them.

**Fix in bootstrap/app.php:**
Added CORS header injection to exception handler. Every error response now includes:
```
Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD
Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token
```

**Result:** Browser can now read error responses without CORS blocking.

### 3. Preflight Request Handling (CRITICAL)
**Problem:** OPTIONS requests might not be handled properly, blocking actual requests.

**Fix in EnsureCorsHeaders middleware:**
```php
// Handle preflight (OPTIONS) requests early
if ($request->getMethod() === 'OPTIONS') {
    $response = response('', 204); // Return 204 No Content
    if ($isAllowed && $origin) {
        // Add CORS headers to preflight response
        $response->headers->set('Access-Control-Allow-Origin', $origin, true);
        // ... other headers
    }
    return $response;
}
```

**Result:** Browser preflight requests get proper 204 response with CORS headers.

### 4. Origin Validation (CRITICAL)
**Problem:** Origin might not be properly validated against allowed list.

**Fix in both middleware and exception handler:**
- Check direct allowed origins: `in_array($origin, $allowedOrigins, true)`
- Check pattern-based origins: `preg_match($pattern, $origin)`

**Allowed Origins in config/cors.php:**
```php
'allowed_origins' => [
    'http://localhost:8081',
    'http://localhost:8080',
    'http://localhost:3000',
    'http://localhost:5173',
    'https://emerald-supply-chain.vercel.app',  // PRODUCTION FRONTEND
    'https://scm.emeraldcfze.com',
]

'allowed_origins_patterns' => [
    '#^https://.*\.lovable\.app$#',     // Lovable previews
    '#^https://.*\.vercel\.app$#',      // All Vercel deployments
    '#^https://.*\.emeraldcfze\.com$#', // All subdomains
]
```

## Browser DevTools Debugging

### 1. Check Preflight Request
Open browser DevTools → Network tab
1. Filter by type: XHR/Fetch
2. Find the request that fails
3. Look for an OPTIONS request (preflight)
4. Click on it and check Response Headers:
   - ✅ Should see `Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app`
   - ✅ Should see `Access-Control-Allow-Methods: ...`
   - ❌ If missing, preflight failed

### 2. Check Actual Request
After preflight passes:
1. Look for POST/GET/PUT/PATCH/DELETE request
2. Click on it and check Response Headers:
   - ✅ Should see `Access-Control-Allow-Origin` header
   - ✅ HTTP status should be 200, 201, 400, 422, etc. (NOT blocked)
   - ❌ If missing header, that's the problem

### 3. Check Console Errors
Open DevTools → Console tab:
- ❌ `Access to XMLHttpRequest at 'https://...' from origin 'https://...' has been blocked by CORS policy`
  - Means CORS headers missing or origin not allowed
- ❌ `The value of the 'Access-Control-Allow-Credentials' header... must be 'true'`
  - Means credentials are being sent but header is false
- ✅ No errors = CORS working

## Server-Side Debugging

### 1. Check CORS Config is Loaded
```bash
php artisan config:show cors
```

Should show:
```php
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
'allowed_headers' => ['Accept', 'Accept-Language', 'Content-Language', 'Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
'allowed_origins' => ['https://emerald-supply-chain.vercel.app', ...],
```

### 2. Check Middleware Order
```bash
php artisan route:list | grep -E "(OPTIONS|cors)" | head -20
```

Or add to any route to debug:
```php
Route::get('/debug-middleware', function () {
    $route = request()->route();
    $middleware = $route->middleware();
    return response()->json(['middleware' => $middleware]);
});
```

### 3. Monitor Logs for CORS Errors
```bash
tail -f storage/logs/laravel.log | grep -i "cors\|origin\|access-control"
```

### 4. Enable Debug Logging in Controller
Add to vendor registration controller:
```php
\Log::info('CORS Debug', [
    'origin' => request()->header('Origin'),
    'method' => request()->method(),
    'path' => request()->path(),
    'cors_config' => config('cors.allowed_origins'),
]);
```

## Testing Scenarios

### Scenario 1: Valid Request from Allowed Origin
```bash
# Should return 200/201 with CORS headers
curl -i https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Content-Type: application/json" \
  -d '{"companyName":"Test","email":"test@example.com","category":"SUPPLIER"}'
```

### Scenario 2: Preflight from Allowed Origin  
```bash
# Should return 204 with CORS headers
curl -i -X OPTIONS https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Access-Control-Request-Method: POST"
```

### Scenario 3: Request from Blocked Origin
```bash
# Should return 200 BUT without CORS headers (origin not allowed)
curl -i https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://blocked-site.com" \
  -H "Content-Type: application/json" \
  -d '{"companyName":"Test","email":"test@example.com","category":"SUPPLIER"}'
```

**Response should have:**
- ✅ HTTP 200 (or appropriate status)
- ❌ NO `Access-Control-Allow-Origin` header (origin not allowed)

### Scenario 4: Error Response with CORS
```bash
# Should return 422 with CORS headers
curl -i https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Content-Type: application/json" \
  -d '{}' # Invalid - missing required fields
```

**Response should have:**
- ✅ HTTP 422 (validation error)
- ✅ `Access-Control-Allow-Origin: https://emerald-supply-chain.vercel.app` header
- ✅ Error message in JSON body

## Common Issues & Fixes

### Issue: "net::ERR_FAILED" in Browser Console
**Cause:** CORS headers missing on response

**Debug:**
1. Run preflight test above - check if `Access-Control-Allow-Origin` is present
2. Check `php artisan config:show cors` - verify origin is in allowed list
3. Check middleware order - CORS must be first

**Fix:**
1. Clear config cache: `php artisan config:cache`
2. Restart PHP-FPM: `sudo systemctl restart php-fpm`
3. Clear browser cache: Ctrl+Shift+Delete

### Issue: "CORS policy: No 'Access-Control-Allow-Origin' header"
**Cause:** Server not sending CORS headers

**Debug:**
1. Check if origin is in allowed list
2. Verify `supports_credentials` is `true` in config/cors.php
3. Check if request has `Origin` header

**Fix:**
```bash
# Clear and restart
php artisan config:clear
php artisan cache:clear
sudo systemctl restart php-fpm
```

### Issue: "Method not allowed" even though method is listed
**Cause:** Preflight returned wrong methods or OPTIONS request failed

**Debug:**
```bash
curl -i -X OPTIONS https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Access-Control-Request-Method: POST" \
  -v
```

Check `Access-Control-Allow-Methods` header contains `POST`

### Issue: Cookies/Auth Not Sent
**Cause:** `supports_credentials` is false or missing from request

**Fix in config/cors.php:**
```php
'supports_credentials' => true,  // Must be true for cookies/tokens
```

**Fix in frontend (JavaScript):**
```javascript
fetch(url, {
    method: 'POST',
    credentials: 'include',  // Send cookies/auth
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`  // Include token
    }
})
```

## Files Modified (Summary)

| File | Changes |
|------|---------|
| bootstrap/app.php | Fixed middleware order, added CORS to exception handler |
| config/cors.php | Enhanced exposed_headers |
| app/Http/Middleware/EnsureCorsHeaders.php | Improved preflight & validation handling |
| routes/api.php | Health check endpoint |

## Verification Checklist

Before considering CORS fixed, verify:

- [ ] `curl -i -X OPTIONS ... -H "Origin: https://emerald-supply-chain.vercel.app"` returns 204 with CORS headers
- [ ] `php artisan config:show cors` shows production frontend URL
- [ ] Browser DevTools shows no CORS errors in Console
- [ ] Preflight OPTIONS request appears in Network tab
- [ ] Actual POST request appears after preflight
- [ ] POST response includes `Access-Control-Allow-Origin` header
- [ ] POST response status is 200/201 (not blocked)
- [ ] Error responses (422, 500) also include CORS headers
- [ ] Can register vendor from emerald-supply-chain.vercel.app without errors

## Production Deployment

After making changes:

```bash
# 1. Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan config:cache

# 2. Restart PHP
sudo systemctl restart php-fpm
# OR
docker-compose restart app
# OR
supervisorctl restart all

# 3. Verify
curl -i -X OPTIONS https://supply-chain-backend-hwh6.onrender.com/api/vendors/register \
  -H "Origin: https://emerald-supply-chain.vercel.app" \
  -H "Access-Control-Request-Method: POST"

# Should see: HTTP/1.1 204 No Content
# And headers with Access-Control-Allow-Origin
```

## Support

If CORS is still not working after following this guide:

1. Collect evidence:
   - Browser DevTools Network tab screenshot
   - Console error message (full text)
   - curl response headers from commands above
   - Server logs: `tail -n 100 storage/logs/laravel.log`

2. Check:
   - Is `https://emerald-supply-chain.vercel.app` exactly in allowed origins?
   - Is middleware order correct? (CORS first)
   - Did you run `php artisan config:cache`?
   - Did you restart PHP-FPM?
   - Are you accessing from exactly `https://emerald-supply-chain.vercel.app` (not `http://` or different domain)?
