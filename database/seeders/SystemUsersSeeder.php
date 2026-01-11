<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Hakeem Garba',
                'email' => 'hakeem.garba@emeraldcfze.com',
                'password' => 'Emerald@2026', // Change on first login
                'role' => 'procurement_manager',
                'spatie_role' => 'procurement_manager',
                'description' => 'Procurement Manager - Full system visibility and PO generation',
            ],
            [
                'name' => 'Viva Musa',
                'email' => 'viva.musa@emeraldcfze.com',
                'password' => 'Emerald@2026',
                'role' => 'supply_chain_director',
                'spatie_role' => 'supply_chain_director',
                'description' => 'Supply Chain Director - Dashboard visibility and approval permissions',
            ],
            [
                'name' => 'Bunmi Babajide',
                'email' => 'bunmi.babajide@emeraldcfze.com',
                'password' => 'Emerald@2026',
                'role' => 'executive',
                'spatie_role' => 'executive',
                'description' => 'Executive - MRF approval and rejection rights',
            ],
            [
                'name' => 'LAA',
                'email' => 'laa@emeraldcfze.com',
                'password' => 'Emerald@2026',
                'role' => 'chairman',
                'spatie_role' => 'chairman',
                'description' => 'Chairman - High-value MRF and payment approval rights',
            ],
        ];

        $this->command->info('Creating system users...');
        $this->command->info('');

        foreach ($users as $userData) {
            $existingUser = User::where('email', $userData['email'])->first();

            if ($existingUser) {
                $this->command->warn("⚠ User already exists: {$userData['email']}");
                
                // Update existing user
                $existingUser->update([
                    'name' => $userData['name'],
                    'password' => Hash::make($userData['password']),
                    'role' => $userData['role'],
                    'email_verified_at' => now(),
                    'must_change_password' => true, // Force password change on first login
                ]);

                // Ensure Spatie role is assigned
                if (!$existingUser->hasRole($userData['spatie_role'])) {
                    try {
                        $existingUser->assignRole($userData['spatie_role']);
                        $this->command->info("  ✓ Role '{$userData['spatie_role']}' assigned");
                    } catch (\Exception $e) {
                        $this->command->error("  ✗ Could not assign role: " . $e->getMessage());
                    }
                }

                $this->command->info("  ✓ Updated: {$userData['name']}");
            } else {
                // Create new user
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => Hash::make($userData['password']),
                    'role' => $userData['role'],
                    'email_verified_at' => now(),
                    'must_change_password' => true, // Force password change on first login
                ]);

                // Assign Spatie role
                try {
                    $user->assignRole($userData['spatie_role']);
                    $this->command->info("✓ Created: {$userData['name']} ({$userData['email']})");
                    $this->command->info("  Role: {$userData['role']}");
                } catch (\Exception $e) {
                    $this->command->error("  ✗ Could not assign role: " . $e->getMessage());
                }
            }

            $this->command->info('');
        }

        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('✓ ALL USERS CREATED/UPDATED SUCCESSFULLY!');
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('');
        $this->command->info('Login Credentials (all users):');
        $this->command->info('─────────────────────────────────');
        $this->command->info('');
        
        foreach ($users as $userData) {
            $this->command->info("📧 {$userData['email']}");
            $this->command->info("   Password: Emerald@2026");
            $this->command->info("   Role: {$userData['role']}");
            $this->command->info("   {$userData['description']}");
            $this->command->info('');
        }
        
        $this->command->info('⚠ IMPORTANT: All users must change password on first login');
        $this->command->info('');
    }
}
