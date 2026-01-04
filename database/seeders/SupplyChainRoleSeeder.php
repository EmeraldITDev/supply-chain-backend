<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class SupplyChainRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Supply chain specific roles
        $roles = [
            'procurement_manager',
            'supply_chain_director',
            'logistics_manager',
        ];

        foreach ($roles as $roleName) {
            // Check if role already exists (might be created by HRIS)
            $role = Role::firstOrCreate(['name' => $roleName]);
            $this->command->info("Role '{$roleName}' ensured.");
        }

        // Note: 'finance', 'executive', and 'chairman' roles may already exist from HRIS
        // We don't need to create them here, just ensure they exist
        $existingRoles = ['finance', 'executive', 'chairman'];
        foreach ($existingRoles as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            if ($role->wasRecentlyCreated) {
                $this->command->info("Role '{$roleName}' created.");
            } else {
                $this->command->info("Role '{$roleName}' already exists.");
            }
        }
    }
}

