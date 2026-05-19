# Fix Login Issue - Run Seeder on Render

## Problem
Production database on Render has no users. The `DatabaseSeeder` was not calling `RolesAndPermissionsSeeder` and `SystemUsersSeeder`, so system users were never created.

## Solution
Run the database seeder on Render to populate the database with:
- System roles (admin, procurement_manager, supply_chain_director, etc.)
- System users with proper access levels

## Steps to Fix (Choose One Method)

### Method 1: Using Render Shell (Recommended)
1. Go to your Render dashboard: https://dashboard.render.com
2. Select your Backend service
3. Click "Shell" tab at the top
4. Run this command:
   ```bash
   php artisan db:seed
   ```

### Method 2: Using Render Deployment Hook
The seeder will automatically run on next deployment if you deploy these changes (database/seeders/DatabaseSeeder.php was updated).

### Method 3: Using SSH (if available)
Connect to Render and run:
```bash
cd /app && php artisan db:seed
```

## What Gets Created

### Roles
- procurement_manager
- supply_chain_director  
- executive
- chairman
- logistics_manager
- finance
- vendor
- admin

### System Users
| Email | Password | Role | Access Level |
|-------|----------|------|--------------|
| hakeem.garba@emeraldcfze.com | Emerald@2026 | procurement_manager | High (PO generation) |
| viva.musa@emeraldcfze.com | Emerald@2026 | supply_chain_director | High (Approvals) |
| bunmi.babajide@emeraldcfze.com | Emerald@2026 | executive | Medium (MRF approval) |
| laa@emeraldcfze.com | Emerald@2026 | chairman | High (Financial) |
| staff@emeraldcfze.com | Staff@2026 | employee | Low (Can create MRFs) |
| executive@emeraldcfze.com | Executive@2026 | executive | Medium |
| procurement@emeraldcfze.com | Procurement@2026 | procurement | High |
| supplychain@emeraldcfze.com | SupplyChain@2026 | supply_chain_director | High |
| finance@emeraldcfze.com | Finance@2026 | finance | Medium |

## After Seeding

1. All users MUST change password on first login (required by system)
2. They will have full access to the Supply Chain Management system
3. Role-based features will work correctly
4. Test all user accounts to verify access

## Verification

After running the seeder, test login with any of the above credentials. All users should now be able to login successfully.

## Changes Made
- `database/seeders/DatabaseSeeder.php` - Updated to call:
  - RolesAndPermissionsSeeder (creates roles)
  - SystemUsersSeeder (creates system users)
