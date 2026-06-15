<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Console\Command;

class RepairScmUserAccess extends Command
{
    protected $signature = 'scm:repair-user-access
                            {--email= : Repair a single user by email}
                            {--dry-run : Report users that would change without saving}';

    protected $description = 'Normalize SCM user roles and sync Spatie assignments so login access works';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $email = $this->option('email');

        $query = User::query()->with('employee')->orderBy('id');
        if ($email) {
            $query->where('email', $email);
        }

        $repaired = 0;
        $stillBlocked = 0;

        $query->chunkById(100, function ($users) use ($dryRun, &$repaired, &$stillBlocked): void {
            foreach ($users as $user) {
                $beforeRole = $user->scmRole();
                $hadAccess = UserRoleNormalizer::hasSupplyChainLoginAccess($user);

                if ($dryRun) {
                    $inferred = UserRoleNormalizer::inferCanonicalRoleFromProfile($user);
                    $normalized = UserRoleNormalizer::normalize($user->scmRole());
                    $wouldChange = ($inferred !== null && $inferred !== $user->scmRole())
                        || ($normalized !== null && $normalized !== $user->scmRole());

                    if (UserRoleNormalizer::isVendorAccount($user)) {
                        continue;
                    }

                    if (! $hadAccess) {
                        $this->line("BLOCKED: {$user->email} role={$user->scmRole()} dept={$user->department} inferred={$inferred}");
                        $stillBlocked++;
                    } elseif ($wouldChange) {
                        $this->line("WOULD FIX: {$user->email} {$user->scmRole()} -> ".($inferred ?? $normalized));
                        $repaired++;
                    }

                    continue;
                }

                if (UserRoleNormalizer::repairUserAccess($user)) {
                    $repaired++;
                    $this->info("Repaired: {$user->email} (was: {$beforeRole}, now: {$user->fresh()->scmRole()})");
                }

                $fresh = $user->fresh(['employee']);

                if (UserRoleNormalizer::isVendorAccount($fresh)) {
                    $this->line("Skipped (vendor portal only): {$fresh->email}");

                    continue;
                }

                if (! UserRoleNormalizer::hasSupplyChainLoginAccess($fresh)) {
                    $stillBlocked++;
                    $this->warn("Still blocked after repair: {$fresh->email} supply_chain_role={$fresh->scmRole()}");
                }
            }
        });

        $this->newLine();
        $this->info($dryRun
            ? "Dry run complete. Would repair: {$repaired}, still blocked: {$stillBlocked}"
            : "Repair complete. Updated: {$repaired}, still blocked: {$stillBlocked}");

        return $stillBlocked > 0 && $email ? self::FAILURE : self::SUCCESS;
    }
}
