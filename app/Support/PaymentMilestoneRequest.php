<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentMilestoneRequest
{
    /**
     * Decode payment_milestones / milestones from JSON or multipart strings.
     */
    public static function mergeIntoRequest(Request $request): void
    {
        $parsed = null;

        foreach (['payment_milestones', 'milestones'] as $key) {
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
                'payment_milestones' => $parsed,
                'milestones' => $parsed,
            ]);
        }
    }

    public static function provided(Request $request): bool
    {
        self::mergeIntoRequest($request);

        return $request->has('payment_milestones') || $request->has('milestones');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function resolve(Request $request): array
    {
        self::mergeIntoRequest($request);

        $raw = $request->input('payment_milestones') ?? $request->input('milestones');
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = self::normalizeRow($row, $index + 1);
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public static function validationRules(bool $required = false): array
    {
        $arrayRule = $required ? 'required|array|min:1' : 'nullable|array';

        return [
            'payment_milestones' => $arrayRule,
            'milestones' => 'nullable|array',
            'payment_milestones.*.label' => 'required_with:payment_milestones|string|max:255',
            'payment_milestones.*.percentage' => 'required_with:payment_milestones|numeric|min:0|max:100',
            'payment_milestones.*.trigger_condition' => 'nullable|string|max:50',
            'payment_milestones.*.triggerCondition' => 'nullable|string|max:50',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $milestones
     */
    public static function validatePercentages(array $milestones): void
    {
        if ($milestones === []) {
            return;
        }

        $total = 0.0;
        foreach ($milestones as $milestone) {
            $total += (float) ($milestone['percentage'] ?? 0);
        }

        if (abs($total - 100.0) > 0.001) {
            throw ValidationException::withMessages([
                'payment_milestones' => 'must sum to 100',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $milestones
     * @return list<array<string, mixed>>
     */
    public static function toScheduleMilestones(array $milestones): array
    {
        return array_map(static function (array $row, int $index) {
            return [
                'milestone_number' => (int) ($row['milestone_number'] ?? $index),
                'label' => $row['label'],
                'percentage' => (float) $row['percentage'],
                'trigger_condition' => $row['trigger_condition'] ?? 'on_advance',
            ];
        }, $milestones, array_keys($milestones));
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

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row, int $fallbackNumber): array
    {
        $label = trim((string) ($row['label'] ?? ''));
        $trigger = $row['trigger_condition'] ?? $row['triggerCondition'] ?? 'on_advance';

        return [
            'milestone_number' => (int) ($row['milestone_number'] ?? $row['milestoneNumber'] ?? $fallbackNumber),
            'label' => $label,
            'percentage' => (float) ($row['percentage'] ?? 0),
            'trigger_condition' => is_string($trigger) && trim($trigger) !== '' ? trim($trigger) : 'on_advance',
        ];
    }
}
