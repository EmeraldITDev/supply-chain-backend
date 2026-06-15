<?php

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Database\Migrations\Migration;

/**
 * Fix users whose supply_chain_role was contaminated with HRIS-only values
 * (e.g. corporate_hr) when the shared users.role column was split.
 */
return new class extends Migration
{
    public function up(): void
    {
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
        // Data repair — no rollback.
    }
};
