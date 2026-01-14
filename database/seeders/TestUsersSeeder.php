<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Regular Staff (Requester)
        User::updateOrCreate(
            ['email' => 'staff@emeraldcfze.com'],
            [
                'name' => 'Test Staff',
                'password' => Hash::make('Staff@2026'),
                'role' => 'employee',
                'department' => 'Operations',
                'is_admin' => false,
                'can_manage_users' => false,
            ]
        );

        // Executive
        User::updateOrCreate(
            ['email' => 'executive@emeraldcfze.com'],
            [
                'name' => 'Test Executive',
                'password' => Hash::make('Executive@2026'),
                'role' => 'executive',
                'department' => 'Executive',
                'is_admin' => true,
                'can_manage_users' => true,
            ]
        );

        // Procurement Manager
        User::updateOrCreate(
            ['email' => 'procurement@emeraldcfze.com'],
            [
                'name' => 'Test Procurement Manager',
                'password' => Hash::make('Procurement@2026'),
                'role' => 'procurement',
                'department' => 'Procurement',
                'is_admin' => true,
                'can_manage_users' => true,
            ]
        );

        // Supply Chain Director
        User::updateOrCreate(
            ['email' => 'supplychain@emeraldcfze.com'],
            [
                'name' => 'Test Supply Chain Director',
                'password' => Hash::make('SupplyChain@2026'),
                'role' => 'supply_chain_director',
                'department' => 'Supply Chain',
                'is_admin' => true,
                'can_manage_users' => true,
            ]
        );

        // Finance Officer
        User::updateOrCreate(
            ['email' => 'finance@emeraldcfze.com'],
            [
                'name' => 'Test Finance Officer',
                'password' => Hash::make('Finance@2026'),
                'role' => 'finance',
                'department' => 'Finance',
                'is_admin' => false,
                'can_manage_users' => false,
            ]
        );

        $this->command->info('Test users created successfully!');
        $this->command->info('Regular Staff: staff@emeraldcfze.com / Staff@2026');
        $this->command->info('Executive: executive@emeraldcfze.com / Executive@2026');
        $this->command->info('Procurement Manager: procurement@emeraldcfze.com / Procurement@2026');
        $this->command->info('Supply Chain Director: supplychain@emeraldcfze.com / SupplyChain@2026');
        $this->command->info('Finance Officer: finance@emeraldcfze.com / Finance@2026');
    }
}
