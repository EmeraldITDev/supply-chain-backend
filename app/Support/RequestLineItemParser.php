<?php

namespace App\Support;

use Illuminate\Http\Request;

class RequestLineItemParser
{
    /**
     * Decode items / line_items from JSON or multipart strings, normalize each row
     * (ensuring both `item_name` and `itemName` are always present), and merge into the request.
     *
     * Normalization happens here — not only in resolve() — so that FormRequest validation
     * rules can rely on both snake_case and camelCase keys being present, regardless of
     * which naming convention the API client used.
     */
    public static function mergeIntoRequest(Request $request): void
    {
        $parsed = null;

        foreach (['line_items', 'items'] as $key) {
            if (!$request->has($key)) {
                continue;
            }

            $decoded = self::decodePayload($request->input($key));
            if ($decoded !== null) {
                $parsed = $decoded;
            }
        }

        if ($parsed !== null) {
            // Normalize every row so both `item_name` (snake) and `itemName` (camel)
            // are present. Without this, `required_without:items.*.itemName` in
            // validation rules does not work reliably because Laravel does not expand
            // wildcard `*` characters inside rule parameters.
            $normalized = array_map(
                static fn (mixed $row) => is_array($row) ? self::normalizeRow($row) : $row,
                $parsed,
            );

            $request->merge([
                'items' => $normalized,
                'line_items' => $normalized,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(Request $request): array
    {
        self::mergeIntoRequest($request);

        $raw = $request->input('items') ?? $request->input('line_items');
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized[] = self::normalizeRow($row);
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public static function validationRules(): array
    {
        return [
            'items' => 'nullable|array',
            'line_items' => 'nullable|array',
            // Both snake_case and camelCase names are accepted; mergeIntoRequest() normalises
            // them so both are always present before validation runs. Using `required_without`
            // with a wildcard parameter (e.g. `required_without:items.*.itemName`) is NOT
            // reliable in Laravel because the `*` in the parameter is never expanded — use
            // plain `nullable` instead and rely on normalizeRow() to supply a fallback value.
            'items.*.item_name' => 'nullable|string|max:255',
            'items.*.itemName' => 'nullable|string|max:255',
            // Accept numeric quantities (not integer-only) so fractional amounts such as
            // "2.5 months" or "0.5 units" are valid.  normalizeRow() casts to int when
            // persisting to the DB, so nothing downstream breaks.
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.budget_amount' => 'nullable|numeric|min:0',
            'items.*.budgetAmount' => 'nullable|numeric|min:0',
            // Per-line VAT % (PO line items). Blank = default rate, 0 = not taxed.
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.taxRate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * @param  mixed  $payload
     * @return array<int, array<string, mixed>>|null
     */
    private static function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (!is_string($payload)) {
            return null;
        }

        $trimmed = trim($payload);
        if ($trimmed === '' || str_contains($trimmed, '[object Object]')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return array_is_list($decoded) ? $decoded : array_values($decoded);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $row): array
    {
        $qty = (int) ($row['quantity'] ?? 1);
        $unitPrice = isset($row['unit_price']) ? (float) $row['unit_price']
            : (isset($row['unitPrice']) ? (float) $row['unitPrice'] : null);

        $budget = null;
        if (isset($row['budget_amount']) && $row['budget_amount'] !== '') {
            $budget = (float) $row['budget_amount'];
        } elseif (isset($row['budgetAmount']) && $row['budgetAmount'] !== '') {
            $budget = (float) $row['budgetAmount'];
        }

        $taxRate = PoTaxCalculator::lineRate($row);

        return [
            'item_name' => trim((string) ($row['item_name'] ?? $row['itemName'] ?? 'Item')),
            'itemName' => trim((string) ($row['item_name'] ?? $row['itemName'] ?? 'Item')),
            'description' => $row['description'] ?? null,
            'quantity' => $qty,
            'unit' => $row['unit'] ?? 'unit',
            'unit_price' => $unitPrice,
            'unitPrice' => $unitPrice,
            'budget_amount' => $budget,
            'budgetAmount' => $budget,
            'specifications' => $row['specifications'] ?? null,
            'tax_rate' => $taxRate,
            'taxRate' => $taxRate,
        ];
    }
}
