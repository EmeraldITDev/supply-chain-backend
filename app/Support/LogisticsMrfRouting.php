<?php

namespace App\Support;

use App\Models\MRF;
use App\Models\User;

/**
 * Emerald MRFs normally start at Executive review; logistics/fleet requests
 * should start at Supply Chain Director instead (same as non-Emerald routing).
 */
final class LogisticsMrfRouting
{
    public static function shouldStartAtSupplyChainDirectorForEmerald(
        ?User $requester,
        ?string $department,
        ?string $category,
        ?string $title,
        ?string $description
    ): bool {
        if ($requester) {
            $role = strtolower((string) ($requester->role ?? ''));
            if (in_array($role, ['logistics_manager', 'logistics_officer', 'logistics'], true)) {
                return true;
            }
        }

        $dept = strtolower((string) $department);
        foreach (['logistics', 'fleet', 'vehicle', 'transport'] as $needle) {
            if ($dept !== '' && str_contains($dept, $needle)) {
                return true;
            }
        }

        $blob = strtolower(trim(((string) $category).' '.(string) $title.' '.(string) $description));
        foreach (['fleet', 'vehicle', 'maintenance', 'logistics', 'transport'] as $kw) {
            if ($blob !== '' && str_contains($blob, $kw)) {
                return true;
            }
        }

        return false;
    }

    public static function mrfShouldStartAtSupplyChainDirector(MRF $mrf): bool
    {
        $requester = $mrf->relationLoaded('requester')
            ? $mrf->requester
            : $mrf->requester()->first();

        return self::shouldStartAtSupplyChainDirectorForEmerald(
            $requester,
            $mrf->department,
            $mrf->category,
            $mrf->title,
            $mrf->description
        );
    }
}
