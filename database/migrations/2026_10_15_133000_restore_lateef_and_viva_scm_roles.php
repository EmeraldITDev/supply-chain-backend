<?php

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Restore SCM roles after HRIS Spatie sync made the UI show Employee.
 * Only writes supply_chain_role + assignRole. Never touches hris_role.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const ASSIGNMENTS = [
        'lateef.olanrewaju@emeraldcfze.com' => 'procurement_manager',
        'viva.musa@emeraldcfze.com' => 'supply_chain_director',
    ];

    public function up(): void
    {
        foreach (self::ASSIGNMENTS as $email => $role) {
            $user = User::findByEmailCaseInsensitive($email);
            if ($user === null) {
                continue;
            }

            $canonical = UserRoleNormalizer::normalize($role) ?? $role;
            if ($user->supply_chain_role !== $canonical) {
                $user->supply_chain_role = $canonical;
                $user->save();
            }

            try {
                Role::firstOrCreate(['name' => $canonical, 'guard_name' => 'web']);
                if (method_exists($user, 'assignRole') && ! $user->hasRole($canonical)) {
                    $user->assignRole($canonical);
                }
            } catch (\Throwable) {
                // Spatie optional — supply_chain_role is the SCM source of truth.
            }
        }
    }

    public function down(): void
    {
        // No rollback — roles must remain after restore.
    }
};
