# CORS & 500 Error Fix Guide

## Problem Analysis

You're getting:
1. **CORS Error**: Missing `Access-Control-Allow-Origin` header
2. **500 Status Code**: The backend is returning an error

When a backend returns 500, CORS headers might not be properly sent, causing the CORS error to appear first.

---

## Root Causes

### Possible Issues:

1. **User role is not 'employee'** - Store method checks: `$user->role !== 'employee'`
2. **Migration not visible by runtime** - Cache needs clearing
3. **CORS headers being obscured by 500 error** - Need to fix the actual error first
4. **Frontend URL not in allowed origins** - Need to verify frontend URL

---

## Step-by-Step Fix

### STEP 1: Check Render Logs for Actual Error

```bash
# SSH into Render and check the error log
tail -f storage/logs/laravel.log
```

**Look for**: What's the actual error message? Is it:
- "Only staff members can create Material Request Forms"?
- "Database schema is not up to date"?
- "Unknown column 'contract_type'"?
- Something else?

### STEP 2: Verify User Role in Database

The store() method requires `$user->role === 'employee'`. Check your user:

```bash
# Via Render shell
php artisan tinker

# In tinker:
$user = User::find(1);  // Replace 1 with your user ID
dd($user->role);        // See what role is set
```

**Expected**: Should be `'employee'` (lowercase)

**Fix if wrong**:
```php
$user->update(['role' => 'employee']);
```

### STEP 3: Clear All Caches on Render

```bash
php artisan optimize:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

### STEP 4: Verify CORS Configuration

Update `.env` on Render to include your frontend URL:

```bash
# SSH to Render
nano .env

# Add or update:
CORS_ALLOWED_ORIGINS="https://your-frontend-domain.com,https://your-other-domain.com"

# Save: Ctrl+X, Y, Enter
```

### STEP 5: Verify Frontend URL

**Check what origin is making the request:**

In browser console, run:
```javascript
console.log(window.location.origin);
```

Make sure this domain is in `config/cors.php` `allowed_origins` array or `CORS_ALLOWED_ORIGINS` env var.

### STEP 6: Update CORS Config if Needed

**If your frontend domain is not listed**, add it to `config/cors.php`:

```php
'allowed_origins' => array_values(array_unique(array_filter(array_map('trim', array_merge(
    [
        'http://localhost:8081',
        'http://localhost:8080',
        'http://localhost:3000',
        'http://localhost:5173',
        'https://your-frontend-domain.com',  // <-- ADD YOUR FRONTEND DOMAIN HERE
        'https://emerald-supply-chain.vercel.app',
        'https://scm.emeraldcfze.com',
    ],
    env('CORS_ALLOWED_ORIGINS') ? explode(',', env('CORS_ALLOWED_ORIGINS')) : []
)))))
```

Then commit and push to Render (auto-deploys).

### STEP 7: Test the API with curl First

Test without frontend to isolate the issue:

```bash
# Via Render shell
curl -X POST https://supply-chain-backend-hwh6.onrender.com/api/mrfs \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test MRF",
    "category": "Supplies",
    "contractType": "emerald",
    "urgency": "Medium",
    "description": "Test",
    "quantity": "100",
    "estimatedCost": "5000",
    "justification": "Testing"
  }'
```

**Expected**: Either success (200) or a clear error message. If this works with curl, it's a CORS configuration issue.

---

## Quick Checklist

- [ ] Checked Render logs: `tail -f storage/logs/laravel.log`
- [ ] Verified user role is 'employee': `php artisan tinker` → Check role
- [ ] Cleared all caches: `php artisan optimize:clear`
- [ ] Verified migration ran: `php artisan migrate:status`
- [ ] Got actual error message from logs
- [ ] Tested with curl (bypasses CORS)
- [ ] Verified frontend domain in CORS config
- [ ] Updated `.env` if needed
- [ ] Deployed changes to Render

---

## Common Solutions

### Issue: "Only staff members can create Material Request Forms"

**Fix**: Your user needs role = 'employee'

```php
php artisan tinker
$user = User::find(1);
$user->update(['role' => 'employee']);
exit;
```

### Issue: "Database schema is not up to date"

**Fix**: Run migration and clear cache

```bash
php artisan migrate
php artisan optimize:clear
```

### Issue: Still getting CORS error after all above

**Nuclear option** - Temporarily disable CORS for testing (NOT for production):

In `config/cors.php`, change:
```php
'allowed_origins' => ['*'],  // TEMPORARY - DEVELOPMENT ONLY
```

Then:
```bash
php artisan config:cache
# Deploy to Render
```

Once it works, change back to proper domain-specific config.

---

## Debugging Script

Run this in your Render shell to diagnose:

```bash
php artisan tinker

# Check user
$user = User::first();
echo "User ID: " . $user->id . "\n";
echo "User Role: " . $user->role . "\n";
echo "Expected: 'employee'\n";

# Check migration
$hasColumn = Schema::hasColumn('m_r_f_s', 'contract_type');
echo "contract_type column exists: " . ($hasColumn ? "YES" : "NO") . "\n";

# Check CORS config
$cors = config('cors');
echo "CORS allowed origins:\n";
echo json_encode($cors['allowed_origins'], JSON_PRETTY_PRINT) . "\n";

exit;
```

---

## After Fixing

### Clear Browser Cache
```bash
# Chrome DevTools
Ctrl+Shift+Delete  # Or Cmd+Shift+Delete on Mac
# Clear: All Time, All cache types
```

### Try API Request Again

```javascript
// In browser console
fetch('https://supply-chain-backend-hwh6.onrender.com/api/mrfs', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    title: 'Test',
    category: 'Supplies',
    contractType: 'emerald',
    urgency: 'Medium',
    description: 'Test',
    quantity: '100',
    estimatedCost: '5000',
    justification: 'Test'
  })
}).then(r => r.json()).then(d => console.log(d));
```

---

## If Still Not Working

**Provide me with:**
1. Exact error message from `storage/logs/laravel.log`
2. Output of the Debugging Script above
3. Your actual frontend URL (what does `window.location.origin` show?)
4. The specific HTTP status code (200, 403, 422, 500, etc.)

Then I can pinpoint the exact issue!
