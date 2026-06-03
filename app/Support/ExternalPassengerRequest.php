<?php

namespace App\Support;

use Illuminate\Http\Request;

class ExternalPassengerRequest
{
    public static function mergeIntoRequest(Request $request): void
    {
        $parsed = null;

        foreach (['external_passengers', 'externalPassengers'] as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $decoded = self::decodePayload($request->input($key));
            if ($decoded !== null) {
                $parsed = $decoded;
            }
        }

        if ($parsed !== null) {
            $request->merge([
                'external_passengers' => $parsed,
                'externalPassengers' => $parsed,
            ]);
        }
    }

    /**
     * @return list<array{name: string, email: string, phone: ?string}>
     */
    public static function resolve(Request $request): array
    {
        self::mergeIntoRequest($request);

        $raw = $request->input('external_passengers') ?? $request->input('externalPassengers');
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || $email === '') {
                continue;
            }
            $phone = isset($row['phone']) ? trim((string) $row['phone']) : null;
            $normalized[] = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public static function validationRules(): array
    {
        return [
            'external_passengers' => 'nullable|array',
            'externalPassengers' => 'nullable|array',
            'external_passengers.*.name' => 'required_with:external_passengers|string|max:255',
            'external_passengers.*.email' => 'required_with:external_passengers|email|max:255',
            'external_passengers.*.phone' => 'nullable|string|max:50',
        ];
    }

    /**
     * @param  mixed  $payload
     * @return list<array<string, mixed>>|null
     */
    private static function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return array_is_list($payload) ? $payload : array_values($payload);
        }

        if (! is_string($payload)) {
            return null;
        }

        $trimmed = trim($payload);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return array_is_list($decoded) ? $decoded : array_values($decoded);
    }
}
