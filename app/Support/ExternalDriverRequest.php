<?php

namespace App\Support;

use Illuminate\Http\Request;

class ExternalDriverRequest
{
    public static function mergeIntoRequest(Request $request): void
    {
        $parsed = null;

        foreach (['external_driver', 'externalDriver'] as $key) {
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
                'external_driver' => $parsed,
                'externalDriver' => $parsed,
            ]);
        }
    }

    /**
     * @return array{name: string, phone: string, license_number: ?string}|null
     */
    public static function resolve(Request $request): ?array
    {
        self::mergeIntoRequest($request);

        $raw = $request->input('external_driver') ?? $request->input('externalDriver');
        if (! is_array($raw)) {
            return null;
        }

        $name = trim((string) ($raw['name'] ?? ''));
        $phone = trim((string) ($raw['phone'] ?? ''));
        if ($name === '' && $phone === '') {
            return null;
        }

        $license = isset($raw['license_number']) || isset($raw['licenseNumber'])
            ? trim((string) ($raw['license_number'] ?? $raw['licenseNumber'] ?? ''))
            : '';

        return [
            'name' => $name,
            'phone' => $phone,
            'license_number' => $license !== '' ? $license : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function validationRules(): array
    {
        return [
            'external_driver' => 'nullable|array',
            'externalDriver' => 'nullable|array',
            'external_driver.name' => 'required_with:external_driver|nullable|string|max:255',
            'external_driver.phone' => 'required_with:external_driver|nullable|string|max:50',
            'external_driver.license_number' => 'nullable|string|max:100',
            'externalDriver.name' => 'required_with:externalDriver|nullable|string|max:255',
            'externalDriver.phone' => 'required_with:externalDriver|nullable|string|max:50',
            'externalDriver.licenseNumber' => 'nullable|string|max:100',
        ];
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>|null
     */
    private static function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
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

        return $decoded;
    }
}
