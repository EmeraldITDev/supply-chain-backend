<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class FleetDocumentExpiryTier
{
    /**
     * Colour tiers aligned with VehicleDocumentsTab / DriverDocumentsDialog.
     *
     * @return array{
     *     tier: string,
     *     label: string,
     *     colour: string,
     *     alert_colour: string,
     *     alertColour: string,
     *     days_remaining: ?int,
     *     daysRemaining: ?int,
     *     expired: bool
     * }
     */
    public static function forExpiryDate(CarbonInterface|string|null $expiresAt): array
    {
        if ($expiresAt === null || $expiresAt === '') {
            return self::payload('valid', 'Valid', 'GREEN', null, false);
        }

        $expiry = $expiresAt instanceof CarbonInterface
            ? $expiresAt->copy()->startOfDay()
            : Carbon::parse($expiresAt)->startOfDay();

        $daysRemaining = (int) Carbon::today()->diffInDays($expiry, false);

        if ($daysRemaining <= 0) {
            return self::payload('expired', 'Expired', 'RED', $daysRemaining, true);
        }

        if ($daysRemaining <= 7) {
            return self::payload('critical', 'Critical', 'RED', $daysRemaining, false);
        }

        if ($daysRemaining <= 42) {
            return self::payload('expiring', 'Expiring', 'AMBER', $daysRemaining, false);
        }

        return self::payload('valid', 'Valid', 'GREEN', $daysRemaining, false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(
        string $tier,
        string $label,
        string $colour,
        ?int $daysRemaining,
        bool $expired,
    ): array {
        return [
            'tier' => $tier,
            'label' => $label,
            'colour' => $colour,
            'alert_colour' => $colour,
            'alertColour' => $colour,
            'days_remaining' => $daysRemaining,
            'daysRemaining' => $daysRemaining,
            'expired' => $expired,
        ];
    }
}
