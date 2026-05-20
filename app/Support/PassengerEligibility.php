<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PassengerEligibility
{
    /** Roles excluded from passenger/driver pickers (vendors + elevated power users). */
    public const EXCLUDED_ROLES = [
        'vendor',
        'admin',
        'executive',
        'chairman',
    ];

    public static function eligibleUsersQuery(): Builder
    {
        return User::query()
            ->whereNotIn('role', self::EXCLUDED_ROLES)
            ->where(function (Builder $q): void {
                $q->whereNull('vendor_id')->orWhere('vendor_id', 0);
            })
            ->orderBy('name');
    }

    public static function canCreateTripRequest(User $user): bool
    {
        if ($user->vendor_id || ($user->role === 'vendor')) {
            return false;
        }

        return !in_array($user->role, self::EXCLUDED_ROLES, true);
    }
}
