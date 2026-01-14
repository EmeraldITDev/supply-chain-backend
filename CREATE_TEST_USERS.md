# Test User Accounts - Creation Guide

## User Credentials

### **Regular Staff (Requester)**
- **Email:** staff@emeraldcfze.com
- **Password:** Staff@2026
- **Role:** employee
- **Department:** Operations
- **Admin:** false
- **Can Manage Users:** false

### **Executive**
- **Email:** executive@emeraldcfze.com
- **Password:** Executive@2026
- **Role:** executive
- **Department:** Executive
- **Admin:** true
- **Can Manage Users:** true

### **Procurement Manager**
- **Email:** procurement@emeraldcfze.com
- **Password:** Procurement@2026
- **Role:** procurement
- **Department:** Procurement
- **Admin:** true
- **Can Manage Users:** true

### **Supply Chain Director**
- **Email:** supplychain@emeraldcfze.com
- **Password:** SupplyChain@2026
- **Role:** supply_chain_director
- **Department:** Supply Chain
- **Admin:** true
- **Can Manage Users:** true

### **Finance Officer**
- **Email:** finance@emeraldcfze.com
- **Password:** Finance@2026
- **Role:** finance
- **Department:** Finance
- **Admin:** false
- **Can Manage Users:** false

---

## Creation Methods

### **Option 1: Using Laravel Tinker**

```bash
cd "/Users/asukuonukaba/Desktop/SCM Backend/supply-chain-backend"
php artisan tinker
```

Then run:
```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Regular Staff
User::create([
    'name' => 'Test Staff',
    'email' => 'staff@emeraldcfze.com',
    'password' => Hash::make('Staff@2026'),
    'role' => 'employee',
    'department' => 'Operations',
    'is_admin' => false,
    'can_manage_users' => false,
]);

// Executive
User::create([
    'name' => 'Test Executive',
    'email' => 'executive@emeraldcfze.com',
    'password' => Hash::make('Executive@2026'),
    'role' => 'executive',
    'department' => 'Executive',
    'is_admin' => true,
    'can_manage_users' => true,
]);

// Procurement Manager
User::create([
    'name' => 'Test Procurement Manager',
    'email' => 'procurement@emeraldcfze.com',
    'password' => Hash::make('Procurement@2026'),
    'role' => 'procurement',
    'department' => 'Procurement',
    'is_admin' => true,
    'can_manage_users' => true,
]);

// Supply Chain Director
User::create([
    'name' => 'Test Supply Chain Director',
    'email' => 'supplychain@emeraldcfze.com',
    'password' => Hash::make('SupplyChain@2026'),
    'role' => 'supply_chain_director',
    'department' => 'Supply Chain',
    'is_admin' => true,
    'can_manage_users' => true,
]);

// Finance Officer
User::create([
    'name' => 'Test Finance Officer',
    'email' => 'finance@emeraldcfze.com',
    'password' => Hash::make('Finance@2026'),
    'role' => 'finance',
    'department' => 'Finance',
    'is_admin' => false,
    'can_manage_users' => false,
]);
```

### **Option 2: Using SQL (PostgreSQL)**

```sql
-- Regular Staff
INSERT INTO users (name, email, password, role, department, is_admin, can_manage_users, created_at, updated_at)
VALUES (
    'Test Staff',
    'staff@emeraldcfze.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Staff@2026
    'employee',
    'Operations',
    false,
    false,
    NOW(),
    NOW()
);

-- Executive
INSERT INTO users (name, email, password, role, department, is_admin, can_manage_users, created_at, updated_at)
VALUES (
    'Test Executive',
    'executive@emeraldcfze.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Executive@2026
    'executive',
    'Executive',
    true,
    true,
    NOW(),
    NOW()
);

-- Procurement Manager
INSERT INTO users (name, email, password, role, department, is_admin, can_manage_users, created_at, updated_at)
VALUES (
    'Test Procurement Manager',
    'procurement@emeraldcfze.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Procurement@2026
    'procurement',
    'Procurement',
    true,
    true,
    NOW(),
    NOW()
);

-- Supply Chain Director
INSERT INTO users (name, email, password, role, department, is_admin, can_manage_users, created_at, updated_at)
VALUES (
    'Test Supply Chain Director',
    'supplychain@emeraldcfze.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- SupplyChain@2026
    'supply_chain_director',
    'Supply Chain',
    true,
    true,
    NOW(),
    NOW()
);

-- Finance Officer
INSERT INTO users (name, email, password, role, department, is_admin, can_manage_users, created_at, updated_at)
VALUES (
    'Test Finance Officer',
    'finance@emeraldcfze.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Finance@2026
    'finance',
    'Finance',
    false,
    false,
    NOW(),
    NOW()
);
```

**Note:** The password hash above is a placeholder. You'll need to generate actual bcrypt hashes for each password.

### **Option 3: Using Seeder (Recommended)**

Create a seeder file:
```bash
php artisan make:seeder TestUsersSeeder
```

Then populate it with the user creation code from Option 1, and run:
```bash
php artisan db:seed --class=TestUsersSeeder
```

---

## Verification

After creating users, verify they exist:
```sql
SELECT id, name, email, role, is_admin, can_manage_users FROM users WHERE email LIKE '%@emeraldcfze.com';
```

Or in Tinker:
```php
User::where('email', 'like', '%@emeraldcfze.com')->get(['id', 'name', 'email', 'role', 'is_admin', 'can_manage_users']);
```

---

## Security Note

**IMPORTANT:** These are test credentials. Change all passwords before deploying to production!
