# SCM Login Access — Investigation & Fix

## Problem

Internal users who previously signed in began receiving **422** with:

> You do not have access to the Supply Chain Management system. Please contact your administrator.

Credentials were often **correct**; the failure happened **after** password validation.

## Root causes

1. **`hasSupplyChainAccess` gate (since Jan 2026)**  
   Login requires SCM access beyond valid email/password. Users without a matching role were blocked.

2. **Non-canonical `users.role` values**  
   Roles stored as display labels (`Logistics Manager`, `Procurement`) did not match exact keys (`logistics_manager`). User management now normalizes on save, but **existing rows were never backfilled**.

3. **Spatie roles never synced for admin-created users**  
   `UserManagementController` set `users.role` but did not call `assignRole()`. Code that relied only on `hasAnyRole()` failed until `users.role` checks were added.

4. **Profile data ignored**  
   Access ignored `users.department`, `can_manage_users`, and `designated_requisition_creator`. Employee department matching was exact-only (e.g. `"Supply Chain Department"` failed).

5. **Vendors on main login**  
   Vendor accounts could pass the internal gate; they should use `POST /api/vendors/auth/login`.

## Code changes

| Area | Change |
|------|--------|
| `app/Support/UserRoleNormalizer.php` | Central access rules, role normalization, profile inference, Spatie sync |
| `app/Http/Controllers/Api/AuthController.php` | Vendor-specific error; uses normalizer |
| `app/Http/Controllers/Api/UserManagementController.php` | `syncSpatieRole()` on create/update |
| Migration `2026_05_20_143500_normalize_user_roles_for_scm_login` | Backfill all users |
| `php artisan scm:repair-user-access` | Manual repair / dry-run |

## Deploy on Render

```bash
# After deploy (or in Render Shell)
php artisan migrate --force
php artisan scm:repair-user-access
```

**Note:** Vendor accounts (`role=vendor`) are intentionally blocked from `POST /api/auth/login` — they must use `POST /api/vendors/auth/login`. The repair command will list them as "Skipped (vendor portal only)".

**Note:** If migration `2026_05_20_143500_normalize_user_roles_for_scm_login` failed once with a private-constant error, redeploy the fix and run `php artisan migrate --force` again (failed migrations are not recorded as run).

Dry-run first:

```bash
php artisan scm:repair-user-access --dry-run
```

Repair one user:

```bash
php artisan scm:repair-user-access --email=joseph.akinyanmi@emeraldcfze.com
```

## Verify

```bash
curl -s -X POST "https://YOUR-BACKEND/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"USER@emeraldcfze.com","password":"THEIR_PASSWORD"}'
```

Success returns `token` and `user`. Failure with access message means run repair for that email or set a canonical role in admin.

## Canonical roles (internal login)

`procurement_manager`, `supply_chain_director`, `logistics_manager`, `logistics_officer`, `finance`, `executive`, `chairman`, `hr_manager`, `employee`, `staff`, `admin`, and legacy aliases (`procurement`, `supply_chain`, `logistics`).
