<?php

namespace App\Support;

/**
 * Single source of truth for PO tax (VAT) maths.
 *
 * Two modes:
 * - per_line: every line carries its own tax_rate. NULL / blank means the default
 *   VAT rate (7.5%); 0 means the line is not taxed. Line amounts are VAT-inclusive.
 * - legacy (tax_mode NULL): one global MRF tax_rate / tax_amount applied to the
 *   whole subtotal. A stored tax_amount > 0 wins (it is what was persisted when the
 *   PO was generated); otherwise subtotal × rate. Line amounts are pre-VAT.
 *
 * Optional PO-level discount (percentage of subtotal, or a fixed amount) is taken
 * BEFORE VAT: it is shared across lines in proportion to their value and each line's
 * VAT is charged on its discounted value. Line display amounts are not discounted.
 *
 * Per-line VAT is totalled per rate band and rounded once per band, so totals don't
 * drift by a kobo from per-line rounding.
 */
final class PoTaxCalculator
{
    public const MODE_PER_LINE = 'per_line';

    public const DEFAULT_RATE = 7.5;

    public const DISCOUNT_PERCENTAGE = 'percentage';

    public const DISCOUNT_AMOUNT = 'amount';

    /**
     * Normalise a discount type/value pair. Returns null when there is no discount.
     *
     * @return array{type: string, value: float}|null
     */
    public static function discount(mixed $type, mixed $value): ?array
    {
        $type = is_string($type) ? strtolower(trim($type)) : '';
        if (! in_array($type, [self::DISCOUNT_PERCENTAGE, self::DISCOUNT_AMOUNT], true)) {
            return null;
        }
        $value = self::parseRate($value);
        if ($value === null || $value <= 0) {
            return null;
        }

        return ['type' => $type, 'value' => $value];
    }

    /**
     * Discount stored on an MRF / PO (discount_type + discount_value).
     *
     * @return array{type: string, value: float}|null
     */
    public static function discountFromModel(?object $mrf): ?array
    {
        return $mrf ? self::discount($mrf->discount_type ?? null, $mrf->discount_value ?? null) : null;
    }

    /**
     * Discount money amount for a subtotal (capped at the subtotal).
     *
     * @param  array{type: string, value: float}|null  $discount
     */
    public static function discountAmount(float $subtotal, ?array $discount): float
    {
        if ($discount === null || $subtotal <= 0) {
            return 0.0;
        }
        $amount = $discount['type'] === self::DISCOUNT_PERCENTAGE
            ? $subtotal * min(100.0, $discount['value']) / 100
            : $discount['value'];

        return round(max(0.0, min($subtotal, $amount)), 2);
    }

    /**
     * Human note for the PO document, e.g. "Discount: 5% of subtotal".
     *
     * @param  array{type: string, value: float}|null  $discount
     */
    public static function discountNote(?array $discount, string $formattedAmount): ?string
    {
        if ($discount === null) {
            return null;
        }

        return $discount['type'] === self::DISCOUNT_PERCENTAGE
            ? 'Discount: '.self::label($discount['value']).' of subtotal (before VAT)'
            : 'Discount: '.$formattedAmount.' fixed amount (before VAT)';
    }

    public static function isPerLine(?string $taxMode): bool
    {
        return $taxMode === self::MODE_PER_LINE;
    }

    /**
     * Normalise an incoming tax_mode value (anything other than per_line → null).
     */
    public static function normaliseMode(mixed $mode): ?string
    {
        return is_string($mode) && strtolower(trim($mode)) === self::MODE_PER_LINE
            ? self::MODE_PER_LINE
            : null;
    }

    /**
     * Parse a line's tax rate. Returns null for blank / non-numeric input.
     */
    public static function parseRate(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * Read a line's rate from either snake_case or camelCase keys.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineRate(array $line): ?float
    {
        return self::parseRate($line['tax_rate'] ?? $line['taxRate'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $lines  each with quantity/qty and unit_price/unitPrice (and optional tax_rate)
     * @param  array{type: string, value: float}|null  $discount  see {@see discount()}
     * @return array{
     *     mode: string|null,
     *     subtotal: float,
     *     discount: float,
     *     discount_type: string|null,
     *     discount_value: float|null,
     *     tax: float,
     *     total: float,
     *     lines: list<array{quantity: float, unit_price: float, net: float, rate: float, discount: float, tax: float, amount: float, tax_label: string}>
     * }
     */
    public static function forLines(
        array $lines,
        ?string $taxMode,
        mixed $legacyRate = null,
        mixed $legacyAmount = null,
        ?array $discount = null,
    ): array {
        $perLine = self::isPerLine($taxMode);
        $globalRate = self::parseRate($legacyRate) ?? 0.0;

        $rows = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $qty = (float) ($line['quantity'] ?? $line['qty'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? $line['unitPrice'] ?? $line['rate'] ?? 0);
            $net = round($qty * $unit, 2);
            $subtotal += $net;
            $rows[] = [
                'quantity' => $qty,
                'unit_price' => $unit,
                'net' => $net,
                'rate' => $perLine ? (self::lineRate($line) ?? self::DEFAULT_RATE) : $globalRate,
            ];
        }
        $subtotal = round($subtotal, 2);
        $discountTotal = self::discountAmount($subtotal, $discount);

        // Share the discount across lines by value; the last priced line takes the
        // rounding remainder so the shares add up exactly.
        $lastPriced = null;
        foreach ($rows as $i => $row) {
            if ($row['net'] > 0) {
                $lastPriced = $i;
            }
        }
        $allocated = 0.0;
        $out = [];
        /** @var array<string, float> $bandBase taxable (discounted) value per VAT rate */
        $bandBase = [];
        foreach ($rows as $i => $row) {
            $share = 0.0;
            if ($discountTotal > 0 && $row['net'] > 0) {
                $share = $i === $lastPriced
                    ? round($discountTotal - $allocated, 2)
                    : round($discountTotal * $row['net'] / $subtotal, 2);
                $allocated += $share;
            }
            $fullTax = $perLine ? round($row['net'] * $row['rate'] / 100, 2) : 0.0;
            $tax = $perLine ? round(($row['net'] - $share) * $row['rate'] / 100, 2) : 0.0;
            if ($perLine) {
                $band = (string) $row['rate'];
                $bandBase[$band] = ($bandBase[$band] ?? 0.0) + ($row['net'] - $share);
            }

            $out[] = $row + [
                'discount' => $share,
                'tax' => $tax,
                // Display amount: qty × rate + full line VAT (not discounted).
                'amount' => $perLine ? round($row['net'] + $fullTax, 2) : $row['net'],
                'tax_label' => self::label($row['rate']),
            ];
        }

        $taxSum = 0.0;
        foreach ($bandBase as $rate => $base) {
            $taxSum += round(round($base, 2) * (float) $rate / 100, 2);
        }

        if (! $perLine) {
            $stored = (float) ($legacyAmount ?? 0);
            $taxSum = $stored > 0 ? $stored : ($subtotal - $discountTotal) * $globalRate / 100;
        }

        $taxSum = round($taxSum, 2);

        return [
            'mode' => $perLine ? self::MODE_PER_LINE : null,
            'subtotal' => $subtotal,
            'discount' => $discountTotal,
            'discount_type' => $discountTotal > 0 ? $discount['type'] : null,
            'discount_value' => $discountTotal > 0 ? $discount['value'] : null,
            'tax' => $taxSum,
            'total' => round($subtotal - $discountTotal + $taxSum, 2),
            'lines' => $out,
        ];
    }

    public static function label(float $rate): string
    {
        if ($rate <= 0) {
            return '0%';
        }

        $s = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return $s.'%';
    }
}
