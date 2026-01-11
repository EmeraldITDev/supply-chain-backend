<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Creating roles and permissions...');
        $this->command->info('');

        // Define all roles needed in the system
        $roles = [
            'admin' => 'System Administrator - Full access',
            'procurement_manager' => 'Procurement Manager - Full system visibility and PO generation',
            'supply_chain_director' => 'Supply Chain Director - Dashboard and approval permissions',
            'logistics_manager' => 'Logistics Manager - Logistics operations',
            'finance' => 'Finance - Financial approvals',
            'executive' => 'Executive - MRF approval and rejection rights',
            'chairman' => 'Chairman - High-value MRF and payment approvals',
            'vendor' => 'Vendor - Vendor portal access',
        ];

        // Create roles
        foreach ($roles as $roleName => $description) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            
            if ($role->wasRecentlyCreated) {
                $this->command->info("✓ Created role: {$roleName}");
            } else {
                $this->command->info("✓ Role exists: {$roleName}");
            }
        }

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('✓ ALL ROLES CREATED/VERIFIED SUCCESSFULLY!');
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('');
        
        // List all roles
        $this->command->info('Available Roles:');
        $this->command->info('─────────────────');
        foreach ($roles as $roleName => $description) {
            $this->command->info("  • {$roleName}");
            $this->command->info("    {$description}");
        }
        $this->command->info('');
    }
}
