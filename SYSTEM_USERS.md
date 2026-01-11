# System Users Setup

## Created Users

All users have the temporary password: **`Emerald@2026`**
⚠️ **Users MUST change password on first login**

---

### 1. Procurement Manager
- **Name:** Hakeem Garba
- **Email:** `hakeem.garba@emeraldcfze.com`
- **Role:** `procurement_manager`
- **Access:**
  - Full system visibility
  - Purchase Order (PO) generation rights
  - Vendor management
  - Dashboard access

---

### 2. Supply Chain Director
- **Name:** Viva Musa
- **Email:** `viva.musa@emeraldcfze.com`
- **Role:** `supply_chain_director`
- **Access:**
  - Dashboard visibility
  - Approval permissions
  - Vendor oversight
  - RFQ management

---

### 3. Executive
- **Name:** Bunmi Babajide
- **Email:** `bunmi.babajide@emeraldcfze.com`
- **Role:** `executive`
- **Access:**
  - MRF approval rights
  - MRF rejection rights
  - Dashboard visibility
  - Executive-level oversight

---

### 4. Chairman
- **Name:** LAA
- **Email:** `laa@emeraldcfze.com`
- **Role:** `chairman`
- **Access:**
  - High-value MRF approval rights
  - Payment approval rights
  - Full system visibility
  - Executive dashboard access

---

## How to Deploy Users

### Option 1: Via Render Shell (Immediate)

1. Go to [Render Dashboard](https://dashboard.render.com)
2. Select your backend service: `supply-chain-backend`
3. Click **"Shell"** tab
4. Run:
```bash
php artisan db:seed --class=SystemUsersSeeder --force
```

### Option 2: Via Git Deployment

1. Commit and push the seeder:
```bash
git add database/seeders/SystemUsersSeeder.php SYSTEM_USERS.md
git commit -m "Add system users seeder"
git push origin main
```

2. After deployment completes, run in Render Shell:
```bash
php artisan db:seed --class=SystemUsersSeeder --force
```

---

## Login URL

**Production:** `https://supply-chain-backend-hwh6.onrender.com/api/auth/login`

---

## Test Login (Example with Hakeem)

```bash
curl -X POST https://supply-chain-backend-hwh6.onrender.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "hakeem.garba@emeraldcfze.com",
    "password": "Emerald@2026"
  }'
```

**Expected Response:**
```json
{
  "id": 1,
  "email": "hakeem.garba@emeraldcfze.com",
  "name": "Hakeem Garba",
  "role": "procurement_manager",
  "token": "...",
  "expiresAt": "...",
  "requiresPasswordChange": true
}
```

---

## Password Change Flow

When `requiresPasswordChange: true`, users must:
1. Login with temporary password: `Emerald@2026`
2. Call `POST /api/auth/change-password` with:
```json
{
  "currentPassword": "Emerald@2026",
  "newPassword": "NewSecurePassword123!",
  "newPassword_confirmation": "NewSecurePassword123!"
}
```

---

## Permissions Summary

| Role | Dashboard | Vendor Mgmt | MRF Approval | PO Generation | High-Value Approval |
|------|-----------|-------------|--------------|---------------|---------------------|
| Procurement Manager | ✅ Full | ✅ Full | ✅ | ✅ | - |
| Supply Chain Director | ✅ Full | ✅ View | ✅ | - | - |
| Executive | ✅ View | ✅ View | ✅ | - | - |
| Chairman | ✅ Full | ✅ View | ✅ | - | ✅ |

---

## Troubleshooting

### User can't login (422 error)
- Ensure seeder was run on production database
- Verify email is correct
- Check password: `Emerald@2026` (case-sensitive)

### User doesn't have access to features
- Check role assignment in database
- Verify Spatie role is assigned (run seeder again)

### Reset user password
```bash
php artisan tinker
```
```php
$user = App\Models\User::where('email', 'hakeem.garba@emeraldcfze.com')->first();
$user->password = Hash::make('NewPassword123');
$user->must_change_password = true;
$user->save();
echo "Password reset for {$user->email}\n";
exit
```

---

## Security Notes

⚠️ **Important:**
1. All users share the same temporary password initially
2. Users MUST change password on first login
3. Passwords are hashed using bcrypt
4. Tokens expire after 30 days (remember me) or 1 day (regular session)
5. Never share passwords via email or chat

---

**Created:** January 2026
**Last Updated:** January 2026
