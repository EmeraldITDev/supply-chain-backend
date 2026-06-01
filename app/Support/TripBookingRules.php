<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

final class TripBookingRules
{
    public const SCOPE_WITHIN_STATE = 'within_state';

    public const SCOPE_OUTSIDE_STATE = 'outside_state';

    public const LEAD_DAYS_WITHIN_STATE = 2;

    public const LEAD_DAYS_OUTSIDE_STATE = 14;

    /**
     * @return list<string>
     */
    public static function allowedScopes(): array
    {
        return [self::SCOPE_WITHIN_STATE, self::SCOPE_OUTSIDE_STATE];
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            self::SCOPE_WITHIN_STATE => 'Within State',
            self::SCOPE_OUTSIDE_STATE => 'Outside State',
            default => Str::title(str_replace('_', ' ', $scope)),
        };
    }

    public static function minimumLeadDays(string $scope): int
    {
        return $scope === self::SCOPE_OUTSIDE_STATE
            ? self::LEAD_DAYS_OUTSIDE_STATE
            : self::LEAD_DAYS_WITHIN_STATE;
    }

    /**
     * Earliest allowed trip date (start of day) for a scope, based on submission time.
     */
    public static function earliestTripDate(string $scope, ?Carbon $submittedAt = null): Carbon
    {
        $base = ($submittedAt ?? now())->copy()->startOfDay();

        return $base->addDays(self::minimumLeadDays($scope));
    }

    /**
     * @return array{valid: bool, message: ?string, minimum_trip_date: string}
     */
    public static function validateDeparture(string $scope, Carbon|string $scheduledDepartureAt, ?Carbon $submittedAt = null): array
    {
        $departure = Carbon::parse($scheduledDepartureAt)->startOfDay();
        $minimum = self::earliestTripDate($scope, $submittedAt);

        if ($departure->lt($minimum)) {
            return [
                'valid' => false,
                'message' => self::violationMessage($scope),
                'minimum_trip_date' => $minimum->toDateString(),
            ];
        }

        return [
            'valid' => true,
            'message' => null,
            'minimum_trip_date' => $minimum->toDateString(),
        ];
    }

    public static function violationMessage(string $scope): string
    {
        if ($scope === self::SCOPE_OUTSIDE_STATE) {
            return 'Outside state trips must be requested at least 2 weeks (14 days) in advance. Please select a later trip date or submit sooner.';
        }

        return 'Within state trips must be requested at least 2 days in advance. Please select a later trip date or submit sooner.';
    }

    /**
     * Normalize API input (camelCase labels or snake values).
     */
    public static function normalizeScope(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim(str_replace([' ', '-'], '_', (string) $value)));

        return match ($normalized) {
            'within_state', 'withinstate', 'within' => self::SCOPE_WITHIN_STATE,
            'outside_state', 'outsidestate', 'outside' => self::SCOPE_OUTSIDE_STATE,
            default => in_array($normalized, self::allowedScopes(), true) ? $normalized : null,
        };
    }
}
