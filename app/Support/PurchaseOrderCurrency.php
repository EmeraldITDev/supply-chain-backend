<?php

namespace App\Support;

/**
 * Normalizes PO / MRF currency codes accepted by the API.
 */
final class PurchaseOrderCurrency
{
    public const DEFAULT = 'NGN';

    /** @var list<string> */
    public const SUPPORTED = ['NGN', 'USD'];

    public const VALIDATION_RULE = 'nullable|string|in:NGN,USD';

    public static function normalize(mixed $value): string
    {
        $code = strtoupper(trim((string) ($value ?? '')));

        return in_array($code, self::SUPPORTED, true) ? $code : self::DEFAULT;
    }

    /**
     * Resolve currency from request body (currency key only).
     */
    public static function fromRequest(\Illuminate\Http\Request $request, ?string $fallback = null): ?string
    {
        if (! $request->filled('currency')) {
            return $fallback;
        }

        return self::normalize($request->input('currency'));
    }
}
