<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;

final class TripBookingRules
{
    public const SCOPE_OUT_OF_STATE_LOCAL = 'out_of_state_local';

    public const SCOPE_INTERNATIONAL = 'international';

    /** @deprecated Legacy scope — maps to out_of_state_local */
    public const SCOPE_WITHIN_STATE = 'within_state';

    /** @deprecated Legacy scope — maps to out_of_state_local */
    public const SCOPE_OUTSIDE_STATE = 'outside_state';

    public const LEAD_DAYS_OUT_OF_STATE_LOCAL = 7;

    public const LEAD_DAYS_INTERNATIONAL = 14;

    /**
     * @return list<string>
     */
    public static function allowedScopes(): array
    {
        return [self::SCOPE_OUT_OF_STATE_LOCAL, self::SCOPE_INTERNATIONAL];
    }

    public static function label(string $scope): string
    {
        return match (self::normalizeScope($scope) ?? $scope) {
            self::SCOPE_OUT_OF_STATE_LOCAL => 'Out of State (Local)',
            self::SCOPE_INTERNATIONAL => 'International (Out of Nigeria)',
            default => Str::title(str_replace('_', ' ', $scope)),
        };
    }

    public static function minimumLeadDays(string $scope): int
    {
        return match (self::normalizeScope($scope)) {
            self::SCOPE_INTERNATIONAL => self::LEAD_DAYS_INTERNATIONAL,
            self::SCOPE_OUT_OF_STATE_LOCAL => self::LEAD_DAYS_OUT_OF_STATE_LOCAL,
            default => self::LEAD_DAYS_OUT_OF_STATE_LOCAL,
        };
    }

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
        $normalized = self::normalizeScope($scope) ?? $scope;
        $departure = Carbon::parse($scheduledDepartureAt)->startOfDay();
        $minimum = self::earliestTripDate($normalized, $submittedAt);

        if ($departure->lt($minimum)) {
            return [
                'valid' => false,
                'message' => self::violationMessage($normalized),
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
        return match (self::normalizeScope($scope)) {
            self::SCOPE_INTERNATIONAL => 'International trips must be requested at least 14 days before the travel date. Please select a later date.',
            self::SCOPE_OUT_OF_STATE_LOCAL => 'Out of state (local) trips must be requested at least 7 days before the travel date. Please select a later date.',
            default => 'The selected trip date does not meet the minimum advance booking period for this trip type.',
        };
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
            'out_of_state_local', 'outofstatelocal', 'out_of_state', 'outsidestate', 'outside_state', 'outside', 'within_state', 'withinstate', 'within' => self::SCOPE_OUT_OF_STATE_LOCAL,
            'international', 'international_out_of_nigeria', 'out_of_nigeria' => self::SCOPE_INTERNATIONAL,
            default => in_array($normalized, self::allowedScopes(), true) ? $normalized : null,
        };
    }

    /**
     * @return list<array{value: string, label: string, minimumLeadDays: int, violationMessage: string}>
     */
    public static function scopesPayload(): array
    {
        return array_map(fn (string $scope) => [
            'value' => $scope,
            'label' => self::label($scope),
            'minimumLeadDays' => self::minimumLeadDays($scope),
            'violationMessage' => self::violationMessage($scope),
        ], self::allowedScopes());
    }
}
