<?php

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Repair SCM login for users blocked by strict role matching:
 * - Normalize display labels on users.role (e.g. "Logistics Manager")
 * - Infer roles from employee / department profile when missing
 * - Sync Spatie roles from canonical users.role
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (UserRoleNormalizer::SPATIE_SYNC_ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'hr_manager', 'guard_name' => 'web']);

        User::query()
            ->with('employee')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    UserRoleNormalizer::repairUserAccess($user);
                }
            });
    }

    public function down(): void
    {
        // No rollback: canonical roles should remain after normalization.
    }
};
