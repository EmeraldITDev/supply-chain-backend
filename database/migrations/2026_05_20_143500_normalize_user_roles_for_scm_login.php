<?php

use App\Models\User;
use App\Support\UserRoleNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Users created with display labels (e.g. "Logistics Manager") were blocked at
 * login because AuthController only matched canonical role keys. Normalize
 * stored roles and ensure the logistics manager account can sign in.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNotNull('role')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $normalized = UserRoleNormalizer::normalize($user->role);
                    if ($normalized !== null && $normalized !== $user->role) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['role' => $normalized]);
                    }
                }
            });

        $joseph = User::query()
            ->where('email', 'joseph.akinyanmi@emeraldcfze.com')
            ->first();

        if ($joseph === null) {
            return;
        }

        DB::table('users')
            ->where('id', $joseph->id)
            ->update(['role' => 'logistics_manager']);

        try {
            if (! $joseph->hasRole('logistics_manager')) {
                $joseph->assignRole('logistics_manager');
            }
        } catch (\Throwable) {
            // Role row may not exist yet on some environments; seeder creates it.
        }
    }

    public function down(): void
    {
        // No rollback: canonical roles should remain after normalization.
    }
};
