<?php

namespace App\Support;

use Illuminate\Http\Request;

class RequestLineItemParser
{
    /**
     * Decode items / line_items from JSON or multipart strings and merge into the request.
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
            $request->merge([
                'items' => $parsed,
                'line_items' => $parsed,
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
            'items.*.item_name' => 'required_without:items.*.itemName|nullable|string|max:255',
            'items.*.itemName' => 'required_without:items.*.item_name|nullable|string|max:255',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.budget_amount' => 'nullable|numeric|min:0',
            'items.*.budgetAmount' => 'nullable|numeric|min:0',
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
        ];
    }
}
