<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class InternationalTransportModeRequest
{
    public const MODE_FLIGHT = 'flight';

    public const MODE_ROAD = 'road';

    /**
     * @return list<string>
     */
    public static function allowedModes(): array
    {
        return [self::MODE_FLIGHT, self::MODE_ROAD];
    }

    /**
     * @return array<string, string>
     */
    public static function validationRules(): array
    {
        return [
            'international_transport_mode' => 'nullable|in:flight,road',
            'internationalTransportMode' => 'nullable|in:flight,road',
        ];
    }

    public static function mergeIntoRequest(Request $request): void
    {
        if ($request->has('internationalTransportMode') && ! $request->has('international_transport_mode')) {
            $request->merge(['international_transport_mode' => $request->input('internationalTransportMode')]);
        }
    }

    public static function resolve(Request $request, ?string $bookingScope): ?string
    {
        self::mergeIntoRequest($request);

        if (TripBookingRules::normalizeScope($bookingScope) !== TripBookingRules::SCOPE_INTERNATIONAL) {
            return null;
        }

        $mode = $request->input('international_transport_mode') ?? $request->input('internationalTransportMode');
        if ($mode === null || $mode === '') {
            return null;
        }

        return in_array($mode, self::allowedModes(), true) ? $mode : null;
    }

    public static function resolveForUpdate(Request $request, ?string $bookingScope, ?string $current): ?string
    {
        if (TripBookingRules::normalizeScope($bookingScope) !== TripBookingRules::SCOPE_INTERNATIONAL) {
            return null;
        }

        if ($request->has('international_transport_mode') || $request->has('internationalTransportMode')) {
            return self::resolve($request, $bookingScope);
        }

        return $current;
    }

    public static function assertIntegrity(Validator $validator, Request $request, ?string $bookingScope): void
    {
        $normalizedScope = TripBookingRules::normalizeScope($bookingScope);

        if ($normalizedScope === null || $normalizedScope === TripBookingRules::SCOPE_INTERNATIONAL) {
            return;
        }

        self::mergeIntoRequest($request);

        if ($request->filled('international_transport_mode') || $request->filled('internationalTransportMode')) {
            $validator->after(function ($v) {
                $v->errors()->add(
                    'international_transport_mode',
                    'Transport mode is only allowed for international trips.'
                );
            });
        }
    }
}
