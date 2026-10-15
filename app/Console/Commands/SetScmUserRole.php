<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Set users.supply_chain_role for named staff accounts.
 * Does not touch hris_role. Uses assignRole (never syncRoles).
 */
class SetScmUserRole extends Command
{
    protected $signature = 'scm:set-user-role
                            {email : User email}
                            {role : Canonical SCM role (e.g. procurement_manager)}
                            {--dry-run : Show the change without saving}';

    protected $description = 'Set supply_chain_role for a user by email (SCM only; never writes hris_role)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $role = UserRoleNormalizer::normalize((string) $this->argument('role'));
        $dryRun = (bool) $this->option('dry-run');

        if ($role === null || ! UserRoleNormalizer::isValidScmRoleKey($role) || UserRoleNormalizer::isHrisOnlyRole($role)) {
            $this->error("Invalid SCM role: {$this->argument('role')}");

            return self::FAILURE;
        }

        $user = User::findByEmailCaseInsensitive($email);
        if ($user === null) {
            $this->error("User not found: {$email}");

            return self::FAILURE;
        }

        $before = $user->supply_chain_role;
        $this->line("User: {$user->email} (id={$user->id})");
        $this->line("  supply_chain_role: {$before} -> {$role}");
        $this->line('  hris_role (unchanged): '.($user->hris_role ?? 'null'));

        if ($dryRun) {
            $this->info('Dry run — no changes saved.');

            return self::SUCCESS;
        }

        $user->supply_chain_role = $role;
        $user->save();

        try {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            if (method_exists($user, 'assignRole') && ! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        } catch (\Throwable $e) {
            $this->warn('Spatie assign skipped: '.$e->getMessage());
        }

        $fresh = $user->fresh();
        $this->info("Updated: {$fresh->email} supply_chain_role={$fresh->supply_chain_role}");

        return self::SUCCESS;
    }
}
